<?php
namespace Dosya\Backup\Wp\Admin\ViewModel;

/**
 * View model for the status card at the top of the Backups tab. Pure: no WordPress calls.
 *
 * Input contract (all keys required unless marked nullable):
 *  - now (int): current unix time.
 *  - tz (string): IANA timezone used to render times.
 *  - last_job (?array): newest ENDED job of any kind: kind ('backup'|'restore'), state (string, 'failed' counts),
 *    error (?string, cause shown to the user), result (?array), created_at (int unix), optional backup_id (?string, the
 *    backup a backup job wrote, its cursor id), optional snapshot_id (?string, the safety copy a restore job made).
 *    A failed backup job counts as a failure when it is newer than last_backup (or no backup exists); a failed backup
 *    job whose backup_id is last_backup's id failed after the local write, so it only cautions that the upload failed.
 *    A failed restore job counts when it is newer than last_backup. ListPayload::selectLastJob decides which job
 *    arrives here: a failed backup until a later backup is done, a failed restore until a later backup or restore is done.
 *  - last_backup (?array): newest backup of this site (ListPayload::newestBackup): id (string), created (int unix),
 *    size (int bytes), remote_state ('none'|'uploading'|'uploaded'|'verified'), locked_until (?int unix).
 *  - remote (array): connected (bool), key_state ('none'|'ok'|'unreadable'|'revoked'),
 *    used (?int bytes), total (?int bytes).
 *  - schedule (array): on (bool), interval (?int seconds), next_run (?int unix),
 *    time (?string "HH:MM"), keep (?int backups kept).
 *  - running (bool): a job is running now (not used for the copy yet).
 *
 * Output: tone (neutral|success|caution|error), headline, sub, button {label, action backup|retry},
 * fix (?{label, target schedule|dosya|log|restore_safety, snapshot_id only for restore_safety}).
 *
 * Overdue: a backup is overdue once it is older than 1.5 times the schedule interval. The grace is half an interval
 * because a scheduled run starts on WP-Cron, which fires only on a page visit, and a run itself takes time; a daily
 * backup that ran at 03:10 yesterday and is still running at 03:20 today is not late. At 1.5 intervals one whole run
 * has been missed, which is the first moment the warning is true.
 */
final class StatusCard
{
    public const NEARLY_FULL = 0.9;
    private const GB = 1073741824;
    private const CAUSE_MAX = 160;

    /** The sentence naming the next scheduled run, e.g. "Next backup tomorrow at 03:00." */
    public static function nextRunText(int $nextRun, int $now, string $tz, callable $t, ?callable $fmt = null): string
    {
        $p = Format::parts($nextRun, $now, $tz, $t, false, $fmt);
        return sprintf($t('Next backup %1$s at %2$s.'), $p[0], $p[1]);
    }

