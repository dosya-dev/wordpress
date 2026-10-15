<?php
namespace Dosya\Backup\Scan;

use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\PathCodec;
use Dosya\Backup\Support\Budget;
use Dosya\Backup\Support\Text;

final class FileScanner
{
    private string $root;
    private Excludes $ex;
    private int $maxLogBytes;

    public function __construct(string $root, Excludes $ex, int $maxLogBytes = 10485760)
    {
        $this->root = rtrim($root, '/');
        $this->ex = $ex;
        $this->maxLogBytes = $maxLogBytes;
    }

    public static function start(): array
    {
        return ['stack' => [['p' => '', 'n' => null]], 'done' => false];
    }

    public function run(array $cursor, Budget $budget, callable $emit, callable $rawWarn): array
    {
        // paths may hold any bytes; warnings end up in JSON, so they must be valid UTF-8
        $warn = function (string $w) use ($rawWarn): void { $rawWarn(Text::utf8($w)); };
        $stack = $cursor['stack'];
        $listings = [];
        $progressed = false;
        while ($stack) {
            if ($progressed && $budget->exhausted()) {
                return ['stack' => $stack, 'done' => false];
            }
            $depth = count($stack) - 1;
            $dir = PathCodec::unpack($stack[$depth]);
            if (!isset($listings[$depth])) {
                $listings[$depth] = $this->listDir($dir, $warn);
            }
            $names = $listings[$depth];
            $after = $stack[$depth]['n'] === null ? null : PathCodec::unpack($stack[$depth]['n']);
            $idx = self::nextIndex($names, $after);
            if ($idx >= count($names)) {
                array_pop($stack);
                unset($listings[$depth]);
                continue;
            }
            $name = $names[$idx];
            $stack[$depth]['n'] = PathCodec::pack($name);
            $progressed = true;
            $rel = $dir === '' ? $name : $dir . '/' . $name;
            if ($this->ex->matches($rel)) {
                continue;
            }
            $abs = $this->root . '/' . $rel;
            $st = @lstat($abs);
            if ($st === false) {
                $warn('Could not read ' . $rel);
                continue;
            }
            $kind = $st['mode'] & 0170000;
            $base = PathCodec::pack($rel) + ['mt' => (int) $st['mtime'], 'm' => $st['mode'] & 07777];
            if ($kind === 0120000) {
                $target = @readlink($abs);
                if ($target === false) {
                    $warn('Could not read link ' . $rel);
                    continue;
                }
                $emit($base + ['t' => Format::TYPE_SYMLINK, 's' => 0, 'l' => PathCodec::pack($target)]);
            } elseif ($kind === 0040000) {
                $emit($base + ['t' => Format::TYPE_DIR, 's' => 0]);
                $stack[] = PathCodec::pack($rel) + ['n' => null];
            } elseif ($kind === 0100000) {
                if (substr($name, -4) === '.log' && $st['size'] > $this->maxLogBytes) {
                    continue;
                }
                if (!is_readable($abs)) {
                    $warn('Could not read ' . $rel);
                    continue;
                }
                $emit($base + ['t' => Format::TYPE_FILE, 's' => (int) $st['size']]);
            }
        }
        return ['stack' => [], 'done' => true];
    }

    /**
     * Index of the first name strictly greater than $after (names sorted with SORT_STRING).
     * Returns count($names) when there is none.
     *
     * @param string[] $names
     */
    private static function nextIndex(array $names, ?string $after): int
    {
        if ($after === null) {
            return 0;
        }
        $lo = 0;
        $hi = count($names);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if (strcmp($names[$mid], $after) <= 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    /** @return string[] */
    private function listDir(string $dir, callable $warn): array
    {
        $abs = $dir === '' ? $this->root : $this->root . '/' . $dir;
        $names = @scandir($abs);
        if ($names === false) {
            $warn('Could not list ' . ($dir === '' ? '/' : $dir));
            return [];
        }
        $names = array_values(array_filter($names, function (string $n): bool { return $n !== '.' && $n !== '..'; }));
        sort($names, SORT_STRING);
        return $names;
    }
}
