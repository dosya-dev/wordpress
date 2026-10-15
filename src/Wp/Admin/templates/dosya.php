<?php
/**
 * dosya tab. $vm: ConnectCard::build() output plus just_connected, connect_error, unencrypted, connect_url, open_url, excludes_url.
 *
 * @package dosya-backup
 */

namespace Dosya\Backup\Wp\Admin;

defined('ABSPATH') || exit;
?>
<?php if ($vm['just_connected']) : ?>
    <div class="notice notice-success inline"><p><?php esc_html_e('Connected to dosya.', 'dosya-backup-restore-migration'); ?></p></div>
<?php endif; ?>
<?php if ($vm['connect_error'] !== '') : ?>
    <div class="notice notice-error inline"><p><?php echo esc_html($vm['connect_error']); ?></p></div>
<?php endif; ?>
<?php
Template::render('partials/connect-card', $vm);
