<?php
namespace Dosya\Backup\Wp\Admin;

use Dosya\Backup\Wp\Plugin;
use Dosya\Backup\Wp\Settings;
use Dosya\Backup\Wp\SiteKeyStore;

/**
 * Site-wide admin notices, only while a real problem exists: at most one, only for users who manage backups,
 * never on the plugin's own page, and dismissible per user until the problem changes.
 */
final class Notices
{
    public const DISMISS_META = 'dosya_backup_dismissed_';
    public const LOST_AT_OPTION = 'dosya_backup_connection_lost_at';
    private const CAUSE_MAX = 160;

    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render']);
        add_action('network_admin_notices', [self::class, 'render']);
    }

    public static function render(): void
    {
        if (!current_user_can(Plugin::capability())) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen !== null && in_array($screen->id, ['toplevel_page_' . AdminPage::SLUG, 'toplevel_page_' . AdminPage::SLUG . '-network'], true)) {
            return;
        }
        $notice = self::current();
        if ($notice === null) {
            return;
        }
        if ((string) get_user_meta(get_current_user_id(), self::DISMISS_META . $notice['key'], true) === $notice['value']) {
            return;
        }
        $attributes = ['data-dosya-notice' => $notice['key'], 'data-dosya-value' => $notice['value']];
        if (function_exists('wp_admin_notice')) {
            wp_admin_notice($notice['message'], [
                'type' => $notice['type'],
                'dismissible' => true,
                'additional_classes' => ['dosya-notice'],
                'attributes' => $attributes,
            ]);
        } else {
            printf(
                '<div class="notice notice-%1$s is-dismissible dosya-notice" data-dosya-notice="%2$s" data-dosya-value="%3$s"><p>%4$s</p></div>',
                esc_attr($notice['type']),
                esc_attr($notice['key']),
                esc_attr($notice['value']),
                wp_kses_post($notice['message'])
            );
        }
        self::enqueueDismiss();
    }

    /** @return array{key:string,value:string,type:string,message:string}|null */
    private static function current(): ?array
    {
        try {
            // the same job the status card shows: a failed backup stays until a later backup is done
            $last = ListPayload::selectLastJob(array_merge(Plugin::jobs()->recent('backup', 20), Plugin::jobs()->recent('restore', 5)));
        } catch (\Throwable $e) {
            $last = null;
        }
        if ($last !== null && $last['kind'] === 'backup' && $last['state'] === 'failed') {
            $cause = rtrim(trim((string) $last['error']), '. ');
            if ($cause === '') {
                $cause = __('unknown error', 'dosya-backup-restore-migration');
            } elseif (function_exists('mb_strlen') && mb_strlen($cause) > self::CAUSE_MAX) {
                $cause = mb_substr($cause, 0, self::CAUSE_MAX) . '...';
            }
            $link = '<a href="' . esc_url(Connect::pageUrl()) . '">' . esc_html__('Open dosya Backup', 'dosya-backup-restore-migration') . '</a>';
            if (self::isUploadFailure($last)) {
                // the backup was written here and only its upload failed: a warning, as the status card's amber
                $message = sprintf(
                    /* translators: 1: why the upload to dosya failed, 2: link to the plugin page. */
                    esc_html__('Your last backup is on this server but the upload to dosya failed: %1$s. %2$s', 'dosya-backup-restore-migration'),
                    esc_html($cause),
                    $link
                );
                return ['key' => 'failed', 'value' => (string) $last['id'], 'type' => 'warning', 'message' => $message];
            }
            $message = sprintf(
                /* translators: 1: why the backup failed, 2: link to the plugin page. */
                esc_html__('Your last backup failed: %1$s. %2$s', 'dosya-backup-restore-migration'),
                esc_html($cause),
                $link
            );
            return ['key' => 'failed', 'value' => (string) $last['id'], 'type' => 'error', 'message' => $message];
        }
        $keyState = (new SiteKeyStore())->state();
        if ($keyState === 'ok' && (int) Settings::read(self::LOST_AT_OPTION, 0) > 0) {
            // the key became readable again without a reconnect (the security keys were put back): a later loss is a new one
            self::forgetLostAt();
        }
        if ($keyState === 'unreadable') {
            // the dismissal is tied to when this loss started, so a later loss after a reconnect shows again
            $lostAt = (int) Settings::read(self::LOST_AT_OPTION, 0);
            if ($lostAt <= 0) {
                $lostAt = time();
                Settings::write(self::LOST_AT_OPTION, $lostAt);
            }
            $message = sprintf(
                /* translators: %s: link to the dosya tab of the plugin page. */
                esc_html__('dosya Backup lost its connection to dosya, so uploads are paused. Backups still run on this server. %s', 'dosya-backup-restore-migration'),
                '<a href="' . esc_url(Connect::pageUrl(['tab' => 'dosya'])) . '">' . esc_html__('Connect again', 'dosya-backup-restore-migration') . '</a>'
            );
            return ['key' => 'connection', 'value' => (string) $lostAt, 'type' => 'warning', 'message' => $message];
        }
        // storage nearly full: no storage figures reach the plugin yet
        return null;
    }

    /** The failed backup job wrote the newest stored backup (its cursor id), so only the upload failed. */
    private static function isUploadFailure(array $job): bool
    {
        $backupId = is_array($job['cursor']) && isset($job['cursor']['id']) ? (string) $job['cursor']['id'] : '';
        if ($backupId === '') {
            return false;
        }
        try {
            $newest = ListPayload::newestBackup(Plugin::store()->list(), [], null);
        } catch (\Throwable $e) {
            return false;
        }
        return $newest !== null && $newest['id'] === $backupId;
    }

    /** Called after a successful connect: forgets when the last loss started and every user's dismissal of it. */
    public static function clearConnectionLost(): void
    {
        self::forgetLostAt();
        delete_metadata('user', 0, self::DISMISS_META . 'connection', '', true);
    }

    private static function forgetLostAt(): void
    {
        if (is_multisite()) {
            delete_site_option(self::LOST_AT_OPTION);
        } else {
            delete_option(self::LOST_AT_OPTION);
        }
    }

    /** A tiny script, only on pages that show a notice, that remembers the dismissal for this user. */
    private static function enqueueDismiss(): void
    {
        $config = wp_json_encode(['ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce(Ajax::NONCE)]);
        $js = 'window.dosyaBackupNotice = ' . $config . ';'
            . 'document.addEventListener("click", function (e) {'
            . 'var b = e.target && e.target.closest ? e.target.closest(".dosya-notice .notice-dismiss") : null;'
            . 'var c = window.dosyaBackupNotice; if (!b || !c) { return; }'
            . 'var n = b.closest(".dosya-notice"); var f = new FormData();'
            . 'f.append("action", "dosya_dismiss_notice"); f.append("nonce", c.nonce);'
            . 'f.append("notice", n.getAttribute("data-dosya-notice") || ""); f.append("value", n.getAttribute("data-dosya-value") || "");'
            . 'fetch(c.ajaxUrl, { method: "POST", credentials: "same-origin", body: f });'
            . '});';
        wp_register_script('dosya-backup-notice', false, [], DOSYA_BACKUP_VERSION, true);
        wp_enqueue_script('dosya-backup-notice');
        wp_add_inline_script('dosya-backup-notice', $js);
    }
}
