<?php
namespace Dosya\Backup\Wp\Admin;

use Dosya\Backup\Wp\Admin\ViewModel\Format;
use Dosya\Backup\Wp\Admin\ViewModel\ResultBanner;
use Dosya\Backup\Wp\Admin\ViewModel\StatusCard;
use Dosya\Backup\Jobs\StepRunner;
use Dosya\Backup\Wp\Plugin;
use Dosya\Backup\Wp\Scheduler;
use Dosya\Backup\Wp\Settings;
use Dosya\Backup\Wp\SiteKeyStore;

/**
 * Gathers the Backups list inputs from WordPress and the stores, shared by the `list` AJAX action and the server-rendered tab.
 */
final class ListAssembler
{
    public const PER_PAGE = 20;

    /** @param array|null $totals receives count and bytes of every backup stored on this server */
    public static function build(int $page, ?array &$totals = null): array
    {
        $active = Plugin::jobs()->active();
        $store = Plugin::store();
        $remote = Plugin::remoteStore();
        $rows = $store->list();
        $totals = ['count' => count($rows), 'bytes' => array_sum(array_map(static function (array $b): int { return (int) $b['size']; }, $rows))];
        $info = [];
        foreach ($rows as $b) {
            $info[$b['id']] = $remote->info($b['id']);
        }
        $deps = $store->dependentCounts($rows);
        $uploading = '';
        if ($active !== null && $active['kind'] === 'backup' && isset($active['cursor']['remote']['vols']) && empty($active['cursor']['remote']['done'])) {
            $uploading = (string) ($active['cursor']['id'] ?? '');
        }
        $ctx = ['now' => time(), 'tz' => wp_timezone_string(), 'uploading_id' => $uploading,
            'fmt' => static function (int $ts, string $pattern): string { return wp_date($pattern, $ts); }];
        $t = self::translator();
        $backupJobs = Plugin::jobs()->recent('backup', 20);
        $payload = ListPayload::build($rows, $backupJobs, $info, $deps, max(1, $page), self::PER_PAGE, $ctx, $t);
        $settings = Settings::get();
        $keyState = (new SiteKeyStore())->state();
        $nextRun = Scheduler::nextRun();

        // the status card reads this site's newest backup, whatever page is shown, and the job ListPayload::selectLastJob picks
        $lastBackup = ListPayload::newestBackup($rows, $info, $uploading);
        $job = ListPayload::selectLastJob(array_merge($backupJobs, Plugin::jobs()->recent('restore', 5)));
        $cursor = $job !== null && is_array($job['cursor']) ? $job['cursor'] : [];
        $interval = $settings['schedule'] === 'daily' ? 86400 : ($settings['schedule'] === 'weekly' ? 604800 : null);
        $card = StatusCard::build([
            'now' => $ctx['now'],
            'tz' => $ctx['tz'],
            'last_job' => $job === null ? null : ['kind' => $job['kind'], 'state' => $job['state'], 'error' => $job['error'],
                'result' => $job['result'], 'created_at' => (int) $job['created_at'],
                'backup_id' => $job['kind'] === 'backup' && isset($cursor['id']) ? (string) $cursor['id'] : null,
                'snapshot_id' => $job['kind'] === 'restore' && isset($cursor['snapshot_id']) && is_string($cursor['snapshot_id']) ? $cursor['snapshot_id'] : null],
            'last_backup' => $lastBackup,
            'remote' => ['connected' => $keyState === 'ok', 'key_state' => $keyState, 'used' => null, 'total' => null],
            'schedule' => ['on' => $settings['schedule'] !== 'off', 'interval' => $interval, 'next_run' => $nextRun,
                'time' => (string) $settings['time'], 'keep' => (int) $settings['keep']],
            'running' => $active !== null,
        ], $t);
        $card['fix_url'] = null;
        $card['log_job_id'] = null;
        if ($card['fix'] !== null) {
            $target = (string) $card['fix']['target'];
            if ($target === 'log') {
                $card['fix_url'] = AdminPage::tabUrl('backups') . '#dosya-log';
                $card['log_job_id'] = $job !== null ? (string) $job['id'] : null;
            } elseif ($target !== 'restore_safety') {
                // restore_safety is a button (undo_restore with fix.snapshot_id), not a link
                $card['fix_url'] = AdminPage::tabUrl($target);
            }
        }
        // Retry now runs the failed backup's scope again
        $scope = (string) ($cursor['scope'] ?? 'full');
        $card['retry_scope'] = $card['button']['action'] === 'retry' && in_array($scope, ['full', 'db', 'files'], true) ? $scope : 'full';

        $foot = sprintf(
            /* translators: 1: number of backups, 2: their total size, e.g. "1.2 GB". */
            _n('%1$d backup · %2$s on this server', '%1$d backups · %2$s on this server', (int) $totals['count'], 'dosya-backup-restore-migration'),
            (int) $totals['count'],
            Format::bytes((int) $totals['bytes'])
        );

        return $payload + ['active' => $active === null ? null : self::status($active['id']), 'settings' => $settings,
            'next_run' => $nextRun, 'storage' => null, 'key_state' => $keyState,
            'tz' => $ctx['tz'], 'now' => $ctx['now'], 'status_vm' => $card, 'foot_text' => $foot];
    }

    private static function translator(): callable
    {
        return I18nCatalog::translator();
    }

    /**
     * Adds what the page needs beside a job status: the job's kind, and on a terminal state the result banner (`result_vm`).
     *
     * @param array<string,mixed> $status StepRunner / status() shape
     * @return array<string,mixed>
     */
    public static function decorate(array $status): array
    {
        $job = Plugin::jobs()->get((string) ($status['id'] ?? ''));
        if (!isset($status['kind'])) {
            $status['kind'] = $job !== null ? (string) $job['kind'] : 'backup';
        }
        if (!StepRunner::isTerminal((string) ($status['state'] ?? ''))) {
            return $status;
        }
        $now = time();
        $ctx = ['now' => $now, 'tz' => wp_timezone_string()];
        if ($job !== null && $status['kind'] === 'restore') {
            $cursor = is_array($job['cursor']) ? $job['cursor'] : [];
            if (isset($cursor['snapshot_id']) && is_string($cursor['snapshot_id'])) {
                $ctx['snapshot_id'] = $cursor['snapshot_id'];
            }
            $restored = (string) ($cursor['plan']['backup_id'] ?? '');
            foreach (Plugin::store()->list() as $b) {
                if ($b['id'] === $restored) {
                    $ctx['backup_created'] = (int) $b['created'];
                    break;
                }
            }
        }
        $status['result_vm'] = ResultBanner::build($status, $ctx, self::translator());
        return $status;
    }

    public static function status(string $jobId): array
    {
        $job = Plugin::jobs()->get($jobId);
        if ($job === null) {
            throw new \InvalidArgumentException(esc_html__('Unknown job.', 'dosya-backup-restore-migration'));
        }
        return ['id' => $job['id'], 'kind' => $job['kind'], 'state' => $job['state'], 'progress' => $job['cursor']['progress'] ?? null,
            'error' => $job['error'], 'result' => $job['result']];
    }
}
