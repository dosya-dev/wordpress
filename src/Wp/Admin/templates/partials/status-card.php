<?php
/**
 * Status card on the Backups tab. $vm: StatusCard::build() output plus fix_url.
 *
 * @package dosya-backup
 */

defined('ABSPATH') || exit;
?>
<section class="dosya-panel dosya-status" data-dosya="status-card" data-tone="<?php echo esc_attr($vm['tone']); ?>">
    <span class="dosya-dot" aria-hidden="true"></span>
    <div class="dosya-status__text">
        <p class="dosya-status__headline"><?php echo esc_html($vm['headline']); ?></p>
        <p class="dosya-status__sub"><?php echo esc_html($vm['sub']); ?></p>
    </div>
    <div class="dosya-status__actions">
        <button type="button" class="button button-primary" data-dosya="backup-now" data-action="<?php echo esc_attr($vm['button']['action']); ?>" data-scope="<?php echo esc_attr((string) ($vm['retry_scope'] ?? 'full')); ?>"><?php echo esc_html($vm['button']['label']); ?></button>
        <?php if (!empty($vm['fix']) && $vm['fix']['target'] === 'restore_safety') : ?>
            <button type="button" class="button" data-dosya="status-undo" data-id="<?php echo esc_attr((string) $vm['fix']['snapshot_id']); ?>"><?php echo esc_html($vm['fix']['label']); ?></button>
        <?php elseif (!empty($vm['fix']) && !empty($vm['fix_url'])) : ?>
            <a class="button-link" data-dosya="status-fix" data-target="<?php echo esc_attr($vm['fix']['target']); ?>"<?php if (!empty($vm['log_job_id'])) : ?> data-job="<?php echo esc_attr((string) $vm['log_job_id']); ?>"<?php endif; ?> href="<?php echo esc_url($vm['fix_url']); ?>"><?php echo esc_html($vm['fix']['label']); ?></a>
        <?php endif; ?>
    </div>
    <details class="dosya-popover" data-dosya="backup-popover" hidden>
        <summary class="screen-reader-text"><?php esc_html_e('Backup options', 'dosya-backup-restore-migration'); ?></summary>
        <fieldset>
            <legend><?php esc_html_e('What to back up', 'dosya-backup-restore-migration'); ?></legend>
            <label><input type="radio" name="dosya_scope" value="full" checked="checked" /> <?php esc_html_e('Database and files', 'dosya-backup-restore-migration'); ?></label>
            <label><input type="radio" name="dosya_scope" value="db" /> <?php esc_html_e('Database only', 'dosya-backup-restore-migration'); ?></label>
            <label><input type="radio" name="dosya_scope" value="files" /> <?php esc_html_e('Files only', 'dosya-backup-restore-migration'); ?></label>
        </fieldset>
        <p>
            <label for="dosya-backup-note"><?php esc_html_e('Note (optional)', 'dosya-backup-restore-migration'); ?></label>
            <input type="text" id="dosya-backup-note" class="regular-text" maxlength="80" data-dosya="backup-note" placeholder="<?php esc_attr_e('Note, e.g. before theme update', 'dosya-backup-restore-migration'); ?>" />
        </p>
        <p><button type="button" class="button button-primary" data-dosya="backup-start"><?php esc_html_e('Start backup', 'dosya-backup-restore-migration'); ?></button></p>
    </details>
</section>
