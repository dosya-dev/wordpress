# dosya Backup (WordPress plugin)

A backup, restore and migration plugin for WordPress, built by
[dosya.dev](https://dosya.dev). It backs up the database and files in small
resumable steps, restores a site at any size for free, moves a site to a new
address, and can keep a second copy of every backup in a dosya workspace,
where a per-site key and a backup lock keep it safe from the site itself.

This is the source of the plugin published on WordPress.org as
`dosya-backup-restore-migration`. The same code is served as a zip from
[dosya.dev/integration/wordpress/](https://dosya.dev/integration/wordpress/).

Plain PHP 7.4+, no build step, no JavaScript framework. The admin page is
four tabs of WordPress core markup with a few small vanilla scripts.

## Requirements

- WordPress 6.0 or newer (tested up to 7.1)
- PHP 7.4 or newer (tested on 7.4 and 8.3)
- MySQL or MariaDB, reached through `mysqli`

No PHP zip extension is needed: backups are written in the plugin's own
volume format (`src/Archive`), which streams and resumes without ever holding
an archive in memory.

The plugin is written for cheap hosting: every job runs in short resumable
steps so a 30 second execution limit, 128 MB of memory, or a host that blocks
loopback requests does not stop a backup halfway. The Docker test stack runs
under exactly those limits (`docker/php-constrained.ini`), and that is a test
requirement, not a suggestion. Do not raise it to make a test pass.

## Install

From WordPress.org, search for "dosya Backup" under Plugins, Add New.

From a zip: download `dosya-backup.zip` from the
[integration page](https://dosya.dev/integration/wordpress/), then upload it
under Plugins, Add New, Upload Plugin.

From this repository: run `bin/build` (see below) and upload
`build/dosya-backup.zip`. Do not symlink the source tree into a live site;
the source tree carries tests and dev dependencies the plugin does not ship.

## Layout

```
dosya-backup.php        plugin header and bootstrap
src/Archive/            the backup volume format: writer, reader, manifest, codecs
src/Jobs/               backup and restore jobs as resumable steps, the local
                        store, the previous-backup index for incrementals
src/Scan/               file scanner and exclude rules
src/Db/                 streaming SQL dump and import, search and replace
                        that understands serialized data
src/Restore/            file restorer and the maintenance guard
mu-plugin/              the restore guard file, copied into mu-plugins during a restore
src/Connect/            PKCE connect flow and the dosya API client
src/Remote/             resumable upload of a backup into the dosya workspace
src/Support/            small helpers: filesystem, path guard, budgets, text
src/Wp/                 everything that touches WordPress: admin page, AJAX,
                        notices, mailer, scheduler, settings, WP-CLI
src/Wp/Admin/ViewModel/ pure view models for the admin page (no WordPress calls)
src/Wp/Admin/templates/ the HTML the view models render into
assets/                 admin CSS and the vanilla scripts
languages/              the translation template
tests/                  PHPUnit, the e2e scripts and the fake dosya service
docker/                 the PHP 7.4 / 8.3 test images and the e2e stack
bin/                    test, e2e and build entry points
```

Only `src/Wp` calls WordPress functions (plus `mu-plugin/`, the restore guard
that `src/Restore` copies into mu-plugins while a restore runs). Every other
namespace takes
what it needs as arguments, which is what makes it testable without a
WordPress install.

## Develop

Everything runs in Docker. There is no local PHP requirement.

```bash
bin/test unit 83        # PHPUnit on PHP 8.3
bin/test unit 74        # the same on PHP 7.4, the oldest supported version
bin/test integration 83 # tests that need MariaDB
bin/test all 83
```

End-to-end, against two real WordPress sites in containers:

```bash
bin/e2e smoke        # back up and verify from WP-CLI
bin/e2e admin        # the admin page through its AJAX endpoints
bin/e2e roundtrip    # back up site A, restore it onto site B as a migration
bin/e2e remote       # connect to a fake dosya service and upload a backup
bin/e2e plugin-check # WordPress.org Plugin Check against the built zip
```

The e2e stack runs the WordPress version named in `readme.txt` under
`Tested up to`. When WordPress releases, bump the image in `docker/e2e.yml`,
run the five gates, then bump `Tested up to`.

Build the zip WordPress.org and dosya.dev ship:

```bash
bin/build            # build/dosya-backup.zip
```

The build copies only what runs in production, installs the runtime Composer
dependencies with `--no-dev`, and leaves tests, Docker files and dev tooling
out. Plugin Check scans this output, not the source tree.

## How it talks to dosya

Without a connection the plugin contacts no outside service. Backups stay on
the server until you download them.

Connecting sends you to dosya.dev with the site address, the home address,
the site name, a random site identifier and whether the install is multisite.
You approve the site once. The plugin receives a key scoped to that one site:
it can write into the site's own backup folder and register backups, and
nothing else. It cannot delete or overwrite a backup, and it
cannot read the rest of the workspace. If a site is compromised, the attacker
gets the same key, which is the point.

After that the plugin talks only to `api.dosya.dev`. The `src/Connect` and
`src/Remote` namespaces are the whole of that conversation; `readme.txt`
describes it in the words WordPress.org requires.

## Releasing

1. Bump `Version` and `DOSYA_BACKUP_VERSION` in `dosya-backup.php`, `Stable tag`
   and the changelog in `readme.txt`.
2. Regenerate the translation template:
   `wp i18n make-pot . languages/dosya-backup-restore-migration.pot --slug=dosya-backup-restore-migration --domain=dosya-backup-restore-migration`
   (from inside the e2e CLI container, excluding `tests`, `vendor`, `build`,
   `docker` and `bin`).
3. Run the unit suites on both PHP versions and all five e2e gates.
4. `bin/build`, then commit `build/dosya-backup.zip` to the WordPress.org SVN
   `trunk/` and `tags/<version>/`.

## Transparency

Every dosya.dev client is published in the open. Your backups are yours: this
repository lets you verify exactly what the plugin sends to and receives from
our servers, what it writes to your database and file system, and what a
restore replaces. The plugin only ever talks to `dosya.dev` and
`api.dosya.dev`. If a claim we make about privacy or its behaviour cannot be
verified in this code, open an issue and call it out.

## License

GPL-2.0-or-later, the licence WordPress.org requires of every plugin it lists.
See [LICENSE](LICENSE). The bundled `woocommerce/action-scheduler` library is
GPL-3.0-or-later.

This is deliberately not the Dosya Source Available License used by the other
dosya clients. You may fork this plugin and point it at another backend. The
dosya service, its API and its backup guarantees are not part of this
repository.

## Contributing

Issues and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md)
for how the tests work and what a change needs before it can merge.

## Security

Found a vulnerability? Please report it privately via
[GitHub private vulnerability reporting](../../security/advisories/new) or
security@dosya.dev rather than a public issue.

## The dosya.dev client family

| Repository | What it is | License |
|---|---|---|
| [wordpress](https://github.com/dosya-dev/wordpress) | WordPress plugin - backup, restore, migrate | GPL-2.0-or-later |
| [browser-extensions](https://github.com/dosya-dev/browser-extensions) | Browser extension - Save to dosya for Chrome, Edge, Firefox | Source-available |
| [desktop](https://github.com/dosya-dev/desktop) | Desktop client - sync, upload, manage | Source-available |
| [cli](https://github.com/dosya-dev/cli) | Command-line interface | Source-available |
| [app.dosya.dev](https://github.com/dosya-dev/app.dosya.dev) | Web application | Source-available |
| [shared](https://github.com/dosya-dev/shared) | Shared TypeScript types & utilities | Source-available |
| [dosya-js](https://github.com/dosya-dev/dosya-js) | Official JavaScript SDK | MIT |
| [dosya-java](https://github.com/dosya-dev/dosya-java) | Official Java SDK | MIT |
