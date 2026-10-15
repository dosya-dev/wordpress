<?php
/**
 * Backups tab. $vm: status (?status card vm), running (?running card vm), rows [backup row vm], empty_text, foot, pager (?{text, page, prev, next}).
 * assets/js/backups.js re-renders every part of this markup; keep the two in step.
 *
 * @package dosya-backup
 */

namespace Dosya\Backup\Wp\Admin;

defined('ABSPATH') || exit;

if ($vm['running'] !== null) {
    Template::render('partials/running-card', $vm['running']);
} else {
    Template::render('partials/status-card', $vm['status']);
    ?>
    <p class="dosya-progress-line" data-dosya="progress" aria-live="polite"></p>
    <?php
}
?>
<div class="dosya-result" data-dosya="result" role="status" hidden></div>
<div class="screen-reader-text" data-dosya="announce" aria-live="polite"></div>
<details class="dosya-panel dosya-log" id="dosya-log" data-dosya="log-panel" hidden>
    <summary><?php esc_html_e('Log', 'dosya-backup-restore-migration'); ?></summary>
    <pre data-dosya="log-text"></pre>
</details>

<table class="widefat striped dosya-list">
    <thead><tr>
        <th scope="col"><?php esc_html_e('Created', 'dosya-backup-restore-migration'); ?></th>
        <th scope="col"><?php esc_html_e('Type', 'dosya-backup-restore-migration'); ?></th>
        <th scope="col"><?php esc_html_e('Contents', 'dosya-backup-restore-migration'); ?></th>
        <th scope="col"><?php esc_html_e('Size', 'dosya-backup-restore-migration'); ?></th>
        <th scope="col"><?php esc_html_e('Stored', 'dosya-backup-restore-migration'); ?></th>
        <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'dosya-backup-restore-migration'); ?></span></th>
    </tr></thead>
    <tbody data-dosya="list">
        <?php if (!$vm['rows']) : ?>
            <tr class="dosya-list__empty"><td colspan="6"><?php echo esc_html($vm['empty_text']); ?></td></tr>
        <?php endif; ?>
        <?php
        foreach ($vm['rows'] as $dosya_backup_row) {
            Template::render('partials/backup-row', $dosya_backup_row);
        }
        ?>
    </tbody>
</table>
<p class="dosya-list__foot" data-dosya="list-foot"><?php echo esc_html($vm['foot']); ?></p>
<div class="tablenav bottom" data-dosya="pager"<?php echo $vm['pager'] === null ? ' hidden' : ''; ?>>
    <?php if ($vm['pager'] !== null) : ?>
        <div class="tablenav-pages">
            <span class="pagination-links">
                <?php if ($vm['pager']['prev'] !== null) : ?>
                    <a class="prev-page button" href="<?php echo esc_url($vm['pager']['prev']); ?>" data-dosya="page" data-page="<?php echo esc_attr((string) ($vm['pager']['page'] - 1)); ?>"><span aria-hidden="true">&lsaquo;</span><span class="screen-reader-text"><?php esc_html_e('Newer backups', 'dosya-backup-restore-migration'); ?></span></a>
                <?php endif; ?>
                <span class="paging-input"><?php echo esc_html($vm['pager']['text']); ?></span>
                <?php if ($vm['pager']['next'] !== null) : ?>
                    <a class="next-page button" href="<?php echo esc_url($vm['pager']['next']); ?>" data-dosya="page" data-page="<?php echo esc_attr((string) ($vm['pager']['page'] + 1)); ?>"><span class="screen-reader-text"><?php esc_html_e('Older backups', 'dosya-backup-restore-migration'); ?></span><span aria-hidden="true">&rsaquo;</span></a>
                <?php endif; ?>
            </span>
        </div>
    <?php endif; ?>
</div>
