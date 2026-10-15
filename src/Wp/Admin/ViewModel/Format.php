<?php
namespace Dosya\Backup\Wp\Admin\ViewModel;

/**
 * Pure formatting helpers for admin view models. No WordPress calls.
 */
final class Format
{
    public static function bytes(int $b): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        if ($b < 1024) {
            return $b . ' B';
        }
        $i = 0;
        $v = (float) $b;
        while (true) {
            while ($v >= 1024 && $i < 4) {
                $v /= 1024;
                $i++;
            }
            // Round before choosing the unit so 1023.95 MB reads "1 GB", not "1024 MB".
            if ($i < 4 && round($v) >= 1024) {
                $v /= 1024;
                $i++;
                continue;
            }
            break;
        }
        if ($v >= 100 || round($v, 1) >= 100 || abs($v - round($v)) < 0.05) {
            $s = (string) (int) round($v);
        } else {
            $s = number_format($v, 1, '.', '');
        }
        return $s . ' ' . $units[$i];
    }

    public static function relative(int $ts, int $now, callable $t): string
    {
        $d = $now - $ts;
        if ($d < 60) {
            return $t('just now');
        }
        if ($d < 3600) {
            $n = intdiv($d, 60);
            return sprintf($n === 1 ? $t('%d minute ago') : $t('%d minutes ago'), $n);
        }
        if ($d < 86400) {
            $n = intdiv($d, 3600);
            return sprintf($n === 1 ? $t('%d hour ago') : $t('%d hours ago'), $n);
        }
        if ($d < 172800) {
            return $t('yesterday');
        }
        return sprintf($t('%d days ago'), intdiv($d, 86400));
    }

    /**
     * Day label and clock time in the given zone, e.g. ['today', '03:00'] or ['11 Oct', '14:20'].
     *
     * @param callable|null $fmt fn(int $ts, string $pattern): string, used for the date part.
     * @return array{0:string,1:string}
     */
    public static function parts(int $ts, int $now, string $tz, callable $t, bool $capital = true, ?callable $fmt = null): array
    {
        try {
            $zone = new \DateTimeZone($tz !== '' ? $tz : 'UTC');
        } catch (\Exception $e) {
            $zone = new \DateTimeZone('UTC');
        }
        $d = (new \DateTimeImmutable('@' . $ts))->setTimezone($zone);
        $n = (new \DateTimeImmutable('@' . $now))->setTimezone($zone);
        $day = $d->format('Y-m-d');
        $time = $d->format('H:i');
        if ($day === $n->format('Y-m-d')) {
            return [$capital ? $t('Today') : $t('today'), $time];
        }
        if ($day === $n->modify('-1 day')->format('Y-m-d')) {
            return [$capital ? $t('Yesterday') : $t('yesterday'), $time];
        }
        if ($day === $n->modify('+1 day')->format('Y-m-d')) {
            return [$capital ? $t('Tomorrow') : $t('tomorrow'), $time];
        }
        $pattern = $t('j M');
        $date = $fmt !== null ? (string) $fmt($ts, $pattern) : $d->format($pattern);
        return [$date, $time];
    }

    public static function when(int $ts, int $now, string $tz, callable $t, bool $capital = true, ?callable $fmt = null): string
    {
        $p = self::parts($ts, $now, $tz, $t, $capital, $fmt);
        return sprintf($t('%1$s %2$s'), $p[0], $p[1]);
    }
}