    public static function build(array $in, callable $t): array
    {
        $now = (int) $in['now'];
        $tz = (string) $in['tz'];
        $job = $in['last_job'] ?? null;
        $lb = $in['last_backup'] ?? null;
        $remote = $in['remote'];
        $sched = $in['schedule'];

        $nextTs = !empty($sched['on']) && !empty($sched['next_run']) && (int) $sched['next_run'] > $now ? (int) $sched['next_run'] : 0;

        $jobFailed = $job && ($job['state'] ?? '') === 'failed';
        $jobKind = $job ? (string) ($job['kind'] ?? '') : '';
        $newer = $job && ($lb === null || (int) $job['created_at'] > (int) $lb['created']);
        // the backup was written here and only its upload to dosya failed
        $uploadFailed = $jobFailed && $jobKind === 'backup' && $lb !== null
            && isset($job['backup_id']) && (string) $job['backup_id'] !== '' && (string) $job['backup_id'] === (string) $lb['id'];
        $failed = $jobFailed && $jobKind === 'backup' && !$uploadFailed && $newer;

        if ($jobFailed && $jobKind === 'restore' && $newer) {
            $cause = self::cause((string) ($job['error'] ?? ''), $t);
            $snapshot = isset($job['snapshot_id']) && is_string($job['snapshot_id']) && $job['snapshot_id'] !== '' ? $job['snapshot_id'] : null;
            $sentences = [];
            $sentences[] = $lb
                ? sprintf($t('Your last good backup is from %1$s and is %2$s.'), Format::when((int) $lb['created'], $now, $tz, $t, false), self::storedPhrase((string) $lb['remote_state'], $t))
                : $t('No earlier backup exists yet.');
            $sentences[] = $snapshot !== null
                ? $t('The safety copy made before the restore puts the site back as it was.')
                : $t('Open the log to see what stopped it.');
            return [
                'tone' => 'error',
                'headline' => sprintf($t('Last restore failed: %s'), $cause),
                'sub' => implode(' ', $sentences),
                'button' => ['label' => $t('Back up now'), 'action' => 'backup'],
                'fix' => $snapshot !== null
                    ? ['label' => $t('Restore safety copy'), 'target' => 'restore_safety', 'snapshot_id' => $snapshot]
                    : ['label' => $t('View log'), 'target' => 'log'],
            ];
        }

        if ($failed) {
            $cause = self::cause((string) ($job['error'] ?? ''), $t);
            $sentences = [];
            $sentences[] = $lb
                ? sprintf($t('Your last good backup is from %1$s and is %2$s.'), Format::when((int) $lb['created'], $now, $tz, $t, false), self::storedPhrase((string) $lb['remote_state'], $t))
                : $t('No earlier backup exists yet.');
            $sentences[] = $t('Fix the cause, then retry.');
            if ($nextTs) {
                $sentences[] = self::nextRunText($nextTs, $now, $tz, $t);
            } elseif (empty($sched['on'])) {
                $sentences[] = $t('Automatic backups are off.');
            }
            return [
                'tone' => 'error',
                'headline' => sprintf($t('Last backup failed: %s'), $cause),
                'sub' => implode(' ', $sentences),
                'button' => ['label' => $t('Retry now'), 'action' => 'retry'],
                'fix' => ['label' => $t('View log'), 'target' => 'log'],
            ];
        }

        if ($lb === null) {
            $parts = [$t('Your first backup is saved on this server.')];
            if (empty($sched['on'])) {
                $parts[] = $t('Automatic backups are off; turn them on under Schedule.');
            } else {
                $label = self::intervalLabel(isset($sched['interval']) ? (int) $sched['interval'] : 0, $t);
                $time = (string) ($sched['time'] ?? '');
                $parts[] = $time !== '' && isset($sched['keep'])
                    ? sprintf($t('%1$s backups are already on (%2$s, keeps %3$d).'), $label, $time, (int) $sched['keep'])
                    : sprintf($t('%1$s backups are already on.'), $label);
            }
            if (empty($remote['connected'])) {
                $parts[] = $t('Connect to dosya to store copies off-site, protected from deletion.');
            }
            return [
                'tone' => 'neutral',
                'headline' => $t('No backup yet'),
                'sub' => implode(' ', $parts),
                'button' => ['label' => $t('Back up now'), 'action' => 'backup'],
                'fix' => null,
            ];
        }

        $rel = Format::relative((int) $lb['created'], $now, $t);
        $state = (string) $lb['remote_state'];
        if ($state === 'verified') {
            $head = sprintf($t('Verified restorable, %s'), $rel);
        } elseif ($state === 'uploaded') {
            $head = sprintf($t('Uploaded to dosya, not yet verified, %s'), $rel);
        } elseif ($state === 'uploading') {
            $head = sprintf($t('Uploading to dosya, %s'), $rel);
        } else {
            $head = sprintf($t('Backed up on this server, %s'), $rel);
        }

        $parts = [Format::bytes((int) $lb['size'])];
        if ($state === 'uploaded' || $state === 'verified') {
            if (!empty($lb['locked_until']) && (int) $lb['locked_until'] > $now) {
                $parts[] = sprintf($t('stored in dosya, protected until %s'), Format::when((int) $lb['locked_until'], $now, $tz, $t, false));
            } else {
                $parts[] = $t('stored in dosya');
            }
        }
        if ($nextTs) {
            $p = Format::parts($nextTs, $now, $tz, $t, false);
            $parts[] = sprintf($t('next backup %1$s at %2$s'), $p[0], $p[1]);
        } elseif (empty($sched['on'])) {
            $parts[] = $t('automatic backups are off');
        }

        $tone = 'success';
        $fix = null;
        $keyState = (string) ($remote['key_state'] ?? 'none');
        if ($uploadFailed) {
            $tone = 'caution';
            /* translators: %s: why the upload to dosya failed. */
            $parts[] = sprintf($t('upload to dosya failed: %s'), self::cause((string) ($job['error'] ?? ''), $t));
            $fix = $keyState === 'ok' ? ['label' => $t('View log'), 'target' => 'log'] : ['label' => $t('Connect again'), 'target' => 'dosya'];
        }
        $overdue = !empty($sched['on']) && !empty($sched['interval'])
            && ($now - (int) $lb['created']) > (int) $sched['interval'] * 1.5;
        if ($overdue) {
            $tone = 'caution';
            $head = $t('Backup overdue');
            $parts[] = sprintf($t('last backup %s'), $rel);
            $parts[] = $t('Changes since then are not protected.');
            $fix = ['label' => $t('Check schedule'), 'target' => 'schedule'];
        }

        if ($keyState === 'unreadable' || $keyState === 'revoked') {
            $tone = 'caution';
            $parts[] = $t('dosya connection lost, uploads paused');
            $fix = ['label' => $t('Connect again'), 'target' => 'dosya'];
        } elseif (!empty($remote['connected']) && !empty($remote['total'])) {
            $used = (int) ($remote['used'] ?? 0);
            $total = (int) $remote['total'];
            if ($total >= self::GB) {
                $parts[] = sprintf($t('%1$s of %2$s GB used'), self::num($used), self::num($total));
            } else {
                $parts[] = sprintf($t('%1$s of %2$s used'), Format::bytes($used), Format::bytes($total));
            }
            if ($used / max(1, $total) >= self::NEARLY_FULL) {
                $tone = 'caution';
                $parts[] = $t('dosya storage almost full');
                $fix = ['label' => $t('Review storage'), 'target' => 'dosya'];
            }
        }

        return [
            'tone' => $tone,
            'headline' => $head,
            'sub' => implode(' · ', array_filter($parts)),
            'button' => ['label' => $t('Back up now'), 'action' => 'backup'],
            'fix' => $fix,
        ];
    }

