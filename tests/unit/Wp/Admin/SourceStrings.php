<?php
namespace Dosya\Backup\Tests\unit\Wp\Admin;

/** Pure file parsing helpers shared by the i18n and copy tests. No WordPress needed. */
final class SourceStrings
{
    public static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    /** @return string[] absolute paths */
    public static function files(string $dir, string $ext = 'php'): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === $ext) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    /** Decode the body of a PHP string literal. */
    public static function decode(string $body, string $quote): string
    {
        if ($quote === "'") {
            return (string) preg_replace("/\\\\(['\\\\])/", '$1', $body);
        }
        return (string) preg_replace('/\\\\(["\\\\$])/', '$1', $body);
    }

    /**
     * Literal first arguments of calls to the given functions.
     *
     * @param string[] $funcs
     * @return string[]
     */
    public static function literalArgs(string $src, array $funcs): array
    {
        $names = implode('|', array_map(static function (string $f): string { return preg_quote($f, '/'); }, $funcs));
        preg_match_all('/(?<![A-Za-z0-9_>:$])(?:' . $names . ')\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/s', $src, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $hit) {
            $out[] = ($hit[1] ?? '') !== '' || !isset($hit[2]) ? self::decode($hit[1], "'") : self::decode($hit[2], '"');
        }
        return $out;
    }
}
