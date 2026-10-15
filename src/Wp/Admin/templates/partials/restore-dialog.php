<?php
/**
 * Restore dialog, opened from a Restore button. $vm: RestoreDialog::build() output; the scripts refill the slots per backup.
 *
 * @package dosya-backup
 */

defined('ABSPATH') || exit;
$dosya_backup_parts = $vm['consequence_parts'];
?>
<dialog class="dosya-dialog" data-dosya="restore-dialog" data-variant="<?php echo esc_attr($vm['variant']); ?>" aria-labelledby="dosya-restore-title">
    <form method="dialog">
        <h2 id="dosya-restore-title" tabindex="-1" data-dosya="restore-title"><?php echo esc_html($vm['title']); ?></h2>
        <p class="dosya-dialog__facts" data-dosya="restore-facts"><?php echo esc_html($vm['facts']); ?></p>
        <p data-dosya="restore-consequence"><?php echo esc_html($dosya_backup_parts['before']); ?><strong data-dosya="restore-domain"><?php echo esc_html($dosya_backup_parts['domain']); ?></strong><?php echo esc_html($dosya_backup_parts['after']); ?></p>
        <ul data-dosya="restore-includes">
            <?php foreach ($vm['includes'] as $dosya_backup_item) : ?>
                <li><?php echo esc_html($dosya_backup_item); ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="dosya-safety" data-dosya="restore-safety"<?php echo $vm['safety'] === null ? ' hidden' : ''; ?>><?php echo esc_html((string) $vm['safety']); ?></p>
        <fieldset data-dosya="restore-scope">
            <legend><?php esc_html_e('What to restore', 'dosya-backup-restore-migration'); ?></legend>
            <label><input type="radio" name="dosya_restore_scope" value="full" checked="checked" /> <?php esc_html_e('Everything', 'dosya-backup-restore-migration'); ?></label>
            <label><input type="radio" name="dosya_restore_scope" value="db" /> <?php esc_html_e('Database only', 'dosya-backup-restore-migration'); ?></label>
            <label><input type="radio" name="dosya_restore_scope" value="files" /> <?php esc_html_e('Files only', 'dosya-backup-restore-migration'); ?></label>
        </fieldset>
        <p data-dosya="restore-url-row"<?php echo $vm['show_address'] ? '' : ' hidden'; ?>>
            <label for="dosya-restore-url" data-dosya="restore-url-label"><?php echo esc_html($vm['labels']['address']); ?></label><br />
            <input type="url" id="dosya-restore-url" class="regular-text" data-dosya="restore-url" value="<?php echo esc_attr($vm['address_default']); ?>" />
        </p>
        <p class="description" data-dosya="restore-url-change"<?php echo $vm['url_change'] === null ? ' hidden' : ''; ?>><?php echo esc_html((string) $vm['url_change']); ?></p>
        <p data-dosya="restore-confirm-row"<?php echo $vm['type_to_confirm'] ? '' : ' hidden'; ?>>
            <label for="dosya-restore-confirm" data-dosya="restore-confirm-label"><?php echo esc_html($vm['labels']['confirm']); ?></label><br />
            <input type="text" id="dosya-restore-confirm" class="regular-text" autocomplete="off" spellcheck="false" data-dosya="restore-confirm" data-expected="<?php echo esc_attr($vm['confirm_domain']); ?>" aria-describedby="dosya-restore-confirm-hint" />
        </p>
        <p class="description dosya-error" id="dosya-restore-confirm-hint" data-dosya="restore-confirm-hint" aria-live="polite" hidden></p>
        <p class="dosya-dialog__buttons">
            <button type="submit" class="button" value="cancel" data-dosya="restore-cancel"><?php echo esc_html($vm['buttons']['cancel']); ?></button>
            <button type="submit" class="button dosya-button--destructive" value="go" data-dosya="restore-go"<?php echo $vm['type_to_confirm'] ? ' disabled="disabled"' : ''; ?>><?php echo esc_html($vm['buttons']['go']); ?></button>
        </p>
    </form>
</dialog>
