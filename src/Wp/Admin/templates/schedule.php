<?php
/**
 * Schedule tab. $vm: schedule, time, tz, next_text (string), keep, keep_options, remote_keep (?string), default_excludes, safety_copy, email_fail, email_ok, email_to, excludes.
 *
 * @package dosya-backup
 */

defined('ABSPATH') || exit;
?>
<section class="dosya-panel dosya-schedule">
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="dosya-set-schedule"><?php esc_html_e('Automatic backups', 'dosya-backup-restore-migration'); ?></label></th>
            <td>
                <select id="dosya-set-schedule" data-dosya="set-schedule">
                    <option value="off"<?php selected($vm['schedule'], 'off'); ?>><?php esc_html_e('Off', 'dosya-backup-restore-migration'); ?></option>
                    <option value="daily"<?php selected($vm['schedule'], 'daily'); ?>><?php esc_html_e('Daily', 'dosya-backup-restore-migration'); ?></option>
                    <option value="weekly"<?php selected($vm['schedule'], 'weekly'); ?>><?php esc_html_e('Weekly', 'dosya-backup-restore-migration'); ?></option>
                    <option value="hourly" disabled="disabled"><?php esc_html_e('Hourly (dosya plans)', 'dosya-backup-restore-migration'); ?></option>
                </select>
                <div data-dosya="set-when"<?php echo $vm['schedule'] === 'off' ? ' hidden' : ''; ?>>
                    <label for="dosya-set-time"><?php esc_html_e('at', 'dosya-backup-restore-migration'); ?></label>
                    <input type="time" id="dosya-set-time" data-dosya="set-time" value="<?php echo esc_attr($vm['time']); ?>" />
                    <p class="description">
                        <?php
                        /* translators: %s: the site's time zone, e.g. Europe/Istanbul. */
                        echo esc_html(sprintf(__('Times are in the site time zone, %s.', 'dosya-backup-restore-migration'), $vm['tz']));
                        ?>
                        <span data-dosya="set-next"><?php echo esc_html($vm['next_text']); ?></span>
                    </p>
                </div>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="dosya-set-keep"><?php esc_html_e('Keep on this server', 'dosya-backup-restore-migration'); ?></label></th>
            <td>
                <select id="dosya-set-keep" data-dosya="set-keep">
                    <?php foreach ($vm['keep_options'] as $dosya_backup_keep) : ?>
                        <option value="<?php echo esc_attr((string) $dosya_backup_keep); ?>"<?php selected($vm['keep'], $dosya_backup_keep); ?>><?php echo esc_html((string) $dosya_backup_keep); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e('Older ones are removed after each successful backup. Backups that newer ones depend on are kept.', 'dosya-backup-restore-migration'); ?></p>
            </td>
        </tr>
        <?php if ($vm['remote_keep'] !== null) : ?>
            <tr>
                <th scope="row"><?php esc_html_e('Keep in dosya', 'dosya-backup-restore-migration'); ?></th>
                <td><p data-dosya="remote-keep"><?php echo esc_html($vm['remote_keep']); ?></p></td>
            </tr>
        <?php endif; ?>
        <tr>
            <th scope="row"><?php esc_html_e('Safety copy before restore', 'dosya-backup-restore-migration'); ?></th>
            <td>
                <label><input type="checkbox" data-dosya="set-safety" value="1"<?php checked($vm['safety_copy']); ?> /> <?php esc_html_e('Make a safety copy of the current site before every restore.', 'dosya-backup-restore-migration'); ?></label>
                <p class="description"><?php esc_html_e('Turning this off makes every restore ask you to type the domain.', 'dosya-backup-restore-migration'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Email me', 'dosya-backup-restore-migration'); ?></th>
            <td>
                <label><input type="checkbox" data-dosya="set-email-fail" value="1"<?php checked($vm['email_fail']); ?> /> <?php esc_html_e('when a backup fails', 'dosya-backup-restore-migration'); ?></label>
                <span aria-hidden="true">·</span>
                <label><input type="checkbox" data-dosya="set-email-ok" value="1"<?php checked($vm['email_ok']); ?> /> <?php esc_html_e('when a backup succeeds', 'dosya-backup-restore-migration'); ?></label>
                <p class="description">
                    <?php
                    /* translators: %s: the site's admin email address. */
                    echo esc_html(sprintf(__('Emails go to %s, the admin email address.', 'dosya-backup-restore-migration'), $vm['email_to']));
                    ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Advanced', 'dosya-backup-restore-migration'); ?></th>
            <td>
                <details id="dosya-excludes">
                    <summary><?php esc_html_e('Advanced: exclude folders and files', 'dosya-backup-restore-migration'); ?></summary>
                    <p><label for="dosya-set-excludes"><?php esc_html_e('One pattern per line. Defaults already skip caches, logs and other backup plugins\' folders.', 'dosya-backup-restore-migration'); ?></label></p>
                    <textarea id="dosya-set-excludes" rows="5" class="large-text code" data-dosya="set-excludes"><?php echo esc_textarea($vm['excludes']); ?></textarea>
                    <ul class="dosya-defaults">
                        <?php foreach ($vm['default_excludes'] as $dosya_backup_glob) : ?>
                            <li><code><?php echo esc_html($dosya_backup_glob); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            </td>
        </tr>
    </table>
    <p>
        <button type="button" class="button button-primary" data-dosya="set-save"><?php esc_html_e('Save', 'dosya-backup-restore-migration'); ?></button>
        <span class="dosya-save-status" data-dosya="set-status" aria-live="polite"></span>
    </p>
</section>
