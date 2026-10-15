<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.unlink_unlink -- engine code runs outside WordPress, where WP_Filesystem is not available.
namespace Dosya\Backup\Remote;

use Dosya\Backup\Archive\FormatException;
use Dosya\Backup\Archive\Manifest;
use Dosya\Backup\Support\Fs;

/**
 * What this site knows about the backups it stored in dosya, kept beside the
 * local store: remote/<id>/manifest.json.gz (the manifest as uploaded, which
 * the next incremental backup reads), remote/<id>/remote.json (the server's
 * answer) and remote/last.json {backup_id, created} for the newest one.
 * The local backup id is the dosya backup id (32 lowercase hex).
 */
final class RemoteStore
{
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    /** An incremental chain starts a new full backup once its full is this old. */
    public const CHAIN_MAX_AGE = 30 * 86400;

    private string $dir;

    /** @param string $localStoreDir the LocalStore directory; this store lives in its remote/ subfolder */
    public function __construct(string $localStoreDir)
    {
        $this->dir = rtrim($localStoreDir, '/') . '/remote';
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function backupDir(string $id): string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid backup id');
        }
        return $this->dir . '/' . $id;
    }

    public function manifestPath(string $id): string
    {
        return $this->backupDir($id) . '/manifest.json.gz';
    }

    public function has(string $id): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $id) === 1 && is_file($this->manifestPath($id));
    }

    /** The server's answer recorded for $id (remote.json), or null when this site never stored it. @return array<string,mixed>|null */
    public function info(string $id): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $id) !== 1 || !is_file($this->dir . '/' . $id . '/remote.json')) {
            return null;
        }
        $j = json_decode((string) file_get_contents($this->dir . '/' . $id . '/remote.json'), true);
        return is_array($j) ? $j : null;
    }

    /**
     * Idempotent: a repeat after a crash rewrites the same files.
     * @param array<string,mixed> $info the server's answer to complete (locked_until, expires_at, ...)
     */
    public function recordUploaded(string $id, int $created, string $manifestPath, array $info = []): void
    {
        $dir = $this->backupDir($id);
        Fs::ensureDir($dir);
        if (!@copy($manifestPath, $dir . '/manifest.json.gz.tmp') || !@rename($dir . '/manifest.json.gz.tmp', $dir . '/manifest.json.gz')) {
            throw new \RuntimeException('Could not keep a copy of the uploaded manifest.');
        }
        $this->writeJson($dir . '/remote.json', ['backup_id' => $id, 'created' => $created] + $info);
        $last = $this->last();
        if ($last === null || $created >= (int) $last['created']) {
            $this->writeJson($this->dir . '/last.json', ['backup_id' => $id, 'created' => $created]);
        }
    }

    /** @return array{backup_id:string,created:int}|null */
    public function last(): ?array
    {
        $p = $this->dir . '/last.json';
        if (!is_file($p)) {
            return null;
        }
        $j = json_decode((string) file_get_contents($p), true);
        if (!is_array($j) || !is_string($j['backup_id'] ?? null) || preg_match('/^[0-9a-f]{32}$/', $j['backup_id']) !== 1 || !is_int($j['created'] ?? null)) {
            return null;
        }
        return ['backup_id' => $j['backup_id'], 'created' => $j['created']];
    }

    /** The manifest of the newest uploaded backup, or null when there is none or it cannot be read. */
    public function previousManifest(): ?Manifest
    {
        $last = $this->last();
        return $last === null ? null : $this->manifest($last['backup_id']);
    }

    /**
     * Whether the next backup may be an incremental on top of $prev: dosya lists $prev and every backup
     * it reads as stored (uploaded or verified), and the chain's full is younger than CHAIN_MAX_AGE.
     * @param list<array<string,mixed>> $serverBackups GET /api/wp/sites/{id}/backups
     */
    public function previousIsUsable(array $serverBackups, Manifest $prev, int $now): bool
    {
        $stored = [];
        foreach ($serverBackups as $b) {
            if (is_array($b) && is_string($b['id'] ?? null) && in_array($b['state'] ?? null, ['uploaded', 'verified'], true)) {
                $stored[$b['id']] = true;
            }
        }
        $id = $prev->meta['backup_id'] ?? null;
        if (!is_string($id) || !isset($stored[$id])) {
            return false;
        }
        $since = self::fullSince($prev);
        if ($since === null || $now - $since >= self::CHAIN_MAX_AGE) {
            return false;
        }
        foreach (self::refs($prev) as $r) {
            if (!isset($stored[$r['b']])) {
                return false;
            }
        }
        return true;
    }

    /** When the chain $m belongs to started (its full backup's created time). */
    public static function fullSince(Manifest $m): ?int
    {
        $v = $m->meta['full_since'] ?? $m->meta['created'] ?? null;
        return is_int($v) ? $v : null;
    }

    /**
     * Size of every volume an incremental on top of $prev may reference: $prev's own volumes and the
     * volumes $prev itself reads (from its meta.ref_volumes, else from the referenced backup's kept
     * manifest). Keys are "<backup id>:<volume no>". Null when a size is unknown.
     * @return array<string,int>|null
     */
    public function volumeSizes(Manifest $prev): ?array
    {
        $sizes = [];
        $own = (string) ($prev->meta['backup_id'] ?? '');
        foreach ((array) ($prev->meta['volumes'] ?? []) as $v) {
            if (is_array($v) && isset($v['v'], $v['size'])) {
                $sizes[$own . ':' . (int) $v['v']] = (int) $v['size'];
            }
        }
        foreach ((array) ($prev->meta['ref_volumes'] ?? []) as $v) {
            if (is_array($v) && is_string($v['b'] ?? null) && isset($v['v'], $v['size'])) {
                $sizes[$v['b'] . ':' . (int) $v['v']] = (int) $v['size'];
            }
        }
        $loaded = [];
        foreach (self::refs($prev) as $r) {
            $k = $r['b'] . ':' . $r['v'];
            if (isset($sizes[$k])) {
                continue;
            }
            if (!isset($loaded[$r['b']])) {
                $loaded[$r['b']] = true;
                $older = $this->manifest($r['b']);
                foreach ($older !== null ? (array) ($older->meta['volumes'] ?? []) : [] as $v) {
                    if (is_array($v) && isset($v['v'], $v['size'])) {
                        $sizes[$r['b'] . ':' . (int) $v['v']] = (int) $v['size'];
                    }
                }
            }
            if (!isset($sizes[$k])) {
                return null;
            }
        }
        return $sizes;
    }

    /** @return list<array{b:string,v:int}> */
    private static function refs(Manifest $m): array
    {
        $out = [];
        foreach ($m->publicView()['refs'] as $r) {
            if (is_string($r['b'] ?? null) && preg_match('/^[0-9a-f]{32}$/', $r['b']) === 1) {
                $out[] = ['b' => $r['b'], 'v' => (int) $r['v']];
            }
        }
        return $out;
    }

    private function manifest(string $id): ?Manifest
    {
        if (!$this->has($id)) {
            return null;
        }
        try {
            $m = Manifest::loadGz($this->manifestPath($id));
        } catch (FormatException $e) {
            return null;
        }
        return ($m->meta['backup_id'] ?? null) === $id && isset($m->meta['created'], $m->meta['kind'], $m->meta['volumes']) ? $m : null;
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        if (file_put_contents($path . '.tmp', json_encode($data, self::FLAGS)) === false || !@rename($path . '.tmp', $path)) {
            throw new \RuntimeException('Could not record the uploaded backup.');
        }
    }
}
