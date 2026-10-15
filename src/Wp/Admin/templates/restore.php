<?php
/**
 * Restore & migrate tab. No view model.
 *
 * @package dosya-backup
 */

defined('ABSPATH') || exit;
?>
<div class="dosya-result" data-dosya="result" role="status" hidden></div>
<div class="screen-reader-text" data-dosya="announce" aria-live="polite"></div>
<section class="dosya-panel dosya-restore">
    <h2><?php esc_html_e('Restore or move a site here', 'dosya-backup-restore-migration'); ?></h2>
    <p><?php esc_html_e('Upload a .dosya backup made on this site or on another one. For a backup that is already on this server, use Restore in the Backups list.', 'dosya-backup-restore-migration'); ?></p>
    <p><?php esc_html_e('Restoring is free at any size.', 'dosya-backup-restore-migration'); ?></p>
    <div class="dosya-drop" data-dosya="drop">
        <label for="dosya-upload"><?php esc_html_e('Drop a .dosya file here, or choose a file', 'dosya-backup-restore-migration'); ?></label>
        <input type="file" id="dosya-upload" class="dosya-drop__input" multiple data-dosya="upload" accept=".gz,.json,.dosya" />
        <p class="dosya-hint"><?php esc_html_e('Choose every file of the backup: manifest.json.gz and each vNNNN.dosya volume.', 'dosya-backup-restore-migration'); ?></p>
    </div>
    <p><?php esc_html_e('Uploads resume where they stopped if the connection drops.', 'dosya-backup-restore-migration'); ?></p>
    <p><?php esc_html_e('An incremental backup needs the earlier backups it builds on, so a full backup is the safe choice for moving a site.', 'dosya-backup-restore-migration'); ?></p>
    <div class="dosya-panel dosya-upload-progress" data-dosya="upload-progress" aria-live="polite" hidden></div>
</section>
