<?php
namespace Dosya\Backup\Wp;

use Dosya\Backup\Jobs\LocalStore;

final class Settings
{
    public const OPTION = 'dosya_backup_settings';
    public const FINGERPRINT_OPTION = 'dosya_backup_fingerprint';
    public const API_BASE_OPTION = 'dosya_backup_api_base';
    public const DIR_OPTION = 'dosya_backup_local_dir';

    public static function get(): array
    {
        $v = self::read(self::OPTION, []);
        return array_merge(SettingsSchema::DEFAULTS, is_array($v) ? $v : []);
    }

    public static function save(array $in): array
    {
        $saved = SettingsSchema::normalize($in, self::get());
        self::write(self::OPTION, $saved);
        return $saved;
    }

    /** Network-level on multisite, like the jobs and the capability. */
    public static function read(string $name, $default)
    {
        return is_multisite() ? get_site_option($name, $default) : get_option($name, $default);
    }

    public static function write(string $name, $value): void
    {
        if (is_multisite()) {
            update_site_option($name, $value);
        } else {
            update_option($name, $value, false);
        }
    }

    /** Chosen once and remembered, so web requests and WP-CLI always agree on the folder. */
    public static function localDir(): string
    {
        $dir = self::read(self::DIR_OPTION, '');
        if (is_string($dir) && $dir !== '') {
            return $dir;
        }
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? sanitize_text_field(wp_unslash($_SERVER['DOCUMENT_ROOT'])) : '';
        $dir = LocalStore::chooseDir(ABSPATH, WP_CONTENT_DIR, $docRoot, strtolower(wp_generate_password(24, false, false)), 'wp_is_writable');
        self::write(self::DIR_OPTION, $dir);
        return $dir;
    }
}
