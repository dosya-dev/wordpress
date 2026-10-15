<?php
namespace Dosya\Backup\Db;

final class TableSelector
{
    /**
     * Tables that belong to this install: start with $prefix, minus any table of another install whose
     * prefix extends ours (detected by that prefix owning options + posts + postmeta).
     * @param string[] $all @param string[] $exclude @return string[]
     */
    public static function select(array $all, string $prefix, bool $multisite, array $exclude = []): array
    {
        $mine = array_values(array_filter($all, function (string $t) use ($prefix): bool {
            return strncmp($t, $prefix, strlen($prefix)) === 0;
        }));
        $set = array_flip($mine);
        $foreign = [];
        foreach ($mine as $t) {
            if (substr($t, -7) !== 'options') {
                continue;
            }
            $cand = substr($t, 0, -7);
            if ($cand === $prefix || !isset($set[$cand . 'posts'], $set[$cand . 'postmeta'])) {
                continue;
            }
            if ($multisite && preg_match('/^' . preg_quote($prefix, '/') . '\d+_$/', $cand) === 1) {
                continue;
            }
            $foreign[] = $cand;
        }
        return array_values(array_filter($mine, function (string $t) use ($foreign, $exclude): bool {
            if (in_array($t, $exclude, true)) {
                return false;
            }
            foreach ($foreign as $f) {
                if (strncmp($t, $f, strlen($f)) === 0) {
                    return false;
                }
            }
            return true;
        }));
    }
}
