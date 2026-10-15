<?php
/**
 * One row of the backup list. $vm: BackupRow::build() output plus downloads [{label, url}], incremental, failed.
 *
 * @package dosya-backup
 */

defined('ABSPATH') || exit;
?>
<tr data-id="<?php echo esc_attr($vm['id']); ?>"<?php echo $vm['failed'] ? ' data-failed="1"' : ''; ?>>
    <td>
        <?php echo esc_html($vm['created_text']); ?><br />
        <span class="dosya-rel"><?php echo esc_html($vm['created_rel']); ?></span>
        <?php if ($vm['note'] !== '') : ?>
            <br /><em class="dosya-note"><?php echo esc_html($vm['note']); ?></em>
        <?php endif; ?>
    </td>
    <td><?php echo esc_html($vm['type_text']); ?></td>
    <?php if ($vm['failed']) : ?>
        <td colspan="2">
            <?php foreach ($vm['badges'] as $dosya_backup_badge) : ?>
                <span class="dosya-badge dosya-badge--<?php echo esc_attr($dosya_backup_badge['kind']); ?>"<?php if ($dosya_backup_badge['title'] !== '') : ?> title="<?php echo esc_attr($dosya_backup_badge['title']); ?>"<?php endif; ?>><?php echo esc_html($dosya_backup_badge['text']); ?></span>
            <?php endforeach; ?>
        </td>
        <td></td>
    <?php else : ?>
        <td>
            <?php echo esc_html($vm['contents_text']); ?>
            <?php if ($vm['contents_hint'] !== '') : ?>
                <br /><span class="dosya-hint"><?php echo esc_html($vm['contents_hint']); ?></span>
            <?php endif; ?>
        </td>
        <td><?php echo esc_html($vm['size_text']); ?></td>
        <td>
            <?php foreach ($vm['badges'] as $dosya_backup_badge) : ?>
                <span class="dosya-badge dosya-badge--<?php echo esc_attr($dosya_backup_badge['kind']); ?>"<?php if ($dosya_backup_badge['title'] !== '') : ?> title="<?php echo esc_attr($dosya_backup_badge['title']); ?>"<?php endif; ?>><?php echo esc_html($dosya_backup_badge['text']); ?></span>
            <?php endforeach; ?>
        </td>
    <?php endif; ?>
    <td class="dosya-actions">
        <?php if (!$vm['failed'] && !empty($vm['actions']['restore'])) : ?>
            <button type="button" class="button" data-dosya="restore" data-id="<?php echo esc_attr($vm['id']); ?>"><?php esc_html_e('Restore', 'dosya-backup-restore-migration'); ?></button>
        <?php endif; ?>
        <details class="dosya-kebab">
            <summary aria-label="<?php esc_attr_e('More actions', 'dosya-backup-restore-migration'); ?>">&#8942;</summary>
            <ul>
                <?php foreach ($vm['downloads'] as $dosya_backup_download) : ?>
                    <li><a href="<?php echo esc_url($dosya_backup_download['url']); ?>" data-dosya="download"<?php echo $vm['incremental'] ? ' data-incremental="1"' : ''; ?>><?php echo esc_html($dosya_backup_download['label']); ?></a></li>
                <?php endforeach; ?>
                <?php if (!empty($vm['actions']['log'])) : ?>
                    <li><button type="button" class="button-link" data-dosya="log" data-id="<?php echo esc_attr($vm['id']); ?>"><?php esc_html_e('View log', 'dosya-backup-restore-migration'); ?></button></li>
                <?php endif; ?>
                <?php if (!$vm['failed']) : ?>
                <li>
                    <?php if (!empty($vm['actions']['delete'])) : ?>
                        <button type="button" class="button-link" data-dosya="delete" data-id="<?php echo esc_attr($vm['id']); ?>"><?php esc_html_e('Delete', 'dosya-backup-restore-migration'); ?></button>
                    <?php else : ?>
                        <button type="button" class="button-link" data-dosya="delete" data-id="<?php echo esc_attr($vm['id']); ?>" disabled="disabled" title="<?php echo esc_attr((string) $vm['delete_refusal']); ?>"><?php esc_html_e('Delete', 'dosya-backup-restore-migration'); ?></button>
                    <?php endif; ?>
                </li>
                <?php endif; ?>
            </ul>
        </details>
    </td>
</tr>
