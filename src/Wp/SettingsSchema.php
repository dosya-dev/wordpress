<?php
namespace Dosya\Backup\Wp;

use Dosya\Backup\Scan\Excludes;

/** Pure validation of the plugin settings; no WordPress calls so it is unit-testable. */
final class SettingsSchema
{
    public const DEFAULTS = [
        'schedule' => 'daily', 'time' => '03:00', 'keep' => 3, 'excludes' => [], 'volume_mb' => 64,
        'safety_copy' => true, 'email_fail' => true, 'email_ok' => false,
    ];
    public const TIME_RE = '/^([01]\d|2[0-3]):[0-5]\d$/';
    private const SCHEDULES = ['off', 'daily', 'weekly'];

    /** @param array<string,mixed> $in @param array<string,mixed> $current */
    public static function normalize(array $in, array $current): array
    {
        $cur = array_merge(self::DEFAULTS, $current);
        $out = $cur;
        if (array_key_exists('schedule', $in)) {
            $out['schedule'] = in_array($in['schedule'], self::SCHEDULES, true) ? $in['schedule'] : $cur['schedule'];
        }
        if (array_key_exists('time', $in)) {
            $out['time'] = is_string($in['time']) && preg_match(self::TIME_RE, $in['time']) ? $in['time'] : $cur['time'];
        }
        if (array_key_exists('keep', $in)) {
            $out['keep'] = is_numeric($in['keep']) ? max(1, min(30, (int) $in['keep'])) : $cur['keep'];
        }
        if (array_key_exists('volume_mb', $in) && $in['volume_mb'] !== '' && $in['volume_mb'] !== null) {
            $out['volume_mb'] = is_numeric($in['volume_mb']) ? max(16, min(256, (int) $in['volume_mb'])) : $cur['volume_mb'];
        }
        foreach (['safety_copy', 'email_fail', 'email_ok'] as $flag) {
            if (array_key_exists($flag, $in)) {
                $out[$flag] = self::bool($in[$flag], (bool) $cur[$flag]);
            }
        }
        if (array_key_exists('excludes', $in) && is_array($in['excludes'])) {
            $out['excludes'] = self::excludes($in['excludes']);
        }
        return $out;
    }

    private static function bool($v, bool $fallback): bool
    {
        if (is_bool($v)) return $v;
        if (is_string($v) || is_int($v)) {
            $s = strtolower((string) $v);
            if (in_array($s, ['1', 'true', 'on', 'yes'], true)) return true;
            if (in_array($s, ['0', 'false', 'off', 'no', ''], true)) return false;
        }
        return $fallback;
    }

    /** @param array<int,mixed> $list */
    private static function excludes(array $list): array
    {
        $out = [];
        foreach ($list as $raw) {
            if (!is_string($raw)) continue;
            $p = trim(preg_replace('/[\x00-\x1f\x7f]/', '', $raw) ?? '');
            if ($p === '' || mb_strlen($p) > 200) continue;
            if (@fnmatch($p, '') === false && preg_match('/\[[^\]]*$/', $p)) continue; // unbalanced bracket glob
            try {
                new Excludes([$p]);
            } catch (\InvalidArgumentException $e) {
                continue;
            }
            $out[$p] = true;
            if (count($out) >= 100) break;
        }
        return array_keys($out);
    }
}
