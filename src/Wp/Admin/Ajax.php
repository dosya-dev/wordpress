<?php
namespace Dosya\Backup\Wp\Admin;

use Dosya\Backup\Jobs\LocalStore;
use Dosya\Backup\Jobs\StepRunner;
use Dosya\Backup\Support\Budget;
use Dosya\Backup\Wp\Admin\ViewModel\RestoreDialog;
use Dosya\Backup\Wp\Mailer;
use Dosya\Backup\Wp\Plugin;
use Dosya\Backup\Wp\Settings;
use Dosya\Backup\Wp\Scheduler;

final class Ajax
{
    public const NONCE = 'dosya_backup_admin';

    public static function register(): void
    {
        foreach (['list', 'start_backup', 'step', 'status', 'cancel', 'start_restore', 'upload_chunk', 'upload_finish', 'save_settings', 'disconnect', 'delete', 'dismiss_notice', 'undo_restore', 'restore_dialog'] as $a) {
            add_action('wp_ajax_dosya_' . $a, [self::class, 'handle']);
        }
    }

    public static function handle(): void
    {
        if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '') !== 'POST') {
            wp_send_json_error(['message' => __('Use a POST request.', 'dosya-backup-restore-migration')], 405);
        }
        if (check_ajax_referer(self::NONCE, 'nonce', false) === false) {
            // a JSON answer the page can recognise; a restore re-issues the login cookie, which retires the page's nonce
            wp_send_json_error(['message' => __('Session expired', 'dosya-backup-restore-migration')], 401);
        }
        if (!current_user_can(Plugin::capability())) {
            wp_send_json_error(['message' => __('You are not allowed to do this.', 'dosya-backup-restore-migration')], 403);
        }
        $action = substr(sanitize_key(wp_unslash($_REQUEST['action'] ?? '')), strlen('dosya_'));
        try {
            wp_send_json_success(self::dispatch($action));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
    }

    private static function field(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- only reached from handle(), after check_ajax_referer()
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    }

    /**
     * Free text the user typed (a note, a domain): tags and control characters removed, trimmed, at most $max characters.
     * Not sanitize_text_field, which turns a lone "<" into "&lt;", so "size < 5MB" would come back changed; the text is
     * stored as is and escaped wherever it is shown.
     */
    private static function text(string $key, int $max): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- sanitized right here (tags and control characters stripped); only reached from handle(), after check_ajax_referer()
        $raw = isset($_POST[$key]) && is_string($_POST[$key]) ? wp_strip_all_tags(wp_unslash($_POST[$key])) : '';
        $raw = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw));
        return function_exists('mb_substr') ? mb_substr($raw, 0, $max) : substr($raw, 0, $max);
    }

    private static function dispatch(string $action): array
    {
        switch ($action) {
            case 'list':
                return ListAssembler::build((int) self::field('page'));
            case 'start_backup':
                $note = self::text('note', 80);
                return ['job_id' => Plugin::startBackup(in_array(self::field('scope'), ['full', 'db', 'files'], true) ? self::field('scope') : 'full', true, $note, 'manual')];
            case 'delete':
                if (Plugin::jobs()->active() !== null) {
                    throw new \RuntimeException(esc_html__('A backup or restore is running.', 'dosya-backup-restore-migration'));
                }
                $delId = sanitize_key(self::field('backup_id'));
                $known = false;
                foreach (Plugin::store()->list() as $b) {
                    if ($b['id'] === $delId) {
                        $known = true;
                    }
                }
                if (!$known) {
                    throw new \InvalidArgumentException(esc_html__('No backup with that id.', 'dosya-backup-restore-migration'));
                }
                $deps = count(Plugin::store()->dependents($delId));
                if ($deps > 0) {
                    /* translators: %d: number of newer backups that build on this one. */
                    throw new \InvalidArgumentException(esc_html(sprintf(_n('%d newer backup builds on this one. Delete that backup first.', '%d newer backups build on this one. Delete those backups first.', $deps, 'dosya-backup-restore-migration'), $deps)));
                }
                Plugin::store()->delete($delId);
                return ['deleted' => true];
            case 'dismiss_notice':
                $notice = self::field('notice');
                if (!in_array($notice, ['failed', 'connection', 'storage'], true)) {
                    throw new \InvalidArgumentException(esc_html__('Unknown notice.', 'dosya-backup-restore-migration'));
                }
                update_user_meta(get_current_user_id(), 'dosya_backup_dismissed_' . $notice, substr(sanitize_key(self::field('value')), 0, 32));
                return ['ok' => true];
            case 'undo_restore':
                $snapshotId = sanitize_key(self::field('snapshot_id'));
                $found = false;
                foreach (Plugin::store()->list() as $b) {
                    if ($b['id'] === $snapshotId && $b['label'] === LocalStore::SNAPSHOT_LABEL) {
                        $found = true;
                    }
                }
                if (!$found) {
                    throw new \InvalidArgumentException(esc_html__('That safety copy no longer exists.', 'dosya-backup-restore-migration'));
                }
                return ['job_id' => Plugin::startRestore($snapshotId, ['scope' => 'db', 'snapshot' => false], true)];
            case 'step':
                // several steps per request: the short steps after the database swap finish in the same request that re-issued the login cookie
                $budget = Budget::forRequest();
                $runner = Plugin::runner();
                do {
                    $r = $runner->step(self::field('job_id'), $budget);
                } while ($r['state'] !== 'busy' && !StepRunner::isTerminal($r['state']) && !$budget->exhausted());
                if (StepRunner::isTerminal($r['state'])) {
                    Mailer::afterStep($r);
                }
                return ListAssembler::decorate($r);
            case 'status':
                return ListAssembler::decorate(ListAssembler::status(self::field('job_id')));
            case 'cancel':
                return ['cancelled' => Plugin::jobs()->cancel(self::field('job_id'))];
            case 'restore_dialog':
                $dlg = self::restoreDialog(sanitize_key(self::field('backup_id')));
                return $dlg['vm'] + ['backup_id' => $dlg['row']['id'], 'scope' => $dlg['scope']];
            case 'start_restore':
                $backupId = sanitize_key(self::field('backup_id'));
                $dlg = self::restoreDialog($backupId);
                $opts = RestoreRequest::validate($dlg['vm'], [
                    'confirm' => self::field('confirm'),
                    'confirm_domain' => self::text('confirm_domain', 253),
                    'scope' => self::field('scope'),
                    'new_url' => esc_url_raw(self::field('new_url')),
                ], $dlg['domain']);
                if ($opts === 'confirm') {
                    throw new \InvalidArgumentException(esc_html__('Please confirm that this replaces the current site.', 'dosya-backup-restore-migration'));
                }
                if ($opts === 'domain') {
                    throw new \InvalidArgumentException(esc_html__("Type the current site's domain to confirm.", 'dosya-backup-restore-migration'));
                }
                // the safety copy follows the Schedule tab setting; a request can never switch it off
                $opts['snapshot'] = (bool) Settings::get()['safety_copy'];
                return ['job_id' => Plugin::startRestore($backupId, $opts)];
            case 'upload_chunk':
                // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in handle(); tmp_name is written by PHP and accepted only when is_uploaded_file() agrees
                $tmp = isset($_FILES['chunk']['tmp_name']) && is_uploaded_file($_FILES['chunk']['tmp_name']) ? (string) $_FILES['chunk']['tmp_name'] : '';
                if ($tmp === '') {
                    throw new \InvalidArgumentException(esc_html__('No data received.', 'dosya-backup-restore-migration'));
                }
                return (new ChunkUpload(Plugin::store()))->receive(sanitize_key(self::field('upload_id')), self::field('name'),
                    (int) self::field('offset'), (int) self::field('total'), $tmp);
            case 'upload_finish':
                return ['backup_id' => (new ChunkUpload(Plugin::store()))->finish(sanitize_key(self::field('upload_id')))];
            case 'disconnect':
                Plugin::connector()->disconnect();
                return ['ok' => true];
            case 'save_settings':
                $in = [];
                foreach (['schedule', 'time', 'keep', 'volume_mb', 'safety_copy', 'email_fail', 'email_ok'] as $k) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in handle()
                    if (isset($_POST[$k])) {
                        $in[$k] = self::field($k);
                    }
                }
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in handle()
                if (isset($_POST['excludes'])) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in handle()
                    $in['excludes'] = preg_split('/\r\n|\n/', sanitize_textarea_field(wp_unslash($_POST['excludes']))) ?: [];
                }
                $saved = Settings::save($in);
                Scheduler::sync($saved);
                return ['settings' => $saved] + AdminPage::nextRun($saved);
        }
        throw new \InvalidArgumentException('Unknown action');
    }

    /**
     * The restore dialog view model for a stored backup, recomputed on every request so the server applies the same rules
     * the dialog showed.
     *
     * @return array{vm:array<string,mixed>,row:array<string,mixed>,domain:string,scope:string}
     */
    private static function restoreDialog(string $backupId): array
    {
        $row = null;
        foreach (Plugin::store()->list() as $b) {
            if ($backupId !== '' && $b['id'] === $backupId) {
                $row = $b;
                break;
            }
        }
        if ($row === null) {
            throw new \InvalidArgumentException(esc_html__('No backup with that id. Reload the page to see the current list.', 'dosya-backup-restore-migration'));
        }
        $info = Plugin::remoteStore()->info($row['id']);
        $manifestHome = null;
        if ($row['origin'] === null) {
            // backups made before the sidecar kept the origin: the manifest names the site they came from
            try {
                $home = Plugin::store()->load($row['id'])->meta['site']['home'] ?? null;
                $manifestHome = is_string($home) ? $home : null;
            } catch (\Throwable $e) {
                $manifestHome = null;
            }
        }
        $input = RestoreRequest::dialogInput($row, is_array($info) ? $info : null, $manifestHome);
        $home = home_url();
        $domain = (string) wp_parse_url($home, PHP_URL_HOST);
        $vm = RestoreDialog::build($input, [
            'now' => time(),
            'tz' => wp_timezone_string(),
            'home_url' => $home,
            'domain' => $domain,
            'safety_copy' => !empty(Settings::get()['safety_copy']),
            'fmt' => static function (int $ts, string $pattern): string { return (string) wp_date($pattern, $ts); },
        ], I18nCatalog::translator());
        return ['vm' => $vm, 'row' => $row, 'domain' => $domain, 'scope' => (string) $input['scope']];
    }
}
