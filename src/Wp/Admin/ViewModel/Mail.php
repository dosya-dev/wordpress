<?php
namespace Dosya\Backup\Wp\Admin\ViewModel;

/**
 * Subject and plain-text body for the email sent once when a backup job ends. Pure: no WordPress calls.
 *
 * $status: kind, state, error (?string), result (?array: size, remote, locked_until).
 * $ctx: site_name, admin_url, last_good (?array created_text, stored_phrase), email_fail, email_ok, now, tz.
 * Returns ['subject' => string, 'body' => string], or null when no mail applies. Restores never send mail.
 */
final class Mail
{
    private const CAUSE_MAX = 120;

    /** @return array{subject:string,body:string}|null */
    public static function build(array $status, array $ctx, callable $t): ?array
    {
        if (($status['kind'] ?? 'backup') !== 'backup') {
            return null;
        }
        $state = (string) ($status['state'] ?? '');
        $site = (string) ($ctx['site_name'] ?? '');
        $url = (string) ($ctx['admin_url'] ?? '');
        $raw = isset($status['error']) && is_scalar($status['error']) ? (string) $status['error'] : '';

        if ($state === 'failed' && !empty($ctx['email_fail'])) {
            $cause = self::cause($raw, $t);
            $lines = [
                /* translators: %s: the reason the backup failed. */
                sprintf($t('The latest backup of this site failed: %s'), $cause),
                '',
                self::lastGood($ctx['last_good'] ?? null, $t),
                self::fix($raw, $t),
                '',
                $url,
            ];
            /* translators: 1: site name, 2: the reason the backup failed. */
            return ['subject' => sprintf($t('[%1$s] Backup failed: %2$s'), $site, $cause), 'body' => implode("\n", $lines) . "\n"];
        }

        if ($state === 'skipped' && !empty($ctx['email_fail'])) {
            $cause = self::cause($raw, $t);
            $lines = [
                /* translators: %s: the reason the backup was skipped. */
                sprintf($t('The latest backup of this site was skipped: %s'), $cause),
                '',
                self::lastGood($ctx['last_good'] ?? null, $t),
                '',
                $url,
            ];
            /* translators: 1: site name, 2: the reason the backup was skipped. */
            return ['subject' => sprintf($t('[%1$s] Backup skipped: %2$s'), $site, $cause), 'body' => implode("\n", $lines) . "\n"];
        }

        if ($state === 'done' && !empty($ctx['email_ok'])) {
            $r = isset($status['result']) && is_array($status['result']) ? $status['result'] : [];
            $size = Format::bytes((int) ($r['size'] ?? 0));
            if (!empty($r['remote'])) {
                /* translators: %s: backup size such as 1.2 GB. */
                $line = sprintf($t('The latest backup of this site is complete: %s, stored in dosya.'), $size);
            } else {
                /* translators: %s: backup size such as 1.2 GB. */
                $line = sprintf($t('The latest backup of this site is complete: %s, on this server.'), $size);
            }
            $lines = [$line];
            if (!empty($r['remote']) && !empty($r['locked_until']) && (int) $r['locked_until'] > (int) ($ctx['now'] ?? 0)) {
                $when = Format::when((int) $r['locked_until'], (int) ($ctx['now'] ?? 0), (string) ($ctx['tz'] ?? 'UTC'), $t, false);
                /* translators: %s: date and time until which the stored copy cannot be deleted. */
                $lines[] = sprintf($t('It is protected from deletion until %s.'), $when);
            }
            $lines[] = '';
            $lines[] = $url;
            /* translators: %s: site name. */
            return ['subject' => sprintf($t('[%s] Backup complete'), $site), 'body' => implode("\n", $lines) . "\n"];
        }

        return null;
    }

    private static function lastGood($lg, callable $t): string
    {
        if (!is_array($lg)) {
            return $t('No earlier backup exists yet.');
        }
        /* translators: 1: when the last good backup was made, 2: where it is stored. */
        return sprintf($t('Your last good backup is from %1$s and is %2$s.'), (string) ($lg['created_text'] ?? ''), (string) ($lg['stored_phrase'] ?? ''));
    }

    private static function fix(string $raw, callable $t): string
    {
        if (preg_match('/disk space|no space|quota/i', $raw) === 1) {
            return $t('Free up disk space or exclude folders under Schedule, then retry.');
        }
        if (preg_match('/connection|revoked|unauthori[sz]ed|\bkey\b/i', $raw) === 1) {
            return $t('Connect to dosya again, then retry.');
        }
        return $t('Open dosya Backup to see the log and retry.');
    }

    private static function cause(string $raw, callable $t): string
    {
        $c = preg_replace('/\s+/u', ' ', trim($raw));
        if ($c === null) {
            $c = trim(preg_replace('/\s+/', ' ', $raw) ?? '');
        }
        if ($c === '') {
            return $t('unknown error');
        }
        if (preg_match('/^(.{' . self::CAUSE_MAX . '}).+/su', $c, $m) === 1) {
            return $m[1] . '...';
        }
        if (preg_match('//u', $c) !== 1 && strlen($c) > self::CAUSE_MAX) {
            return substr($c, 0, self::CAUSE_MAX) . '...';
        }
        return $c;
    }
}
