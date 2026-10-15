<?php
/**
 * Plugin Name:       dosya Backup
 * Plugin URI:        https://dosya.dev/integration/wordpress/
 * Description:       Back up, restore and migrate your site. Restore is free at any size.
 * Version:           0.3.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            dosya
 * Author URI:        https://dosya.dev/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dosya-backup-restore-migration
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DOSYA_BACKUP_VERSION', '0.3.1');
define('DOSYA_BACKUP_FILE', __FILE__);
define('DOSYA_BACKUP_DIR', __DIR__);

require_once __DIR__ . '/src/Autoloader.php';
\Dosya\Backup\Autoloader::register(__DIR__ . '/src');

if (is_file(__DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php')) {
    require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
}

\Dosya\Backup\Wp\Plugin::boot();