    private static function cause(string $raw, callable $t): string
    {
        $cause = trim($raw);
        if ($cause === '') {
            return $t('unknown error');
        }
        if (preg_match('/^(.{' . self::CAUSE_MAX . '}).+/su', $cause, $m)) {
            return $m[1] . '...';
        }
        if (preg_match('//u', $cause) !== 1 && strlen($cause) > self::CAUSE_MAX) {
            return substr($cause, 0, self::CAUSE_MAX) . '...';
        }
        return $cause;
    }

    private static function intervalLabel(int $interval, callable $t): string
    {
        if ($interval === 86400) {
            return $t('Daily');
        }
        if ($interval === 604800) {
            return $t('Weekly');
        }
        return $t('Automatic');
    }

    public static function storedPhrase(string $state, callable $t): string
    {
        if ($state === 'verified') {
            return $t('verified in dosya');
        }
        if ($state === 'uploaded') {
            return $t('in dosya, not yet verified');
        }
        if ($state === 'uploading') {
            return $t('in dosya, upload in progress');
        }
        return $t('on this server only');
    }

    /** Bare GB number, at most one decimal, trailing ".0" stripped. */
    private static function num(int $bytes): string
    {
        $s = number_format($bytes / self::GB, 1, '.', '');
        return substr($s, -2) === '.0' ? substr($s, 0, -2) : $s;
    }
}
