<?php
namespace Dosya\Backup\Jobs;

use Dosya\Backup\Archive\Manifest;
use Dosya\Backup\Support\Fs;

final class LocalStore
{
    public const SNAPSHOT_LABEL = 'pre-restore';
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
    }

    public static function chooseDir(string $abspath, string $contentDir, string $docRoot, string $suffix, callable $isWritable): string
    {
        $parent = dirname(rtrim($abspath, '/'));
        $root = rtrim($docRoot, '/');
        $outside = $root !== '' && strpos($parent . '/', $root . '/') !== 0;
        if ($outside && $isWritable($parent)) {
            return $parent . '/dosya-backups/' . $suffix;
        }
        return rtrim($contentDir, '/') . '/dosya-backup-local/' . $suffix;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function ensure(): void
    {
        Fs::ensureDir($this->dir);
        $deny = [
            'index.php' => "<?php\n// Silence is golden.\n",
            '.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        ];
        foreach ([$this->dir, dirname($this->dir)] as $d) {
            if (!in_array(basename($d), ['dosya-backup-local', 'dosya-backups'], true) && $d !== $this->dir) {
                continue;
            }
            foreach ($deny as $name => $body) {
                if (!is_file($d . '/' . $name)) {
                    file_put_contents($d . '/' . $name, $body);
                }
            }
        }
    }

    public function backupDir(string $id): string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid backup id');
        }
        return $this->dir . '/' . $id;
    }

    public function volumePath(string $id, int $n): string { return sprintf('%s/volumes/v%04d.dosya', $this->backupDir($id), $n); }
    public function manifestPath(string $id): string { return $this->backupDir($id) . '/manifest.json.gz'; }
    public function publicManifestPath(string $id): string { return $this->backupDir($id) . '/manifest.public.json'; }

    public function localMetaPath(string $id): string { return $this->backupDir($id) . '/local.json'; }

    /**
     * What only this site keeps about a backup (the note, where it came from, whether it was uploaded
     * here). Kept out of the public manifest, which is sent to dosya.
     */
    public function writeLocalMeta(string $id, string $note, string $origin, bool $uploaded): void
    {
        $json = json_encode(['note' => $note, 'origin' => $origin, 'uploaded' => $uploaded], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $p = $this->localMetaPath($id);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- engine code runs outside WordPress
        if (file_put_contents($p . '.tmp', $json) === false || !@rename($p . '.tmp', $p)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            throw new \RuntimeException('Could not write the backup details.');
        }
    }

    public function incomingDir(string $uploadId): string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $uploadId) !== 1) {
            throw new \InvalidArgumentException('Invalid upload id');
        }
        return $this->dir . '/incoming/' . $uploadId;
    }

    /**
     * Complete local backups, newest first. size and bytes_new are this backup's own volumes; bytes_total
     * adds the volumes of earlier backups an incremental reads (those found on this site).
     */
    public function list(): array
    {
        $out = [];
        $volSizes = [];
        $refs = [];
        foreach (is_dir($this->dir) ? (scandir($this->dir) ?: []) : [] as $name) {
            if (preg_match('/^[0-9a-f]{32}$/', $name) !== 1 || !is_readable($this->publicManifestPath($name))) {
                continue;
            }
            $pub = json_decode((string) file_get_contents($this->publicManifestPath($name)), true);
            if (!is_array($pub) || ($pub['backup_id'] ?? null) !== $name || !isset($pub['created'], $pub['kind'])
                || !is_array($pub['volumes'] ?? null)) {
                continue;
            }
            $size = 0;
            foreach ($pub['volumes'] as $v) {
                if (is_array($v) && isset($v['size']) && is_numeric($v['size'])) {
                    $size += (int) $v['size'];
                    $volSizes[$name . ':' . (int) ($v['v'] ?? 0)] = (int) $v['size'];
                }
            }
            $local = is_readable($this->localMetaPath($name)) ? json_decode((string) file_get_contents($this->localMetaPath($name)), true) : null;
            $local = is_array($local) ? $local : [];
            $refs[$name] = is_array($pub['refs'] ?? null) ? $pub['refs'] : [];
            $out[] = ['id' => $name, 'created' => (int) $pub['created'], 'kind' => (string) $pub['kind'],
                'scope' => (string) ($pub['scope'] ?? 'full'), 'label' => (string) ($pub['label'] ?? ''),
                'size' => $size, 'volumes' => count($pub['volumes']), 'bytes_new' => $size, 'bytes_total' => $size,
                'note' => (string) ($local['note'] ?? ''), 'origin' => isset($local['origin']) && $local['origin'] !== '' ? (string) $local['origin'] : null,
                'uploaded' => !empty($local['uploaded'])];
        }
        foreach ($out as $i => $b) {
            foreach ($refs[$b['id']] as $r) {
                if (is_array($r) && isset($r['b'], $r['v'])) {
                    $out[$i]['bytes_total'] += $volSizes[$r['b'] . ':' . (int) $r['v']] ?? 0;
                }
            }
        }
        usort($out, function (array $a, array $b): int { return $b['created'] <=> $a['created']; });
        return $out;
    }

    public function load(string $id): Manifest
    {
        return Manifest::loadGz($this->manifestPath($id));
    }

    public function delete(string $id): void
    {
        Fs::rrmdir($this->backupDir($id));
    }

    /** @param string[] $protect ids that must survive (e.g. the backup a restore is reading) @return string[] deleted ids */
    public function prune(int $keepNormal, int $keepSnapshots, int $now, array $protect = []): array
    {
        $deleted = [];
        $normal = 0;
        $snaps = 0;
        $complete = [];
        $kept = [];
        $list = $this->list();
        foreach ($list as $b) {
            $complete[$b['id']] = true;
            $isSnap = $b['label'] === self::SNAPSHOT_LABEL;
            $keep = $isSnap ? (++$snaps <= $keepSnapshots) : (++$normal <= $keepNormal);
            if ($keep || in_array($b['id'], $protect, true)) {
                $kept[$b['id']] = true;
            }
        }
        // an incremental reads volumes of older backups: everything a kept backup references stays too
        $kept = $this->closure(array_keys($kept));
        foreach ($list as $b) {
            if (!isset($kept[$b['id']])) {
                $this->delete($b['id']);
                $deleted[] = $b['id'];
            }
        }
        foreach (is_dir($this->dir) ? (scandir($this->dir) ?: []) : [] as $name) {
            if (preg_match('/^[0-9a-f]{32}$/', $name) === 1 && !isset($complete[$name]) && $this->newestMtime($this->dir . '/' . $name) < $now - 86400) {
                $this->delete($name);
                $deleted[] = $name;
            }
        }
        return $deleted;
    }

    /**
     * The other local backups that read volumes of $id (directly or through the backups they read),
     * newest first. Deleting $id would break their restore. @return string[]
     */
    public function dependents(string $id): array
    {
        $out = [];
        foreach ($this->list() as $b) {
            if ($b['id'] === $id) {
                continue;
            }
            if (isset($this->closure([$b['id']])[$id])) {
                $out[] = $b['id'];
            }
        }
        return $out;
    }

    /**
     * The roots plus every backup they reference, transitively (an incremental
     * reads volumes of older backups). Shared by prune() and dependents().
     *
     * @param list<string> $roots
     * @return array<string, true> backup id => true
     */
    private function closure(array $roots): array
    {
        $seen = [];
        foreach ($roots as $root) {
            $seen[(string) $root] = true;
        }
        $queue = array_keys($seen);
        while ($queue) {
            foreach ($this->referencedIds((string) array_pop($queue)) as $ref) {
                if (!isset($seen[$ref])) {
                    $seen[$ref] = true;
                    $queue[] = $ref;
                }
            }
        }
        return $seen;
    }

    /**
     * How many other backups build on each of $rows (directly or through the backups they read), from one pass over their manifests.
     * @param array<int,array> $rows LocalStore::list() rows
     * @return array<string,int> backup id => count
     */
    public function dependentCounts(array $rows): array
    {
        $refs = [];
        foreach ($rows as $b) {
            $refs[(string) $b['id']] = $this->referencedIds((string) $b['id']);
        }
        $counts = array_fill_keys(array_keys($refs), 0);
        foreach ($refs as $root => $_) {
            $seen = [$root => true];
            $queue = [$root];
            while ($queue) {
                foreach ($refs[array_pop($queue)] ?? [] as $r) {
                    if (!isset($seen[$r])) {
                        $seen[$r] = true;
                        $queue[] = $r;
                        if (isset($counts[$r])) {
                            $counts[$r]++;
                        }
                    }
                }
            }
        }
        return $counts;
    }

    /** Null when $id may be deleted, else why not (it is the base of newer backups). */
    public function deleteRefusal(string $id): ?string
    {
        $deps = $this->dependents($id);
        if ($deps === []) {
            return null;
        }
        return sprintf('This backup is the base of %d newer backup(s): %s. Delete those first.', count($deps), implode(', ', $deps));
    }

    /** Deletes $id unless a newer backup builds on it. @throws \InvalidArgumentException with deleteRefusal's message */
    public function deleteUnlessBase(string $id): void
    {
        $why = $this->deleteRefusal($id);
        if ($why !== null) {
            throw new \InvalidArgumentException($why); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, escaped where displayed
        }
        $this->delete($id);
    }

    /** Backup ids whose volumes $id reads (its public manifest's refs). @return string[] */
    public function referencedIds(string $id): array
    {
        $p = $this->publicManifestPath($id);
        $pub = is_readable($p) ? json_decode((string) file_get_contents($p), true) : null;
        $out = [];
        foreach (is_array($pub) && is_array($pub['refs'] ?? null) ? $pub['refs'] : [] as $r) {
            $b = is_array($r) ? ($r['b'] ?? null) : null;
            if (is_string($b) && $b !== $id && preg_match('/^[0-9a-f]{32}$/', $b) === 1) {
                $out[$b] = true;
            }
        }
        return array_keys($out);
    }

    private function newestMtime(string $dir): int
    {
        $t = (int) filemtime($dir);
        foreach ([$dir . '/entries.jsonl', $dir . '/files.jsonl', $dir . '/volumes'] as $p) {
            if (file_exists($p)) {
                $t = max($t, (int) filemtime($p));
            }
        }
        foreach (glob($dir . '/volumes/*.dosya') ?: [] as $v) {
            $t = max($t, (int) filemtime($v));
        }
        return $t;
    }
}
