<?php
namespace Dosya\Backup\Wp\Admin;

use Dosya\Backup\Jobs\LocalStore;
use Dosya\Backup\Wp\Admin\ViewModel\BackupRow;

/**
 * Assembles the Backups tab list response from raw inputs. Pure: no WordPress calls.
 */
final class ListPayload
{
    /**
     * @param array<int,array> $rows       LocalStore::list() rows
     * @param array<int,array> $failedJobs JobStore::recent('backup', n) rows (any state)
     * @param array            $remoteInfo backup id => RemoteStore::info() array or null
     * @param array            $dependents backup id => number of newer backups built on it
     * @param array            $ctx        now, tz, optional uploading_id, optional fmt
     * @param callable         $t          Translator
     */
    public static function build(array $rows, array $failedJobs, array $remoteInfo, array $dependents, int $page, int $perPage, array $ctx, callable $t): array
    {
        $uploading = isset($ctx['uploading_id']) ? (string) $ctx['uploading_id'] : '';
        $stored = [];
        foreach ($rows as $b) {
            $stored[(string) $b['id']] = true;
        }
        // a failed job whose backup is stored failed after the local write (the upload): it marks that row, not a row of its own.
        // Jobs come newest first, so the newest failure for a backup wins.
        $uploadFailed = [];
        $orphans = [];
        foreach ($failedJobs as $j) {
            if (($j['state'] ?? '') !== 'failed') {
                continue;
            }
            $c = is_array($j['cursor'] ?? null) ? $j['cursor'] : [];
            // a backup's id is the cursor's id; the job id is minted separately
            $backupId = (string) ($c['id'] ?? '');
            if ($backupId !== '' && isset($stored[$backupId])) {
                if (!isset($uploadFailed[$backupId])) {
                    $uploadFailed[$backupId] = (string) ($j['error'] ?? '') !== '' ? (string) $j['error'] : $t('The upload to dosya did not finish.');
                }
                continue;
            }
            $orphans[] = $j;
        }
        $all = [];
        foreach ($rows as $b) {
            $id = (string) $b['id'];
            $info = $remoteInfo[$id] ?? null;
            $state = self::remoteState($id, $info, $uploading);
            $all[] = $b + [
                'note' => '', 'origin' => null,
            ] + [
                'remote_state' => $state,
                'remote' => $state !== 'none',
                'locked_until' => is_array($info) && isset($info['locked_until']) ? (int) $info['locked_until'] : null,
                'failed_reason' => null,
                // a later successful upload of the same backup clears the failure
                'upload_failed_reason' => $state === 'none' && isset($uploadFailed[$id]) ? $uploadFailed[$id] : null,
                'is_safety' => ($b['label'] ?? '') === LocalStore::SNAPSHOT_LABEL,
                'is_uploaded' => !empty($b['uploaded']),
                'dependents' => (int) ($dependents[$id] ?? 0),
            ];
        }
        foreach ($orphans as $j) {
            $id = (string) ($j['id'] ?? '');
            $c = is_array($j['cursor'] ?? null) ? $j['cursor'] : [];
            $all[] = ['id' => $id, 'created' => (int) ($j['created_at'] ?? 0), 'kind' => (string) ($c['kind'] ?? 'full'),
                'scope' => (string) ($c['scope'] ?? 'full'), 'label' => (string) ($c['label'] ?? ''), 'size' => 0, 'volumes' => 0,
                'bytes_new' => 0, 'bytes_total' => 0, 'note' => (string) ($c['note'] ?? ''), 'origin' => null, 'remote_state' => 'none',
                'locked_until' => null, 'failed_reason' => (string) ($j['error'] ?? '') !== '' ? (string) $j['error'] : $t('The backup did not finish.'),
                'upload_failed_reason' => null,
                'is_safety' => false, 'is_uploaded' => false, 'dependents' => 0, 'remote' => false];
        }
        usort($all, static function (array $a, array $b): int { return $b['created'] <=> $a['created']; });
        $perPage = max(1, $perPage);
        $page = max(1, $page);
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);
        $vm = [];
        foreach ($slice as $r) {
            $vm[] = BackupRow::build($r, ['dependents' => $r['dependents']] + $ctx, $t);
        }
        return ['backups' => $slice, 'rows_vm' => $vm, 'page' => $page, 'per_page' => $perPage, 'total' => count($all)];
    }

    /**
     * The newest backup the status card reports on, in its `last_backup` shape (id, created, size, remote_state,
     * locked_until), or null. A backup uploaded from another site and a safety copy made before a restore are not
     * this site's own backups, so neither counts.
     *
     * @param array<int,array> $rows       LocalStore::list() rows
     * @param array            $remoteInfo backup id => RemoteStore::info() array or null
     */
    public static function newestBackup(array $rows, array $remoteInfo, ?string $uploadingId): ?array
    {
        $best = null;
        foreach ($rows as $b) {
            if (($b['uploaded'] ?? false) === true || ($b['label'] ?? '') === LocalStore::SNAPSHOT_LABEL) {
                continue;
            }
            if ($best === null || (int) $b['created'] > (int) $best['created']) {
                $best = $b;
            }
        }
        if ($best === null) {
            return null;
        }
        $id = (string) $best['id'];
        $info = $remoteInfo[$id] ?? null;
        return ['id' => $id, 'created' => (int) $best['created'], 'size' => (int) $best['size'],
            'remote_state' => self::remoteState($id, $info, (string) $uploadingId),
            'locked_until' => is_array($info) && isset($info['locked_until']) ? (int) $info['locked_until'] : null];
    }

    /**
     * The job the status card (and the site-wide notice) reports on. Cancelled, skipped and running jobs are ignored.
     *  - A failed backup stays shown until a later backup job is done: a restore or a cancelled run does not clear it.
     *  - A failed restore is shown when it is newer than the newest backup job and no later restore is done
     *    (StatusCard also requires it to be newer than the last backup).
     *  - When both are shown, the newer failure wins.
     *  - Otherwise the newest done job of either kind, or null.
     *
     * @param array<int,array> $jobs decoded JobStore rows (kind, state, created_at, cursor, ...) of both kinds, any order
     */
    public static function selectLastJob(array $jobs): ?array
    {
        $newest = ['backup' => null, 'restore' => null];
        foreach ($jobs as $j) {
            $kind = (string) ($j['kind'] ?? '');
            $state = (string) ($j['state'] ?? '');
            if (!array_key_exists($kind, $newest) || ($state !== 'done' && $state !== 'failed')) {
                continue;
            }
            if ($newest[$kind] === null || (int) $j['created_at'] > (int) $newest[$kind]['created_at']) {
                $newest[$kind] = $j;
            }
        }
        $b = $newest['backup'];
        $r = $newest['restore'];
        $failedBackup = $b !== null && $b['state'] === 'failed' ? $b : null;
        $failedRestore = $r !== null && $r['state'] === 'failed' && ($b === null || (int) $r['created_at'] > (int) $b['created_at']) ? $r : null;
        if ($failedBackup !== null && $failedRestore !== null) {
            return (int) $failedRestore['created_at'] > (int) $failedBackup['created_at'] ? $failedRestore : $failedBackup;
        }
        if ($failedBackup !== null || $failedRestore !== null) {
            return $failedBackup ?? $failedRestore;
        }
        $done = null;
        foreach ([$b, $r] as $j) {
            if ($j !== null && $j['state'] === 'done' && ($done === null || (int) $j['created_at'] > (int) $done['created_at'])) {
                $done = $j;
            }
        }
        return $done;
    }

    /** @param mixed $info RemoteStore::info() array or null */
    private static function remoteState(string $id, $info, string $uploading): string
    {
        if ($id === $uploading && $uploading !== '') {
            return 'uploading';
        }
        if (!is_array($info)) {
            return 'none';
        }
        return ($info['verified'] ?? null) === true ? 'verified' : 'uploaded';
    }
}
