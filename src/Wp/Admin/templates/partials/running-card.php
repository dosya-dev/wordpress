<?php
/**
 * Running card, shown instead of the status card while a job runs. $vm: RunningCard::build() output plus line.
 *
 * @package dosya-backup
 */

defined('ABSPATH') || exit;
$dosya_backup_percent = $vm['percent'];
?>
<section class="dosya-panel dosya-running" data-dosya="running-card" data-tone="neutral">
    <p class="dosya-running__heading"><?php echo esc_html($vm['heading']); ?></p>
    <div class="dosya-progress" role="progressbar" aria-label="<?php echo esc_attr($vm['heading']); ?>" aria-valuemin="0" aria-valuemax="100"<?php if ($dosya_backup_percent !== null) : ?> aria-valuenow="<?php echo esc_attr((string) $dosya_backup_percent); ?>"<?php endif; ?>>
        <span class="dosya-progress__thumb" style="width:<?php echo esc_attr((string) ($dosya_backup_percent ?? 0)); ?>%"></span>
    </div>
    <p class="dosya-running__detail" data-dosya="progress" aria-live="polite"><?php echo esc_html($vm['line']); ?></p>
    <details>
        <summary><?php esc_html_e('Show log', 'dosya-backup-restore-migration'); ?></summary>
        <pre data-dosya="log"></pre>
    </details>
</section>
