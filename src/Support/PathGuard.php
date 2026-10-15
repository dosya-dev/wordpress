<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI).
namespace Dosya\Backup\Support;

final class PathGuard
{
    public static function resolve(string $root, string $rel): string
    {
        $rel = str_replace('\\', '/', $rel);
        if ($rel === '' || strpos($rel, "\0") !== false || $rel[0] === '/' || preg_match('#^[A-Za-z]:#', $rel) === 1) {
            throw new \InvalidArgumentException('Unsafe path in backup: ' . $rel);
        }
        $parts = [];
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                throw new \InvalidArgumentException('Unsafe path in backup: ' . $rel);
            }
            $parts[] = $seg;
        }
        if (!$parts) {
            throw new \InvalidArgumentException('Unsafe path in backup: ' . $rel);
        }
        return rtrim($root, '/') . '/' . implode('/', $parts);
    }

    public static function normalize(string $abs): string
    {
        $out = [];
        foreach (explode('/', $abs) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        return '/' . implode('/', $out);
    }

    public static function inside(string $path, string $root): bool
    {
        $root = rtrim($root, '/');
        return $path === $root || strpos($path, $root . '/') === 0;
    }

    public static function parentInside(string $path, string $rootReal): bool
    {
        $dir = dirname($path);
        while (!file_exists($dir) && !is_link($dir) && $dir !== dirname($dir)) {
            $dir = dirname($dir);
        }
        $real = realpath($dir);
        return $real !== false && self::inside($real, $rootReal);
    }

    /** Resolves $target as the kernel would (symlinks followed before "..") and checks it lands inside $rootReal. */
    public static function resolvesInside(string $linkPath, string $target, string $rootReal): bool
    {
        $dir = realpath(dirname($linkPath));
        if ($dir === false || !self::inside($dir, $rootReal)) {
            return false;
        }
        $absolute = $target !== '' && $target[0] === '/';
        $cur = $absolute ? '/' : $dir;
        $dangling = false;
        foreach (explode('/', $target) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($dangling) {
                    return false;
                }
                $cur = dirname($cur);
            } else {
                $next = rtrim($cur, '/') . '/' . $seg;
                if (file_exists($next) || is_link($next)) {
                    $real = realpath($next);
                    if ($real === false) {
                        return false;
                    }
                    $next = $real;
                } else {
                    $dangling = true;
                }
                $cur = $next;
            }
            if (!$absolute && !self::inside($cur, $rootReal)) {
                return false;
            }
        }
        return self::inside($cur, $rootReal);
    }
}
