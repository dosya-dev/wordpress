<?php
namespace Dosya\Backup\Wp\Admin;

use Dosya\Backup\Wp\Plugin;

final class Download
{
    public const NONCE = 'dosya_backup_download';

    public static function register(): void
    {
        add_action('admin_post_dosya_download', [self::class, 'handle']);
    }

    public static function url(string $id, string $file): string
    {
        // raw '&' (wp_nonce_url would HTML-escape it, which breaks the URL once it is JSON-encoded for the tab scripts)
        return add_query_arg(['action' => 'dosya_download', 'id' => $id, 'file' => $file, '_wpnonce' => wp_create_nonce(self::NONCE)], admin_url('admin-post.php'));
    }

    public static function handle(): void
    {
        if (!current_user_can(Plugin::capability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'dosya-backup-restore-migration'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE);
        $id = isset($_GET['id']) ? sanitize_key(wp_unslash($_GET['id'])) : '';
        $file = isset($_GET['file']) ? sanitize_text_field(wp_unslash($_GET['file'])) : '';
        $store = Plugin::store();
        // sanitize_file_name() would turn manifest.json.gz into manifest.json_.gz; the patterns below are the allowlist
        $path = null;
        try {
            if (preg_match('/^v(\d{4})\.dosya$/', $file, $m) === 1) {
                $path = $store->volumePath($id, (int) $m[1]);
            } elseif ($file === 'manifest.json.gz') {
                $path = $store->manifestPath($id);
            } elseif ($file === 'manifest.public.json') {
                $path = $store->publicManifestPath($id);
            }
        } catch (\InvalidArgumentException $e) {
            $path = null;
        }
        if ($path === null || !is_file($path)) {
            wp_die(esc_html__('That backup file does not exist.', 'dosya-backup-restore-migration'), '', ['response' => 404]);
        }
        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . $file . '"');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $fh = fopen($path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streams a binary download
        fpassthru($fh);
        fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the download stream
        exit;
    }
}
