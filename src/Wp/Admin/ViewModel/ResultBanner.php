<?php
namespace Dosya\Backup\Wp\Admin\ViewModel;

/**
 * Persistent result after a job reaches a terminal state. No WordPress calls.
 */
final class ResultBanner
{
    /**
     * @param array<string,mixed> $status
     * @param array<string,mixed> $ctx now, tz, optional backup_created (restore) and snapshot_id (failed restore)
     * @return array{tone:string,text:string,action:?array{label:string,kind:string,id:?string}}
     */
    public static function build(array $status, array $ctx, callable $t): array
    {
        $kind = ($status['kind'] ?? 'backup') === 'restore' ? 'restore' : 'backup';
        $state = (string) ($status['state'] ?? '');
        $result = isset($status['result']) && is_array($status['result']) ? $status['result'] : [];
        $reason = self::clean(isset($status['error']) && is_scalar($status['error']) ? (string) $status['error'] : '');
        $err = $reason !== '' ? $reason : $t('Unknown error');

        if ($state === 'done') {
            return $kind === 'restore' ? self::restoreDone($result, $ctx, $t) : self::backupDone($result, $t);
        }
        if ($state === 'failed') {
            if ($kind === 'restore') {
                $snap = isset($ctx['snapshot_id']) && is_string($ctx['snapshot_id']) && $ctx['snapshot_id'] !== '' ? $ctx['snapshot_id'] : null;
                $action = $snap !== null
                    ? ['label' => $t('Restore safety copy'), 'kind' => 'restore_safety', 'id' => $snap]
                    : ['label' => $t('Show log'), 'kind' => 'log', 'id' => null];
                /* translators: %s: the reason the restore failed. */
                return ['tone' => 'error', 'text' => sprintf($t('Restore failed: %s'), $err), 'action' => $action];
            }
            /* translators: %s: the reason the backup failed. */
            return ['tone' => 'error', 'text' => sprintf($t('Backup failed: %s'), $err), 'action' => ['label' => $t('Retry now'), 'kind' => 'retry', 'id' => null]];
        }
        if ($state === 'skipped') {
            if ($reason === '') {
                return ['tone' => 'caution', 'text' => $t('Backup skipped.'), 'action' => null];
            }
            /* translators: %s: the reason the backup was skipped. */
            return ['tone' => 'caution', 'text' => sprintf($t('Backup skipped: %s'), $err), 'action' => null];
        }
        return ['tone' => 'caution', 'text' => $t('Cancelled.'), 'action' => null];
    }

    /** @param array<string,mixed> $r */
    private static function backupDone(array $r, callable $t): array
    {
        $size = Format::bytes((int) ($r['size'] ?? 0));
        if (!empty($r['remote'])) {
            /* translators: %s: backup size such as 1.2 GB. */
            $text = sprintf($t('Backup complete, %s, stored in dosya, not yet verified.'), $size);
        } else {
            /* translators: %s: backup size such as 1.2 GB. */
            $text = sprintf($t('Backup complete, %s, on this server.'), $size);
        }
        $tone = 'success';
        $w = isset($r['warnings']) && is_array($r['warnings']) ? array_values($r['warnings']) : [];
        if ($w !== []) {
            $tone = 'caution';
            $n = count($w);
            /* translators: 1: number of warnings, 2: the first warning. */
            $text = sprintf($t('%1$s %2$s'), $text, sprintf($n === 1 ? $t('With %1$d warning: %2$s') : $t('With %1$d warnings: %2$s'), $n, self::clean((string) $w[0])));
        }
        return ['tone' => $tone, 'text' => $text, 'action' => null];
    }

    /**
     * @param array<string,mixed> $r
     * @param array<string,mixed> $ctx
     */
    private static function restoreDone(array $r, array $ctx, callable $t): array
    {
        $now = (int) ($ctx['now'] ?? 0);
        $when = Format::when((int) ($ctx['backup_created'] ?? $now), $now, (string) ($ctx['tz'] ?? 'UTC'), $t, false);
        /* translators: %s: date and time of the restored backup. */
        $text = sprintf($t('Site restored to %s. Links checked, cache cleared, maintenance page removed.'), $when);
        $snap = isset($r['snapshot_id']) && is_string($r['snapshot_id']) && $r['snapshot_id'] !== '' ? $r['snapshot_id'] : null;
        return [
            'tone' => 'success',
            'text' => $text,
            'action' => $snap !== null ? ['label' => $t('Undo this restore'), 'kind' => 'undo', 'id' => $snap] : null,
        ];
    }

    /** Trim, collapse whitespace and cap at 300 characters. */
    private static function clean(string $s): string
    {
        $c = preg_replace('/\s+/u', ' ', trim($s));
        if ($c === null) {
            $c = trim(preg_replace('/\s+/', ' ', $s) ?? '');
            return strlen($c) > 300 ? substr($c, 0, 297) . '...' : $c;
        }
        if (preg_match('/^(.{297}).{4}/su', $c, $m) === 1) {
            return $m[1] . '...';
        }
        return $c;
    }
}
