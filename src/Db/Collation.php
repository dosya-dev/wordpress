<?php
namespace Dosya\Backup\Db;

final class Collation
{
    /** @param string[] $supported @return array{0:string,1:array<string,string>} */
    public static function rewrite(string $ddl, array $supported): array
    {
        $set = array_flip(array_map('strtolower', $supported));
        $subs = [];
        $out = (string) preg_replace_callback('/\b(utf8mb4|utf8mb3|utf8)_[a-z0-9_]+\b/i', function (array $m) use ($set, &$subs): string {
            $c = strtolower($m[0]);
            if (isset($set[$c])) {
                return $m[0];
            }
            $alt = self::fallback($c, $set);
            $subs[$c] = $alt;
            return $alt;
        }, $ddl);
        if (!isset($set['utf8mb3_general_ci'])) {
            $out = (string) preg_replace('/\butf8mb3\b/i', 'utf8', $out);
        }
        return [$out, $subs];
    }

    private static function fallback(string $c, array $set): string
    {
        if (strpos($c, 'utf8mb4_') === 0) {
            $chain = ['utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci'];
            $last = 'utf8mb4_general_ci';
        } else {
            $chain = ['utf8_unicode_520_ci', 'utf8_unicode_ci', 'utf8_general_ci', 'utf8mb3_unicode_ci', 'utf8mb3_general_ci'];
            if (strpos($c, 'utf8mb3_') === 0) {
                array_unshift($chain, 'utf8_' . substr($c, 8));
            }
            $last = 'utf8_general_ci';
        }
        foreach ($chain as $alt) {
            if (isset($set[$alt])) {
                return $alt;
            }
        }
        return $last;
    }
}
