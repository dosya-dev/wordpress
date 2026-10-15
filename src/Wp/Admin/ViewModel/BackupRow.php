<?php
namespace Dosya\Backup\Wp\Admin\ViewModel;

/**
 * Turns one backup list row into the texts, badges and allowed actions the table shows.
 * No WordPress calls; $t is the injected translator.
 */
final class BackupRow
{
    /**
     * @param array    $row Backup list row plus note, origin, remote_state, locked_until, failed_reason, optional upload_failed_reason
     *                      (the backup is stored here but its upload to dosya failed), is_safety, is_uploaded.
     * @param array    $ctx now, tz, dependents, optional fmt (fn(int $ts, string $pattern): string).
     * @param callable $t   Translator.
     */
    public static function build(array $row, array $ctx, callable $t): array
    {
        $now = (int) $ctx['now'];
        $tz = (string) $ctx['tz'];
        $fmt = isset($ctx['fmt']) && is_callable($ctx['fmt']) ? $ctx['fmt'] : null;
        $label = (string) ($row['label'] ?? '');
        $isSafety = !empty($row['is_safety']) || $label === 'pre-restore';

        if ($isSafety) {
            $type = $t('Safety copy');
        } elseif (!empty($row['is_uploaded'])) {
            $type = $t('Uploaded');
        } elseif ($label === 'manual') {
            $type = $t('Manual');
        } else {
            $type = $t('Scheduled');
        }

        $scope = $row['scope'] ?? 'full';
        if ($scope === 'db') {
            $contents = $t('Database');
        } elseif ($scope === 'files') {
            $contents = $t('Files');
        } else {
            $contents = $t('Database + files');
        }

        $hint = '';
        if ($isSafety) {
            $hint = $t('made before a restore');
        } elseif (($row['kind'] ?? 'full') === 'incremental') {
            /* translators: %s: size of the data this incremental backup added, e.g. "38 MB". */
            $hint = sprintf($t('incremental, %s new'), Format::bytes((int) ($row['bytes_new'] ?? 0)));
        }

        $badges = [];
        $failed = isset($row['failed_reason']) && is_string($row['failed_reason']) && $row['failed_reason'] !== '';
        if ($failed) {
            /* translators: %s: the reason the backup failed. */
            $badges[] = ['kind' => 'failed', 'text' => sprintf($t('Failed · %s'), (string) $row['failed_reason']), 'title' => ''];
        } else {
            $rs = $row['remote_state'] ?? 'none';
            if ($rs === 'verified') {
                $badges[] = ['kind' => 'dosya-verified', 'text' => $t('dosya · verified'), 'title' => $t('dosya confirmed every volume of this backup.')];
            } elseif ($rs === 'uploading') {
                $badges[] = ['kind' => 'dosya-uploading', 'text' => $t('dosya · uploading'), 'title' => ''];
            } elseif ($rs === 'uploaded') {
                $badges[] = ['kind' => 'dosya-unverified', 'text' => $t('dosya · not verified'), 'title' => $t('Uploaded; dosya has not confirmed the volumes yet.')];
            } else {
                $badges[] = ['kind' => 'local', 'text' => $t('local'), 'title' => $t('Stored on this server only.')];
            }
            $uploadFailed = isset($row['upload_failed_reason']) && is_string($row['upload_failed_reason']) ? trim($row['upload_failed_reason']) : '';
            if ($uploadFailed !== '') {
                $badges[] = ['kind' => 'dosya-upload-failed', 'text' => $t('dosya · upload failed'), 'title' => $uploadFailed];
            }
            if ($rs !== 'none' && !empty($row['locked_until']) && (int) $row['locked_until'] > $now) {
                $until = Format::when((int) $row['locked_until'], $now, $tz, $t, false, $fmt);
                /* translators: %s: when the lock ends, e.g. "tomorrow 09:00" or "14 Oct 09:00". */
                $badges[] = ['kind' => 'lock', 'text' => sprintf($t('🔒 until %s'), $until),
                    /* translators: %s: when the lock ends, e.g. "tomorrow 09:00" or "14 Oct 09:00". */
                    'title' => sprintf($t('Protected in dosya until %s; this site\'s key cannot delete or change it.'), $until)];
            }
        }

        $dep = (int) ($ctx['dependents'] ?? 0);
        $refusal = null;
        if ($dep === 1) {
            /* translators: %d: number of newer backups that depend on this one (here exactly 1). */
            $refusal = sprintf($t('This backup is the base of %d newer backup. Delete it first.'), $dep);
        } elseif ($dep > 1) {
            /* translators: %d: number of newer backups that depend on this one. */
            $refusal = sprintf($t('This backup is the base of %d newer backups. Delete those first.'), $dep);
        }

        $created = (int) $row['created'];
        return [
            'id' => $row['id'],
            'created_text' => Format::when($created, $now, $tz, $t, true, $fmt),
            'created_rel' => Format::relative($created, $now, $t),
            'note' => (string) ($row['note'] ?? ''),
            'type_text' => $type,
            'contents_text' => $contents,
            'contents_hint' => $hint,
            'size_text' => $failed ? '' : Format::bytes((int) ($row['size'] ?? 0)),
            'badges' => $badges,
            'actions' => ['restore' => !$failed, 'download' => !$failed, 'log' => true, 'delete' => $refusal === null],
            'delete_refusal' => $refusal,
        ];
    }
}
