<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.unlink_unlink -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI); engine code runs outside WordPress and streams large files, which WP_Filesystem cannot do.
namespace Dosya\Backup\Restore;

use Dosya\Backup\Archive\BackupReader;
use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\Manifest;
use Dosya\Backup\Archive\PathCodec;
use Dosya\Backup\Support\AppendLog;
use Dosya\Backup\Support\Budget;
use Dosya\Backup\Support\Fs;
use Dosya\Backup\Support\PathGuard;
use Dosya\Backup\Support\Text;

final class FileRestorer
{
    private string $root;
    private string $staging;
    private Manifest $m;
    private BackupReader $reader;
    /** @var callable */
    private $selects;

    public function __construct(string $root, string $staging, Manifest $m, BackupReader $reader, callable $selects)
    {
        $this->root = rtrim($root, '/');
        $this->staging = rtrim($staging, '/');
        $this->m = $m;
        $this->reader = $reader;
        $this->selects = $selects;
    }

    public static function start(): array
    {
        return ['i' => 0, 'blk' => 0, 'links' => 0, 'done' => false, 'mi' => 0, 'links_done' => false, 'moved' => false];
    }

    public function fetch(array $c, Budget $b, callable $warn): array
    {
        Fs::ensureDir($this->staging . '/f');
        $links = new AppendLog($this->staging . '/links.jsonl', (int) $c['links']);
        $n = count($this->m->files);
        $progressed = false;
        while ($c['i'] < $n) {
            if ($progressed && $b->exhausted()) {
                break;
            }
            $progressed = true;
            $row = $this->m->files[$c['i']];
            $path = PathCodec::unpack($row);
            $type = (int) $row['t'];
            if (!($this->selects)($path, $type)) {
                $c['i']++;
                continue;
            }
            try {
                $dst = PathGuard::resolve($this->staging . '/f', $path);
            } catch (\InvalidArgumentException $e) {
                $warn(Text::utf8('Skipped unsafe path ' . $path));
                $c['i']++;
                continue;
            }
            if ($type === Format::TYPE_DIR) {
                Fs::ensureDir($dst);
                $c['i']++;
                continue;
            }
            if ($type === Format::TYPE_SYMLINK) {
                $links->append(PathCodec::pack($path) + ['l' => PathCodec::pack($this->reader->readAll($row['loc']))]);
                $c['i']++;
                continue;
            }
            Fs::ensureDir(dirname($dst));
            $entry = $this->reader->entry($row['loc']);
            $offset = 0;
            for ($k = 0; $k < (int) $c['blk']; $k++) {
                $offset += (int) $entry['b'][$k][2];
            }
            clearstatcache(true, $dst);
            if ($offset > 0 && (!is_file($dst) || filesize($dst) < $offset)) {
                $c['blk'] = 0;
                $offset = 0;
            }
            $fh = fopen($dst, 'c+b');
            if ($fh === false || !ftruncate($fh, $offset) || fseek($fh, $offset) !== 0) {
                throw new \RuntimeException('Cannot write ' . $path . ' to the staging folder.');
            }
            $next = $this->reader->readEntry($row['loc'], function (string $plain) use ($fh): void {
                if (fwrite($fh, $plain) !== strlen($plain)) {
                    throw new \RuntimeException('Write failed (disk full?)');
                }
            }, (int) $c['blk'], function () use ($b): bool { return $b->exhausted(); });
            fclose($fh);
            if ($next !== -1) {
                $c['blk'] = $next;
                break;
            }
            touch($dst, (int) $row['mt']);
            $c['blk'] = 0;
            $c['i']++;
        }
        $c['links'] = $links->length();
        $c['done'] = $c['i'] >= $n;
        return $c;
    }

