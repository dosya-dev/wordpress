<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI).
namespace Dosya\Backup\Scan;

final class Excludes
{
    /** @var string[] */
    private array $globs;
    /** @var string[] */
    private array $regexes = [];

    /** @param string[] $globs */
    public function __construct(array $globs)
    {
        $this->globs = [];
        foreach ($globs as $g) {
            $g = trim(str_replace('\\', '/', (string) $g), "/ \t");
            if ($g !== '') {
                $re = self::toRegex($g);
                if (@preg_match($re, '') === false) {
                    throw new \InvalidArgumentException('invalid exclude pattern: ' . $g);
                }
                $this->globs[] = $g;
                $this->regexes[] = $re;
            }
        }
    }

    /** @return string[] */
    public static function defaults(): array
    {
        return [
            'wp-content/cache/**', 'wp-content/upgrade/**', 'wp-content/upgrade-temp-backup/**',
            'wp-content/dosya-backup-local/**', 'wp-content/dosya-restore-staging/**', 'wp-content/dosya-restore.lock',
            'wp-content/updraft/**', 'wp-content/ai1wm-backups/**', 'wp-content/backups-dup-lite/**', 'wp-content/backups-dup-pro/**',
            'wp-content/wpvivid*/**', 'wp-content/uploads/wpvivid*/**', 'wp-content/backwpup-*/**', 'wp-content/uploads/backwpup-*/**',
            'wp-content/backup-migration*/**', 'wp-content/uploads/backup-migration*/**', 'wp-content/backuply/**',
            '**/.git/**', '**/node_modules/**',
        ];
    }

    public function with(string $glob): self
    {
        return new self(array_merge($this->globs, [$glob]));
    }

    public function matches(string $relPath): bool
    {
        foreach ($this->regexes as $re) {
            if (preg_match($re, $relPath) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function toRegex(string $glob): string
    {
        $re = '';
        $n = strlen($glob);
        for ($i = 0; $i < $n; $i++) {
            $c = $glob[$i];
            if ($c === '/' && $i + 3 === $n && substr($glob, $i, 3) === '/**') {
                $re .= '(?:/.*)?';
                $i += 2;
            } elseif ($c === '*') {
                if (($glob[$i + 1] ?? '') === '*') {
                    $i++;
                    if (($glob[$i + 1] ?? '') === '/') {
                        $i++;
                        $re .= '(?:.*/)?';
                    } else {
                        $re .= '.*';
                    }
                } else {
                    $re .= '[^/]*';
                }
            } elseif ($c === '?') {
                $re .= '[^/]';
            } elseif ($c === '[' && ($j = strpos($glob, ']', $i + 1)) !== false) {
                $class = substr($glob, $i + 1, $j - $i - 1);
                $neg = $class !== '' && $class[0] === '!';
                if ($neg) {
                    $class = substr($class, 1);
                }
                $re .= '[' . ($neg ? '^' : '') . str_replace(['\\', '#'], ['\\\\', '\\#'], $class) . ']';
                $i = $j;
            } else {
                $re .= preg_quote($c, '#');
            }
        }
        return '#^' . $re . '$#s';
    }
}
