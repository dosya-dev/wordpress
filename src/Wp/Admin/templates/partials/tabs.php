<?php
/**
 * Tab bar. $vm: tabs [{name, label, url, active}], busy (bool).
 *
 * @package dosya-backup
 */

defined('ABSPATH') || exit;
?>
<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('dosya Backup sections', 'dosya-backup-restore-migration'); ?>">
    <?php foreach ($vm['tabs'] as $dosya_backup_tab) : ?>
        <a class="nav-tab<?php echo $dosya_backup_tab['active'] ? ' nav-tab-active' : ''; ?>"
            href="<?php echo esc_url($dosya_backup_tab['url']); ?>"
            data-dosya-tab="<?php echo esc_attr($dosya_backup_tab['name']); ?>"
            <?php if ($dosya_backup_tab['active']) : ?>aria-current="page"<?php elseif ($vm['busy']) : ?>aria-disabled="true" title="<?php esc_attr_e('A backup or restore is running', 'dosya-backup-restore-migration'); ?>"<?php endif; ?>><?php echo esc_html($dosya_backup_tab['label']); ?></a>
    <?php endforeach; ?>
</nav>