    public function move(array $c, Budget $b, callable $warn): array
    {
        $rootReal = (string) realpath($this->root);
        $n = count($this->m->files);
        $progressed = false;
        while ($c['mi'] < $n) {
            if ($progressed && $b->exhausted()) {
                return $c;
            }
            $progressed = true;
            $row = $this->m->files[$c['mi']];
            $c['mi']++;
            $path = PathCodec::unpack($row);
            $type = (int) $row['t'];
            if ($type === Format::TYPE_SYMLINK || !($this->selects)($path, $type)) {
                continue;
            }
            try {
                $dst = PathGuard::resolve($this->root, $path);
                $src = PathGuard::resolve($this->staging . '/f', $path);
            } catch (\InvalidArgumentException $e) {
                $warn(Text::utf8('Skipped unsafe path ' . $path));
                continue;
            }
            if (!PathGuard::parentInside($dst, $rootReal)) {
                $warn(Text::utf8('Skipped ' . $path . ': its folder points outside the site.'));
                continue;
            }
            if ($type === Format::TYPE_DIR) {
                if (is_link($dst) && is_dir($dst)) {
                    $warn(Text::utf8('Kept existing link ' . $path));
                    continue;
                }
                if (is_link($dst)) {
                    unlink($dst);
                }
                Fs::ensureDir($dst);
                continue;
            }
            if (!is_file($src)) {
                continue;
            }
            if (is_link($dst)) {
                unlink($dst);
            }
            if (is_dir($dst)) {
                $warn(Text::utf8('Skipped ' . $path . ': a folder with that name exists.'));
                continue;
            }
            Fs::ensureDir(dirname($dst));
            if (!rename($src, $dst)) {
                if (!copy($src, $dst)) {
                    throw new \RuntimeException('Cannot write ' . $path);
                }
                unlink($src);
            }
            chmod($dst, ((int) $row['m'] & 0777) ?: 0644);
            touch($dst, (int) $row['mt']);
        }
        if (!$c['links_done']) {
            $this->restoreLinks((int) $c['links'], $rootReal, $warn);
            $c['links_done'] = true;
        }
        $c['moved'] = true;
        return $c;
    }

    public function cleanup(): void
    {
        Fs::rrmdir($this->staging);
    }

    private function restoreLinks(int $length, string $rootReal, callable $warn): void
    {
        if ($length === 0) {
            return;
        }
        $log = new AppendLog($this->staging . '/links.jsonl', $length);
        $pos = 0;
        $created = [];
        while ($page = $log->readFrom($pos, 500)) {
            foreach ($page as [$row, $end]) {
                $pos = $end;
                $path = PathCodec::unpack($row);
                $target = PathCodec::unpack($row['l']);
                if (!($this->selects)($path, Format::TYPE_SYMLINK)) {
                    continue;
                }
                try {
                    $link = PathGuard::resolve($this->root, $path);
                } catch (\InvalidArgumentException $e) {
                    $warn(Text::utf8('Skipped unsafe path ' . $path));
                    continue;
                }
                if (!PathGuard::parentInside($link, $rootReal) || !PathGuard::resolvesInside($link, $target, $rootReal)) {
                    $warn(Text::utf8('Skipped link ' . $path . ': it points outside the site.'));
                    continue;
                }
                if (file_exists($link) && !is_link($link)) {
                    $warn(Text::utf8('Skipped link ' . $path . ': a file or folder with that name exists.'));
                    continue;
                }
                if (is_link($link)) {
                    unlink($link);
                }
                if (!@symlink($target, $link)) {
                    $warn(Text::utf8('Skipped link ' . $path . ': it could not be created.'));
                    continue;
                }
                $parent = realpath(dirname($link));
                if ($parent === false || !PathGuard::inside($parent, $rootReal)) {
                    unlink($link);
                    $warn(Text::utf8('Skipped link ' . $path . ': it points outside the site.'));
                    continue;
                }
                $created[$path] = $link;
            }
        }
        foreach ($created as $path => $link) {
            $real = realpath($link);
            $target = (string) readlink($link);
            if ($real !== false ? !PathGuard::inside($real, $rootReal) : !PathGuard::resolvesInside($link, $target, $rootReal)) {
                unlink($link);
                $warn(Text::utf8('Removed link ' . $path . ': it points outside the site.'));
            }
        }
    }
}
