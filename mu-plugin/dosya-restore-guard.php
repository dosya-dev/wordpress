<?php
/**
 * Plugin Name: dosya restore guard
 * Description: Present only while a dosya Backup restore is running. dosya Backup copies this file into mu-plugins when a restore starts and removes it when the restore ends.
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink -- the guard deletes its own two files once the restore ends; WP_Filesystem is not available this early.

if (!defined('ABSPATH')) {
    exit;
}

/**
 * While a restore runs: only dosya Backup loads, restore step requests skip the theme, visitors get a 503.
 * The lock file holds the deadline on line 1 and the basename of the plugin to keep loaded on line 2.
 */
function dosya_backup_restore_migration_guard(): void
{
    $lock = WP_CONTENT_DIR . '/dosya-restore.lock';
    $lines = is_readable($lock) ? file($lock, FILE_IGNORE_NEW_LINES) : false;
    $until = is_array($lines) ? (int) ($lines[0] ?? 0) : 0;
    $keep = is_array($lines) && isset($lines[1]) && $lines[1] !== '' ? $lines[1] : 'dosya-backup-restore-migration/dosya-backup.php';

    if ($until < time()) {
        // The restore ended or its deadline passed: the guard removes itself and the site runs normally.
        if (is_file($lock)) {
            unlink($lock);
        }
        unlink(__FILE__);
        return;
    }

    // Only dosya Backup loads while files and tables are being replaced.
    add_filter('option_active_plugins', static function ($plugins) use ($keep) {
        return in_array($keep, (array) $plugins, true) ? array($keep) : array();
    }, PHP_INT_MAX);
    add_filter('site_option_active_sitewide_plugins', static function ($plugins) use ($keep) {
        $plugins = (array) $plugins;
        return isset($plugins[$keep]) ? array($keep => $plugins[$keep]) : array();
    }, PHP_INT_MAX);

    // Restore step requests (WP-CLI, or the plugin's own Ajax actions) run without a theme, which may be half-written.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the action name only decides whether a theme loads; the plugin's Ajax handlers verify their own nonces.
    $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
    if ((defined('WP_CLI') && WP_CLI) || strpos($action, 'dosya_') === 0) {
        add_filter('template', static function () { return 'dosya-no-theme'; }, PHP_INT_MAX);
        add_filter('stylesheet', static function () { return 'dosya-no-theme'; }, PHP_INT_MAX);
    }

    // Visitors see a maintenance answer; administrators still reach the dashboard.
    add_action('template_redirect', static function () {
        if (current_user_can('manage_options')) {
            return;
        }
        status_header(503);
        header('Retry-After: 120');
        echo 'This site is being restored. Please try again in a few minutes.';
        exit;
    }, 0);
}

dosya_backup_restore_migration_guard();
