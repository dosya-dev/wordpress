<?php
namespace Dosya\Backup\Wp\Admin;

use Dosya\Backup\Wp\ConnectException;
use Dosya\Backup\Wp\Plugin;

/** Entry points of the connect flow: start (to the dosya consent page) and callback (back from it). */
final class Connect
{
    public const MESSAGE_TRANSIENT = 'dosya_backup_connect_msg_';
    public const START_NONCE = 'dosya_connect_start';

    public static function register(): void
    {
        add_action('admin_post_dosya_connect_start', [self::class, 'start']);
        add_action('admin_post_dosya_connect_callback', [self::class, 'callback']);
    }

    public static function startUrl(): string
    {
        return wp_nonce_url(admin_url('admin-post.php?action=dosya_connect_start'), self::START_NONCE);
    }

    public static function pageUrl(array $args = []): string
    {
        $base = is_multisite() ? network_admin_url('admin.php') : admin_url('admin.php');
        return add_query_arg(array_merge(['page' => AdminPage::SLUG], $args), $base);
    }

    public static function start(): void
    {
        if (!current_user_can(Plugin::capability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'dosya-backup-restore-migration'), '', ['response' => 403]);
        }
        check_admin_referer(self::START_NONCE);
        $url = Plugin::connector()->begin();
        if (strpos($url, Plugin::webBase() . '/') !== 0) {
            wp_die(esc_html__('Could not start connecting to dosya.', 'dosya-backup-restore-migration'), '', ['response' => 500]);
        }
        // external origin on purpose: wp_safe_redirect would refuse it
        wp_redirect($url); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit;
    }

    public static function callback(): void
    {
        if (!current_user_can(Plugin::capability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'dosya-backup-restore-migration'), '', ['response' => 403]);
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the visitor comes back from dosya.dev; the single-use state in a transient is the CSRF check
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        // phpcs:enable
        if ($error !== '') {
            if ($error === 'cancelled') {
                Plugin::connector()->abort($state);
                self::back(['connect_error' => 'cancelled']);
            }
            self::back(['connect_error' => 'exchange']);
        }
        if ($code === '' || $state === '') {
            self::back(['connect_error' => 'expired']);
        }
        try {
            Plugin::connector()->complete($code, $state);
        } catch (ConnectException $e) {
            if ($e->reason === 'plan_cap' || $e->reason === 'exchange') {
                // the server's sentence travels in a short per-user transient, never in the URL
                set_transient(self::MESSAGE_TRANSIENT . get_current_user_id(), $e->getMessage(), 60);
            }
            self::back(['connect_error' => $e->reason]);
        }
        self::back(['connected' => '1']);
    }

    private static function back(array $args): void
    {
        wp_safe_redirect(self::pageUrl($args));
        exit;
    }
}
