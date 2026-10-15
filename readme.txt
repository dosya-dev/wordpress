=== dosya Backup ===
Contributors: dosyadev
Tags: backup, restore, migration, clone, database backup
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, restore and move your site. Restoring is free at any size, with no account.

== Description ==

* Back up the whole site, only the database, or only files.
* Restore or move a site to a new address with no size limit and no paid add-on.
* Upload a backup in small pieces, so your host's upload limit never matters.
* A safety copy of the database is made before every restore, so you can undo it.
* Works on hosts with short time limits: every job runs in small resumable steps.
* WP-CLI: `wp dosya backup`, `wp dosya restore`, `wp dosya list`, `wp dosya verify`.

Sites whose wp-content folder lives outside the WordPress folder are not fully supported yet.

Source code, issues and pull requests: https://github.com/dosya-dev/wordpress

== External services ==

The plugin contacts no outside service until the site owner connects it to a dosya account. Without a connection, backups stay on your server until you download them.

When you choose "Connect to dosya", the plugin sends you to https://dosya.dev with the site address, the home address, the site name, a random site identifier (not derived from any personal data) and whether this is a multisite install. You approve the connection there once.

After that the plugin talks only to https://api.dosya.dev. Every request carries the plugin version and the WordPress version in its User-Agent. During backups it uploads the backup files you make, with their sizes and checksums, and registers the backup. When you disconnect, it tells dosya.

dosya privacy policy: https://dosya.dev/privacy
dosya terms of service: https://dosya.dev/terms

== Screenshots ==

1. The Backups tab: the status card, the backup list with where each copy is stored, and Back up now.
2. The restore dialog: what will be replaced, the safety copy, and the Restore site button.
3. The Schedule tab: automatic backups, retention, safety copy and email settings as plain sentences.
4. The dosya tab: connect the site to keep a second copy of every backup off this server.

== Changelog ==

= 0.3.1 =
* Text domain and folder follow the WordPress.org slug, dosya-backup-restore-migration.
* The restore guard is a file shipped with the plugin and copied into mu-plugins while a restore runs; nothing is generated.
* Every translatable string is a literal, so the translation tools can read all of them.
* On a site without a MySQL or MariaDB database the plugin activates quietly and explains on the Plugins screen why it stays idle.
* Action Scheduler 4.2.0; WordPress 6.9 or newer.

= 0.3.0 =
* The plugin is named dosya Backup, matching its WordPress.org slug and text domain.
* Ready for the WordPress.org directory: translation template, screenshots, and a clean Plugin Check run.
* The plugin link points at dosya.dev/integration/wordpress/.

= 0.2.0 =
* A redesigned admin page in four tabs: Backups, Restore & migrate, Schedule and dosya.
* A status card on the Backups tab says in one line whether the site is protected, and names the one fix when it is not.
* Back up now asks what to back up and takes an optional note, shown in the backup list.
* A restore dialog that says what will be replaced, makes a safety copy of the current site first, and offers Undo after the restore.
* Admin notices when the last backup failed or the dosya connection was lost, dismissible until the problem changes.
* Email when a backup fails or is skipped, and optionally when one completes.

= 0.1.0 =
* First release: local backups, restore and migration.
