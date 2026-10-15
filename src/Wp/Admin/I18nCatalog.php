<?php
namespace Dosya\Backup\Wp\Admin;

/**
 * Every string the view models (src/Wp/Admin/ViewModel), ListPayload and the localized script table (AdminPage::i18nKeys())
 * hand to a translator callable, each listed once with a literal __() so `wp i18n make-pot` and the translators can read it.
 * translate() looks a string up here, so no gettext call in the plugin ever receives a variable. I18nCatalogTest keeps the
 * list in step with the sources and checks that every key repeats its own string.
 */
final class I18nCatalog
{
    /** @var array<string,string>|null */
    private static ?array $map = null;

    /** The translation of an English string listed below; the string itself when it is not listed. */
    public static function translate(string $english): string
    {
        if (self::$map === null) {
            self::$map = self::strings();
        }
        return self::$map[$english] ?? $english;
    }

    /** A translator callable for the view models, which know nothing about WordPress. */
    public static function translator(): callable
    {
        return static function (string $s): string {
            return self::translate($s);
        };
    }

    /** @return array<string,string> English string => its translation */
    public static function strings(): array
    {
        return [
            'Safety copy' => __('Safety copy', 'dosya-backup-restore-migration'),
            'Uploaded' => __('Uploaded', 'dosya-backup-restore-migration'),
            'Manual' => __('Manual', 'dosya-backup-restore-migration'),
            'Scheduled' => __('Scheduled', 'dosya-backup-restore-migration'),
            'Database' => __('Database', 'dosya-backup-restore-migration'),
            'Files' => __('Files', 'dosya-backup-restore-migration'),
            'Database + files' => __('Database + files', 'dosya-backup-restore-migration'),
            'made before a restore' => __('made before a restore', 'dosya-backup-restore-migration'),
            /* translators: %s: size of the data this incremental backup added, e.g. "38 MB". */
            'incremental, %s new' => __('incremental, %s new', 'dosya-backup-restore-migration'),
            /* translators: %s: the reason the backup failed. */
            'Failed · %s' => __('Failed · %s', 'dosya-backup-restore-migration'),
            'dosya · verified' => __('dosya · verified', 'dosya-backup-restore-migration'),
            'dosya confirmed every volume of this backup.' => __('dosya confirmed every volume of this backup.', 'dosya-backup-restore-migration'),
            'dosya · uploading' => __('dosya · uploading', 'dosya-backup-restore-migration'),
            'dosya · not verified' => __('dosya · not verified', 'dosya-backup-restore-migration'),
            'dosya · upload failed' => __('dosya · upload failed', 'dosya-backup-restore-migration'),
            'Uploaded; dosya has not confirmed the volumes yet.' => __('Uploaded; dosya has not confirmed the volumes yet.', 'dosya-backup-restore-migration'),
            'local' => __('local', 'dosya-backup-restore-migration'),
            'Stored on this server only.' => __('Stored on this server only.', 'dosya-backup-restore-migration'),
            /* translators: %s: when the lock ends, e.g. "tomorrow 09:00" or "14 Oct 09:00". */
            '🔒 until %s' => __('🔒 until %s', 'dosya-backup-restore-migration'),
            /* translators: %s: when the lock ends, e.g. "tomorrow 09:00" or "14 Oct 09:00". */
            'Protected in dosya until %s; this site\'s key cannot delete or change it.' => __('Protected in dosya until %s; this site\'s key cannot delete or change it.', 'dosya-backup-restore-migration'),
            /* translators: %d: number of newer backups that depend on this one (here exactly 1). */
            'This backup is the base of %d newer backup. Delete it first.' => __('This backup is the base of %d newer backup. Delete it first.', 'dosya-backup-restore-migration'),
            /* translators: %d: number of newer backups that depend on this one. */
            'This backup is the base of %d newer backups. Delete those first.' => __('This backup is the base of %d newer backups. Delete those first.', 'dosya-backup-restore-migration'),
            'Connect again' => __('Connect again', 'dosya-backup-restore-migration'),
            'Connection to dosya lost' => __('Connection to dosya lost', 'dosya-backup-restore-migration'),
            'Your site\'s security keys changed, so the saved connection cannot be read. Backups already in dosya are safe. Connect again to resume uploads.' => __('Your site\'s security keys changed, so the saved connection cannot be read. Backups already in dosya are safe. Connect again to resume uploads.', 'dosya-backup-restore-migration'),
            'This site was disconnected from dosya.dev' => __('This site was disconnected from dosya.dev', 'dosya-backup-restore-migration'),
            'Connect again to resume uploads.' => __('Connect again to resume uploads.', 'dosya-backup-restore-migration'),
            'Store copies in dosya' => __('Store copies in dosya', 'dosya-backup-restore-migration'),
            'Keep a second copy of every backup off this server. If this server is ever lost, you can restore from dosya.' => __('Keep a second copy of every backup off this server. If this server is ever lost, you can restore from dosya.', 'dosya-backup-restore-migration'),
            'You will approve this site on dosya.dev. dosya receives the site address, its name, and the backups you make. Nothing else.' => __('You will approve this site on dosya.dev. dosya receives the site address, its name, and the backups you make. Nothing else.', 'dosya-backup-restore-migration'),
            'Connect to dosya' => __('Connect to dosya', 'dosya-backup-restore-migration'),
            'Disconnect this site from dosya? Backups already stored in dosya are kept.' => __('Disconnect this site from dosya? Backups already stored in dosya are kept.', 'dosya-backup-restore-migration'),
            /* translators: %d: number of backups that still fit. */
            'about %d more backups at the current size' => __('about %d more backups at the current size', 'dosya-backup-restore-migration'),
            /* translators: 1: storage used, 2: storage total, both sizes such as 1.2 GB. */
            '%1$s of %2$s used' => __('%1$s of %2$s used', 'dosya-backup-restore-migration'),
            'Open in dosya ↗' => __('Open in dosya ↗', 'dosya-backup-restore-migration'),
            'Storage in dosya is almost full' => __('Storage in dosya is almost full', 'dosya-backup-restore-migration'),
            /* translators: 1: storage used, 2: storage total, both sizes such as 1.2 GB. */
            '%1$s of %2$s. When it is full, the next upload is skipped and the backup stays on this server only.' => __('%1$s of %2$s. When it is full, the next upload is skipped and the backup stays on this server only.', 'dosya-backup-restore-migration'),
            'Free the space first: exclude the uploads folder under Schedule, or shorten retention on dosya.dev. If you need more, plans start at 50 GB on dosya.dev.' => __('Free the space first: exclude the uploads folder under Schedule, or shorten retention on dosya.dev. If you need more, plans start at 50 GB on dosya.dev.', 'dosya-backup-restore-migration'),
            'Review excludes' => __('Review excludes', 'dosya-backup-restore-migration'),
            'Connected to dosya' => __('Connected to dosya', 'dosya-backup-restore-migration'),
            /* translators: 1: dosya account name, 2: dosya workspace name. */
            'as %1$s · workspace %2$s' => __('as %1$s · workspace %2$s', 'dosya-backup-restore-migration'),
            /* translators: %s: dosya account name. */
            'as %s' => __('as %s', 'dosya-backup-restore-migration'),
            'Disconnect' => __('Disconnect', 'dosya-backup-restore-migration'),
            'Folder' => __('Folder', 'dosya-backup-restore-migration'),
            'Last upload' => __('Last upload', 'dosya-backup-restore-migration'),
            /* translators: %s: when the last upload happened, e.g. "today 09:00". */
            '%s, verified' => __('%s, verified', 'dosya-backup-restore-migration'),
            /* translators: %s: when the last upload happened, e.g. "today 09:00". */
            '%s, not yet verified' => __('%s, not yet verified', 'dosya-backup-restore-migration'),
            'Retention' => __('Retention', 'dosya-backup-restore-migration'),
            /* translators: %d: number of days. */
            '%d days' => __('%d days', 'dosya-backup-restore-migration'),
            'Protection' => __('Protection', 'dosya-backup-restore-migration'),
            /* translators: %d: number of hours. */
            '%d h after each upload' => __('%d h after each upload', 'dosya-backup-restore-migration'),
            'just now' => __('just now', 'dosya-backup-restore-migration'),
            /* translators: %d: number of minutes. */
            '%d minute ago' => __('%d minute ago', 'dosya-backup-restore-migration'),
            /* translators: %d: number of minutes. */
            '%d minutes ago' => __('%d minutes ago', 'dosya-backup-restore-migration'),
            /* translators: %d: number of hours. */
            '%d hour ago' => __('%d hour ago', 'dosya-backup-restore-migration'),
            /* translators: %d: number of hours. */
            '%d hours ago' => __('%d hours ago', 'dosya-backup-restore-migration'),
            'yesterday' => __('yesterday', 'dosya-backup-restore-migration'),
            /* translators: %d: number of days. */
            '%d days ago' => __('%d days ago', 'dosya-backup-restore-migration'),
            'Today' => __('Today', 'dosya-backup-restore-migration'),
            'today' => __('today', 'dosya-backup-restore-migration'),
            'Yesterday' => __('Yesterday', 'dosya-backup-restore-migration'),
            'Tomorrow' => __('Tomorrow', 'dosya-backup-restore-migration'),
            'tomorrow' => __('tomorrow', 'dosya-backup-restore-migration'),
            /* translators: date format: day and short month, see https://www.php.net/manual/datetime.format.php */
            'j M' => __('j M', 'dosya-backup-restore-migration'),
            /* translators: joins two parts with a space. Either 1: a day such as "Today" or "14 Oct", 2: a time such as "09:00"; or 1: a result sentence, 2: the warning sentence that follows it. */
            '%1$s %2$s' => __('%1$s %2$s', 'dosya-backup-restore-migration'),
            /* translators: %s: the reason the backup failed. */
            'The latest backup of this site failed: %s' => __('The latest backup of this site failed: %s', 'dosya-backup-restore-migration'),
            /* translators: 1: site name, 2: the reason the backup failed. */
            '[%1$s] Backup failed: %2$s' => __('[%1$s] Backup failed: %2$s', 'dosya-backup-restore-migration'),
            /* translators: %s: the reason the backup was skipped. */
            'The latest backup of this site was skipped: %s' => __('The latest backup of this site was skipped: %s', 'dosya-backup-restore-migration'),
            /* translators: 1: site name, 2: the reason the backup was skipped. */
            '[%1$s] Backup skipped: %2$s' => __('[%1$s] Backup skipped: %2$s', 'dosya-backup-restore-migration'),
            /* translators: %s: backup size such as 1.2 GB. */
            'The latest backup of this site is complete: %s, stored in dosya.' => __('The latest backup of this site is complete: %s, stored in dosya.', 'dosya-backup-restore-migration'),
            /* translators: %s: backup size such as 1.2 GB. */
            'The latest backup of this site is complete: %s, on this server.' => __('The latest backup of this site is complete: %s, on this server.', 'dosya-backup-restore-migration'),
            /* translators: %s: date and time until which the stored copy cannot be deleted. */
            'It is protected from deletion until %s.' => __('It is protected from deletion until %s.', 'dosya-backup-restore-migration'),
            /* translators: %s: site name. */
            '[%s] Backup complete' => __('[%s] Backup complete', 'dosya-backup-restore-migration'),
            'No earlier backup exists yet.' => __('No earlier backup exists yet.', 'dosya-backup-restore-migration'),
            /* translators: 1: when the last good backup was made, 2: where it is stored. */
            'Your last good backup is from %1$s and is %2$s.' => __('Your last good backup is from %1$s and is %2$s.', 'dosya-backup-restore-migration'),
            'Free up disk space or exclude folders under Schedule, then retry.' => __('Free up disk space or exclude folders under Schedule, then retry.', 'dosya-backup-restore-migration'),
            'Connect to dosya again, then retry.' => __('Connect to dosya again, then retry.', 'dosya-backup-restore-migration'),
            'Open dosya Backup to see the log and retry.' => __('Open dosya Backup to see the log and retry.', 'dosya-backup-restore-migration'),
            'unknown error' => __('unknown error', 'dosya-backup-restore-migration'),
            /* translators: %s: domain of the site the backup was made on. */
            'Move %s onto this site?' => __('Move %s onto this site?', 'dosya-backup-restore-migration'),
            /* translators: 1: domain of this site, 2: domain of the site the backup was made on. %1$s must appear exactly once and first: the renderer splits the sentence on it. */
            'This replaces everything on %1$s with %2$s\'s database and files. Links and media paths are rewritten to the new address.' => __('This replaces everything on %1$s with %2$s\'s database and files. Links and media paths are rewritten to the new address.', 'dosya-backup-restore-migration'),
            'Replace this site' => __('Replace this site', 'dosya-backup-restore-migration'),
            /* translators: %s: date and time of the backup. */
            'Restore the backup from %s?' => __('Restore the backup from %s?', 'dosya-backup-restore-migration'),
            /* translators: %1$s: domain of this site. It must appear exactly once and first: the renderer splits the sentence on it. */
            'This replaces the current %1$s with the site as it was then:' => __('This replaces the current %1$s with the site as it was then:', 'dosya-backup-restore-migration'),
            'Restore site' => __('Restore site', 'dosya-backup-restore-migration'),
            /* translators: 1: address the backup was made on, 2: this site's address. */
            'Links are updated from %1$s to %2$s.' => __('Links are updated from %1$s to %2$s.', 'dosya-backup-restore-migration'),
            'Database: posts, pages, settings, users, orders' => __('Database: posts, pages, settings, users, orders', 'dosya-backup-restore-migration'),
            'Files: themes, plugins, uploads' => __('Files: themes, plugins, uploads', 'dosya-backup-restore-migration'),
            'Incremental backup' => __('Incremental backup', 'dosya-backup-restore-migration'),
            'Database backup' => __('Database backup', 'dosya-backup-restore-migration'),
            'Files backup' => __('Files backup', 'dosya-backup-restore-migration'),
            'Full backup' => __('Full backup', 'dosya-backup-restore-migration'),
            'Stored in dosya, verified restorable' => __('Stored in dosya, verified restorable', 'dosya-backup-restore-migration'),
            'Stored in dosya, not yet verified' => __('Stored in dosya, not yet verified', 'dosya-backup-restore-migration'),
            'Uploaded from another site' => __('Uploaded from another site', 'dosya-backup-restore-migration'),
            'On this server' => __('On this server', 'dosya-backup-restore-migration'),
            /* translators: joins the facts line. 1: the facts so far, 2: the next fact, e.g. "Full backup" and "1.2 GB". */
            '%1$s · %2$s' => __('%1$s · %2$s', 'dosya-backup-restore-migration'),
            'Site address after restore' => __('Site address after restore', 'dosya-backup-restore-migration'),
            'Type the current site\'s domain to confirm' => __('Type the current site\'s domain to confirm', 'dosya-backup-restore-migration'),
            'A safety copy of the current site is made first. If anything looks wrong afterwards, open the Backups list and restore that safety copy.' => __('A safety copy of the current site is made first. If anything looks wrong afterwards, open the Backups list and restore that safety copy.', 'dosya-backup-restore-migration'),
            'Keep current site' => __('Keep current site', 'dosya-backup-restore-migration'),
            'Unknown error' => __('Unknown error', 'dosya-backup-restore-migration'),
            'Restore safety copy' => __('Restore safety copy', 'dosya-backup-restore-migration'),
            'Show log' => __('Show log', 'dosya-backup-restore-migration'),
            /* translators: %s: the reason the restore failed. */
            'Restore failed: %s' => __('Restore failed: %s', 'dosya-backup-restore-migration'),
            /* translators: %s: the reason the backup failed. */
            'Backup failed: %s' => __('Backup failed: %s', 'dosya-backup-restore-migration'),
            'Retry now' => __('Retry now', 'dosya-backup-restore-migration'),
            'Backup skipped.' => __('Backup skipped.', 'dosya-backup-restore-migration'),
            /* translators: %s: the reason the backup was skipped. */
            'Backup skipped: %s' => __('Backup skipped: %s', 'dosya-backup-restore-migration'),
            'Cancelled.' => __('Cancelled.', 'dosya-backup-restore-migration'),
            /* translators: %s: backup size such as 1.2 GB. */
            'Backup complete, %s, stored in dosya, not yet verified.' => __('Backup complete, %s, stored in dosya, not yet verified.', 'dosya-backup-restore-migration'),
            /* translators: %s: backup size such as 1.2 GB. */
            'Backup complete, %s, on this server.' => __('Backup complete, %s, on this server.', 'dosya-backup-restore-migration'),
            /* translators: 1: number of warnings, 2: the first warning. */
            'With %1$d warning: %2$s' => __('With %1$d warning: %2$s', 'dosya-backup-restore-migration'),
            /* translators: 1: number of warnings, 2: the first warning. */
            'With %1$d warnings: %2$s' => __('With %1$d warnings: %2$s', 'dosya-backup-restore-migration'),
            /* translators: %s: date and time of the restored backup. */
            'Site restored to %s. Links checked, cache cleared, maintenance page removed.' => __('Site restored to %s. Links checked, cache cleared, maintenance page removed.', 'dosya-backup-restore-migration'),
            'Undo this restore' => __('Undo this restore', 'dosya-backup-restore-migration'),
            /* translators: 1: tables done, 2: total tables. */
            '%1$d of %2$d tables' => __('%1$d of %2$d tables', 'dosya-backup-restore-migration'),
            /* translators: 1: number of files, 2: size such as 1.2 GB. */
            '%1$d file, %2$s' => __('%1$d file, %2$s', 'dosya-backup-restore-migration'),
            /* translators: 1: number of files, 2: size such as 1.2 GB. */
            '%1$d files, %2$s' => __('%1$d files, %2$s', 'dosya-backup-restore-migration'),
            /* translators: 1: size done, 2: total size. */
            '%1$s of %2$s' => __('%1$s of %2$s', 'dosya-backup-restore-migration'),
            /* translators: 1: items done, 2: total items. */
            '%1$d of %2$d' => __('%1$d of %2$d', 'dosya-backup-restore-migration'),
            /* translators: %s: date and time of the backup being restored. */
            'Restoring the backup from %s' => __('Restoring the backup from %s', 'dosya-backup-restore-migration'),
            'Restoring the backup' => __('Restoring the backup', 'dosya-backup-restore-migration'),
            'Uploading to dosya' => __('Uploading to dosya', 'dosya-backup-restore-migration'),
            'Backing up this site' => __('Backing up this site', 'dosya-backup-restore-migration'),
            /* translators: 1: day such as "tomorrow", 2: time of day. */
            'Next backup %1$s at %2$s.' => __('Next backup %1$s at %2$s.', 'dosya-backup-restore-migration'),
            'Fix the cause, then retry.' => __('Fix the cause, then retry.', 'dosya-backup-restore-migration'),
            'Automatic backups are off.' => __('Automatic backups are off.', 'dosya-backup-restore-migration'),
            /* translators: %s: the reason the backup failed. */
            'Last backup failed: %s' => __('Last backup failed: %s', 'dosya-backup-restore-migration'),
            /* translators: %s: the reason the restore failed. */
            'Last restore failed: %s' => __('Last restore failed: %s', 'dosya-backup-restore-migration'),
            'The safety copy made before the restore puts the site back as it was.' => __('The safety copy made before the restore puts the site back as it was.', 'dosya-backup-restore-migration'),
            'Open the log to see what stopped it.' => __('Open the log to see what stopped it.', 'dosya-backup-restore-migration'),
            /* translators: %s: why the upload to dosya failed. */
            'upload to dosya failed: %s' => __('upload to dosya failed: %s', 'dosya-backup-restore-migration'),
            // ListPayload: the cause of a failed run that recorded none
            'The backup did not finish.' => __('The backup did not finish.', 'dosya-backup-restore-migration'),
            'The upload to dosya did not finish.' => __('The upload to dosya did not finish.', 'dosya-backup-restore-migration'),
            'View log' => __('View log', 'dosya-backup-restore-migration'),
            'Your first backup is saved on this server.' => __('Your first backup is saved on this server.', 'dosya-backup-restore-migration'),
            'Automatic backups are off; turn them on under Schedule.' => __('Automatic backups are off; turn them on under Schedule.', 'dosya-backup-restore-migration'),
            /* translators: 1: Daily or Weekly, 2: time of day, 3: number of backups kept. */
            '%1$s backups are already on (%2$s, keeps %3$d).' => __('%1$s backups are already on (%2$s, keeps %3$d).', 'dosya-backup-restore-migration'),
            /* translators: %1$s: Daily, Weekly or Automatic. */
            '%1$s backups are already on.' => __('%1$s backups are already on.', 'dosya-backup-restore-migration'),
            'Connect to dosya to store copies off-site, protected from deletion.' => __('Connect to dosya to store copies off-site, protected from deletion.', 'dosya-backup-restore-migration'),
            'No backup yet' => __('No backup yet', 'dosya-backup-restore-migration'),
            'Back up now' => __('Back up now', 'dosya-backup-restore-migration'),
            /* translators: %s: how long ago the backup was made, e.g. "2 hours ago". */
            'Verified restorable, %s' => __('Verified restorable, %s', 'dosya-backup-restore-migration'),
            /* translators: %s: how long ago the backup was made, e.g. "2 hours ago". */
            'Uploaded to dosya, not yet verified, %s' => __('Uploaded to dosya, not yet verified, %s', 'dosya-backup-restore-migration'),
            /* translators: %s: how long ago the backup was made, e.g. "2 hours ago". */
            'Uploading to dosya, %s' => __('Uploading to dosya, %s', 'dosya-backup-restore-migration'),
            /* translators: %s: how long ago the backup was made, e.g. "2 hours ago". */
            'Backed up on this server, %s' => __('Backed up on this server, %s', 'dosya-backup-restore-migration'),
            /* translators: %s: when the lock ends, e.g. "tomorrow 09:00". */
            'stored in dosya, protected until %s' => __('stored in dosya, protected until %s', 'dosya-backup-restore-migration'),
            'stored in dosya' => __('stored in dosya', 'dosya-backup-restore-migration'),
            /* translators: 1: day such as "tomorrow", 2: time of day. */
            'next backup %1$s at %2$s' => __('next backup %1$s at %2$s', 'dosya-backup-restore-migration'),
            'automatic backups are off' => __('automatic backups are off', 'dosya-backup-restore-migration'),
            'Backup overdue' => __('Backup overdue', 'dosya-backup-restore-migration'),
            /* translators: %s: how long ago the last backup was made. */
            'last backup %s' => __('last backup %s', 'dosya-backup-restore-migration'),
            'Changes since then are not protected.' => __('Changes since then are not protected.', 'dosya-backup-restore-migration'),
            'Check schedule' => __('Check schedule', 'dosya-backup-restore-migration'),
            'dosya connection lost, uploads paused' => __('dosya connection lost, uploads paused', 'dosya-backup-restore-migration'),
            /* translators: 1: storage used in GB, 2: storage total in GB. */
            '%1$s of %2$s GB used' => __('%1$s of %2$s GB used', 'dosya-backup-restore-migration'),
            'dosya storage almost full' => __('dosya storage almost full', 'dosya-backup-restore-migration'),
            'Review storage' => __('Review storage', 'dosya-backup-restore-migration'),
            'Daily' => __('Daily', 'dosya-backup-restore-migration'),
            'Weekly' => __('Weekly', 'dosya-backup-restore-migration'),
            'Automatic' => __('Automatic', 'dosya-backup-restore-migration'),
            'verified in dosya' => __('verified in dosya', 'dosya-backup-restore-migration'),
            'in dosya, not yet verified' => __('in dosya, not yet verified', 'dosya-backup-restore-migration'),
            'in dosya, upload in progress' => __('in dosya, upload in progress', 'dosya-backup-restore-migration'),
            'on this server only' => __('on this server only', 'dosya-backup-restore-migration'),
            'Preparing' => __('Preparing', 'dosya-backup-restore-migration'),
            'Working' => __('Working', 'dosya-backup-restore-migration'),
            'Finishing' => __('Finishing', 'dosya-backup-restore-migration'),
            'Registering with dosya' => __('Registering with dosya', 'dosya-backup-restore-migration'),
            'Scanning' => __('Scanning', 'dosya-backup-restore-migration'),
            /* translators: 1: current volume number, 2: number of volumes. */
            'Uploading to dosya, volume %1$d of %2$d' => __('Uploading to dosya, volume %1$d of %2$d', 'dosya-backup-restore-migration'),
            'Uploading manifests' => __('Uploading manifests', 'dosya-backup-restore-migration'),
            'Finishing the upload to dosya' => __('Finishing the upload to dosya', 'dosya-backup-restore-migration'),
            'Making a safety copy' => __('Making a safety copy', 'dosya-backup-restore-migration'),
            'Reading the backup' => __('Reading the backup', 'dosya-backup-restore-migration'),
            'Updating links and paths' => __('Updating links and paths', 'dosya-backup-restore-migration'),
            'Switching to the restored database' => __('Switching to the restored database', 'dosya-backup-restore-migration'),
            'Checking the site' => __('Checking the site', 'dosya-backup-restore-migration'),
            'Cleaning up' => __('Cleaning up', 'dosya-backup-restore-migration'),
            'Delete this backup from this server? Copies in dosya are kept.' => __('Delete this backup from this server? Copies in dosya are kept.', 'dosya-backup-restore-migration'),
            'This is an incremental backup. It depends on earlier backups and can only be imported where those exist. To migrate a site, download a full backup.' => __('This is an incremental backup. It depends on earlier backups and can only be imported where those exist. To migrate a site, download a full backup.', 'dosya-backup-restore-migration'),
            'Your session expired. Reload the page to continue.' => __('Your session expired. Reload the page to continue.', 'dosya-backup-restore-migration'),
            'Your login was refreshed by the restore. Reloading...' => __('Your login was refreshed by the restore. Reloading...', 'dosya-backup-restore-migration'),
            'Saved' => __('Saved', 'dosya-backup-restore-migration'),
            'changes apply from the next run' => __('changes apply from the next run', 'dosya-backup-restore-migration'),
            /* translators: 1: size uploaded, 2: total size, 3: current chunk, 4: number of chunks. */
            '%1$s of %2$s · chunk %3$d of %4$d · if the upload stops, choose the same files again to resume' => __('%1$s of %2$s · chunk %3$d of %4$d · if the upload stops, choose the same files again to resume', 'dosya-backup-restore-migration'),
            'Backup received. Choose how to restore it.' => __('Backup received. Choose how to restore it.', 'dosya-backup-restore-migration'),
            'No backups on this server yet. The first one appears here as soon as it starts.' => __('No backups on this server yet. The first one appears here as soon as it starts.', 'dosya-backup-restore-migration'),
            'More actions' => __('More actions', 'dosya-backup-restore-migration'),
            /* translators: %s: the reason it failed. */
            'Failed: %s' => __('Failed: %s', 'dosya-backup-restore-migration'),
            /* translators: %s: the reason it was skipped. */
            'Skipped: %s' => __('Skipped: %s', 'dosya-backup-restore-migration'),
            'Done.' => __('Done.', 'dosya-backup-restore-migration'),
            /* translators: 1: number of warnings, 2: the first warning. */
            'Done, with %1$d warning(s): %2$s' => __('Done, with %1$d warning(s): %2$s', 'dosya-backup-restore-migration'),
            'The request failed. Try again.' => __('The request failed. Try again.', 'dosya-backup-restore-migration'),
            'Restore' => __('Restore', 'dosya-backup-restore-migration'),
            'Download manifest' => __('Download manifest', 'dosya-backup-restore-migration'),
            /* translators: %s: volume file name, e.g. v0001.dosya. */
            'Download %s' => __('Download %s', 'dosya-backup-restore-migration'),
            'Delete' => __('Delete', 'dosya-backup-restore-migration'),
            'the site shows a maintenance page until this finishes' => __('the site shows a maintenance page until this finishes', 'dosya-backup-restore-migration'),
            'A backup or restore is running' => __('A backup or restore is running', 'dosya-backup-restore-migration'),
            'No log is kept for completed backups yet.' => __('No log is kept for completed backups yet.', 'dosya-backup-restore-migration'),
            'Start backup' => __('Start backup', 'dosya-backup-restore-migration'),
            'Backup started.' => __('Backup started.', 'dosya-backup-restore-migration'),
            /* translators: 1: current page, 2: number of pages. */
            'Page %1$d of %2$d' => __('Page %1$d of %2$d', 'dosya-backup-restore-migration'),
            'Newer backups' => __('Newer backups', 'dosya-backup-restore-migration'),
            'Older backups' => __('Older backups', 'dosya-backup-restore-migration'),
            'Backup options' => __('Backup options', 'dosya-backup-restore-migration'),
            'What to back up' => __('What to back up', 'dosya-backup-restore-migration'),
            'Database and files' => __('Database and files', 'dosya-backup-restore-migration'),
            'Database only' => __('Database only', 'dosya-backup-restore-migration'),
            'Files only' => __('Files only', 'dosya-backup-restore-migration'),
            'Note (optional)' => __('Note (optional)', 'dosya-backup-restore-migration'),
            'Note, e.g. before theme update' => __('Note, e.g. before theme update', 'dosya-backup-restore-migration'),
            'Dismiss' => __('Dismiss', 'dosya-backup-restore-migration'),
            /* translators: %s: domain of this site. */
            'The domain you typed does not match %s. Type it exactly to continue.' => __('The domain you typed does not match %s. Type it exactly to continue.', 'dosya-backup-restore-migration'),
            /* translators: %s: why the upload stopped. */
            'Upload stopped: %s Choose the files again to resume where it stopped.' => __('Upload stopped: %s Choose the files again to resume where it stopped.', 'dosya-backup-restore-migration'),
            /* translators: %s: file name. */
            'Uploading %s' => __('Uploading %s', 'dosya-backup-restore-migration'),
            'Upload progress' => __('Upload progress', 'dosya-backup-restore-migration'),
            'An upload is already running. Wait for it to finish.' => __('An upload is already running. Wait for it to finish.', 'dosya-backup-restore-migration'),
            'No files were chosen. Choose the backup files to upload.' => __('No files were chosen. Choose the backup files to upload.', 'dosya-backup-restore-migration'),
            'The connection to this site dropped. Check the connection, then reload the page.' => __('The connection to this site dropped. Check the connection, then reload the page.', 'dosya-backup-restore-migration'),
            'Restore started. The site shows a maintenance page until it finishes.' => __('Restore started. The site shows a maintenance page until it finishes.', 'dosya-backup-restore-migration'),
        ];
    }
}
