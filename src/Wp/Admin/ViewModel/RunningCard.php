<?php
namespace Dosya\Backup\Wp\Admin\ViewModel;

/**
 * Phase map and copy for a running backup or restore. No WordPress calls.
 */
final class RunningCard
{
    /** Phase path to i18n key. The JS mirrors this map. */
    public const PHASES = [
        'backup.queued' => 'phase_preparing',
        'backup.db_dump' => 'phase_backup_database',
        'backup.file_scan' => 'phase_backup_scanning',
        'backup.archive' => 'phase_backup_files',
        'backup.finalize' => 'phase_finishing',
        'backup.remote' => 'phase_registering',
        'backup.skipped' => 'phase_done',
        'backup.done' => 'phase_done',
        'backup.finalize.seal' => 'phase_finishing',
        'backup.finalize.hash' => 'phase_finishing',
        'backup.finalize.write' => 'phase_finishing',
        'backup.finalize.remote' => 'phase_finishing',
        'backup.remote.register' => 'phase_remote_register',
        'backup.remote.volumes' => 'phase_remote_volumes',
        'backup.remote.manifests' => 'phase_remote_manifests',
        'backup.remote.complete' => 'phase_remote_complete',
        'restore.queued' => 'phase_preparing',
        'restore.snapshot' => 'phase_restore_snapshot',
        'restore.files_fetch' => 'phase_restore_fetch',
        'restore.db_import' => 'phase_restore_database',
        'restore.db_transform' => 'phase_restore_transform',
        'restore.files_move' => 'phase_restore_files',
        'restore.db_swap' => 'phase_restore_swap',
        'restore.finalize' => 'phase_finishing',
        'restore.health' => 'phase_restore_health',
        'restore.cleanup' => 'phase_restore_cleanup',
        'restore.done' => 'phase_done',
    ];

    /** i18n key to English sentence. */
    public const PHASE_TEXT = [
        'phase_preparing' => 'Preparing',
        'phase_working' => 'Working',
        'phase_done' => 'Finishing',
        'phase_registering' => 'Registering with dosya',
        'phase_backup_database' => 'Database',
        'phase_backup_scanning' => 'Scanning',
        'phase_backup_files' => 'Files',
        'phase_finishing' => 'Finishing',
        'phase_remote_register' => 'Registering with dosya',
        'phase_remote_volumes' => 'Uploading to dosya, volume %1$d of %2$d',
        'phase_remote_manifests' => 'Uploading manifests',
        'phase_remote_complete' => 'Finishing the upload to dosya',
        'phase_restore_snapshot' => 'Making a safety copy',
        'phase_restore_fetch' => 'Reading the backup',
        'phase_restore_database' => 'Database',
        'phase_restore_transform' => 'Updating links and paths',
        'phase_restore_files' => 'Files',
        'phase_restore_swap' => 'Switching to the restored database',
        'phase_restore_health' => 'Checking the site',
        'phase_restore_cleanup' => 'Cleaning up',
    ];

    /**
     * @param array<string,mixed> $status
     * @param array<string,mixed>|null $ctx optional backup_created, now, tz for the restore heading
     * @return array<string,mixed>
     */
    public static function build(array $status, callable $t, ?array $ctx = null): array
    {
        $kind = ($status['kind'] ?? 'backup') === 'restore' ? 'restore' : 'backup';
        $p = isset($status['progress']) && is_array($status['progress']) ? $status['progress'] : [];
        $phase = (string) ($p['phase'] ?? 'queued');
        $step = (string) ($p['step'] ?? '');

        $path = $kind . '.' . $phase;
        if ($step !== '' && isset(self::PHASES[$path . '.' . $step])) {
            $path .= '.' . $step;
        }
        $key = self::PHASES[$path] ?? 'phase_working';
        if ($phase === 'queued') {
            $key = 'phase_preparing';
        }

        $text = $t(self::PHASE_TEXT[$key]);
        $detail = '';
        $percent = null;
        $int = static function ($v): int { return (int) $v; };

        if ($key === 'phase_remote_volumes') {
            $vol = $int($p['volume'] ?? 0);
            $vols = $int($p['volumes'] ?? 0);
            $text = sprintf($text, $vol, $vols);
            if ($vols > 0) {
                $percent = max(0, min(100, (int) round((max($vol, 1) - 1) / $vols * 100)));
            }
        } elseif ($kind === 'backup' && $phase === 'db_dump') {
            $done = $int($p['tables_done'] ?? 0);
            $total = $int($p['tables'] ?? 0);
            /* translators: 1: tables done, 2: total tables. */
            $detail = sprintf($t('%1$d of %2$d tables'), $done, $total);
            $percent = $total > 0 ? self::pct($done, $total) : null;
        } elseif ($kind === 'backup' && $phase === 'file_scan') {
            /* translators: 1: number of files, 2: size such as 1.2 GB. */
            $n = $int($p['files'] ?? 0);
            $detail = sprintf($n === 1 ? $t('%1$d file, %2$s') : $t('%1$d files, %2$s'), $n, Format::bytes($int($p['bytes'] ?? 0)));
        } elseif ($kind === 'backup' && $phase === 'archive') {
            $done = $int($p['bytes_done'] ?? 0);
            $total = $int($p['bytes_total'] ?? 0);
            /* translators: 1: size done, 2: total size. */
            $detail = sprintf($t('%1$s of %2$s'), Format::bytes($done), Format::bytes($total));
            $percent = $total > 0 ? self::pct($done, $total) : null;
        } elseif ($kind === 'restore' && ($phase === 'files_fetch' || $phase === 'db_import')) {
            $done = $int($p['done'] ?? 0);
            $total = $int($p['total'] ?? 0);
            /* translators: 1: items done, 2: total items. */
            $detail = sprintf($t('%1$d of %2$d'), $done, $total);
            $percent = $total > 0 ? self::pct($done, $total) : null;
        }

        if ($kind === 'restore') {
            if ($ctx !== null && isset($ctx['backup_created'])) {
                $when = Format::when((int) $ctx['backup_created'], (int) ($ctx['now'] ?? 0), (string) ($ctx['tz'] ?? 'UTC'), $t, false);
                /* translators: %s: date and time of the backup being restored. */
                $heading = sprintf($t('Restoring the backup from %s'), $when);
            } else {
                $heading = $t('Restoring the backup');
            }
        } elseif ($phase === 'remote') {
            $heading = $t('Uploading to dosya');
        } else {
            $heading = $t('Backing up this site');
        }

        return [
            'heading' => $heading,
            'phase_key' => $key,
            'phase_text' => $text,
            'percent' => $percent,
            'detail' => $detail,
            'maintenance' => $kind === 'restore' && !in_array($phase, ['queued', 'done'], true),
        ];
    }

    private static function pct(int $done, int $total): int
    {
        return max(0, min(100, (int) round($done / $total * 100)));
    }
}
