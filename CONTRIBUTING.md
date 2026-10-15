# Contributing to dosya Backup

Thanks for looking. Issues and pull requests are welcome.

## Before you open a pull request

- Run the unit suite on both PHP versions and the e2e gates that touch what
  you changed. `bin/test unit 74`, `bin/test unit 83`, and `bin/e2e admin` at
  least; `bin/e2e roundtrip` for anything in `src/Restore` or `src/Backup`.
  Everything runs in Docker, see the README.
- Keep the constrained PHP limits in `docker/php-constrained.ini`. A change
  that only passes with more memory or a longer time limit does not work on
  the hosting this plugin is for.
- Code under `src/Backup`, `src/Restore`, `src/Db` and `src/Connect` does not
  call WordPress functions. Pass what you need in.
- User-facing text goes through the i18n catalogue and the `dosya-backup-restore-migration`
  text domain. The copy-rules test enforces plain sentences, no em dashes, and
  no exclamation marks.
- `bin/e2e plugin-check` must stay clean. It scans the built zip the way
  WordPress.org does.

## What we will not merge

- Anything that changes what the plugin sends to dosya without also updating
  the "External services" section of `readme.txt`. That section is a promise
  to users and to WordPress.org reviewers.
- A dependency added to `composer.json` `require`. The plugin ships one
  runtime library, Action Scheduler, on purpose.
- Changes to the connect protocol or the API client that are not coordinated
  with us first. Open an issue and describe the change; the server side has to
  move with it.

## Reporting a security issue

Please do not open a public issue. Use GitHub private vulnerability reporting
on this repository or write to security@dosya.dev.

## License

By contributing you agree that your contribution is licensed under
GPL-2.0-or-later, the same licence as the plugin.
