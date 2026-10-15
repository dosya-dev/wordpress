<?php
namespace Dosya\Backup\Wp;

use Dosya\Backup\Archive\FileSource;
use Dosya\Backup\Archive\VolumeReader;
use Dosya\Backup\Jobs\StepRunner;
use Dosya\Backup\Support\Budget;

/** Back up, restore and migrate this site. */
final class Cli
{
    /**
     * Back up this site to local storage.
     *
     * ## OPTIONS
     *
     * [--scope=<scope>]
     * : What to back up.
     * ---
     * default: full
     * options:
     *   - full
     *   - db
     *   - files
     * ---
     *
     * [--async]
     * : Queue the job and print its id instead of running it here.
     */
    public function backup(array $args, array $assoc): void
    {
        $this->guard(function () use ($args, $assoc): void {
            $id = Plugin::startBackup((string) ($assoc['scope'] ?? 'full'), isset($assoc['async']));
            if (isset($assoc['async'])) {
                \WP_CLI::line($id);
                return;
            }
            $this->drive($id);
        });
    }

    /**
     * List local backups.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * @subcommand list
     */
    public function list_(array $args, array $assoc): void
    {
        $store = Plugin::store();
        $rows = array_map(function (array $b) use ($store): array {
            return ['id' => $b['id'], 'created' => gmdate('Y-m-d H:i:s', $b['created']), 'scope' => $b['scope'], 'label' => $b['label'],
                'size' => size_format($b['size']), 'volumes' => $b['volumes'], 'dir' => $store->backupDir($b['id'])];
        }, $store->list());
        \WP_CLI\Utils\format_items((string) ($assoc['format'] ?? 'table'), $rows, ['id', 'created', 'scope', 'label', 'size', 'volumes', 'dir']);
    }

    /**
     * Restore a local backup onto this site. Restoring a backup from another site migrates it here.
     *
     * ## OPTIONS
     *
     * <backup-id>
     * : The backup to restore (see `wp dosya list`).
     *
     * [--db-only]
     * : Restore only the database.
     *
     * [--files-only]
     * : Restore only files.
     *
     * [--tables=<tables>]
     * : Comma-separated table names, as they are named in the backup.
     *
     * [--paths=<globs>]
     * : Comma-separated paths or globs to restore, e.g. wp-content/uploads/**
     *
     * [--new-url=<url>]
     * : Address of this site after the restore. Defaults to the current address.
     *
     * [--no-snapshot]
     * : Skip the safety snapshot of the current database.
     *
     * [--async]
     * : Queue the job and print its id.
     *
     * [--yes]
     * : Do not ask for confirmation.
     */
    public function restore(array $args, array $assoc): void
    {
        $this->guard(function () use ($args, $assoc): void {
            $opts = [
                'scope' => isset($assoc['db-only']) ? 'db' : (isset($assoc['files-only']) ? 'files' : 'full'),
                'tables' => isset($assoc['tables']) ? array_values(array_filter(array_map('trim', explode(',', (string) $assoc['tables'])))) : null,
                'paths' => isset($assoc['paths']) ? array_values(array_filter(array_map('trim', explode(',', (string) $assoc['paths'])))) : null,
                'snapshot' => !isset($assoc['no-snapshot']),
            ];
            if (isset($assoc['new-url'])) {
                $opts['new_url'] = (string) $assoc['new-url'];
            }
            \WP_CLI::confirm(sprintf('This replaces this site with backup %s. Continue?', $args[0]), $assoc);
            $id = Plugin::startRestore((string) $args[0], $opts, isset($assoc['async']));
            if (isset($assoc['async'])) {
                \WP_CLI::line($id);
                return;
            }
            $this->drive($id);
        });
    }

    /**
     * Check every volume of a local backup.
     *
     * ## OPTIONS
     *
     * <backup-id>
     * : The backup to check.
     */
    public function verify(array $args): void
    {
        $this->guard(function () use ($args): void {
            $store = Plugin::store();
            $m = $store->load((string) $args[0]);
            foreach ($m->meta['volumes'] as $v) {
                $path = $store->volumePath((string) $args[0], (int) $v['v']);
                if (!is_file($path) || hash_file('sha256', $path) !== $v['sha256']) {
                    \WP_CLI::error(sprintf('Volume %d is missing or damaged.', $v['v']));
                }
                (new VolumeReader(new FileSource($path)))->verify();
            }
            \WP_CLI::success(sprintf('%d volumes verified.', count($m->meta['volumes'])));
        });
    }

    /**
     * Run one time-limited step of a job and print its status as JSON.
     *
     * ## OPTIONS
     *
     * <job-id>
     * : The job.
     */
    public function step(array $args): void
    {
        $this->guard(function () use ($args): void {
            $r = Plugin::runner()->step((string) $args[0], Budget::forRequest());
            if (StepRunner::isTerminal((string) $r['state'])) {
                Mailer::afterStep($r); // the one email per ended job, as the admin page and the scheduler send it
            }
            \WP_CLI::line((string) wp_json_encode($r));
        });
    }

    /**
     * Print the dosya connection (no argument) or a job's status as JSON (with a job id).
     *
     * ## OPTIONS
     *
     * [<job-id>]
     * : The job. Leave out to print the dosya connection instead.
     */
    public function status(array $args): void
    {
        $this->guard(function () use ($args): void {
            if (!isset($args[0])) {
                $this->printConnection();
                return;
            }
            $job = Plugin::jobs()->get((string) $args[0]);
            if ($job === null) {
                \WP_CLI::error('Unknown job.');
            }
            \WP_CLI::line((string) wp_json_encode(['id' => $job['id'], 'kind' => $job['kind'], 'state' => $job['state'],
                'progress' => $job['cursor']['progress'] ?? null, 'error' => $job['error'], 'result' => $job['result']]));
        });
    }

    /**
     * Cancel a queued or running job.
     *
     * ## OPTIONS
     *
     * <job-id>
     * : The job.
     */
    public function cancel(array $args): void
    {
        $this->guard(function () use ($args): void {
            Plugin::jobs()->cancel((string) $args[0]) ? \WP_CLI::success('Cancelled.') : \WP_CLI::warning('That job is not running.');
        });
    }

    /**
     * Connect this site to dosya with a code from the consent page and your own PKCE verifier.
     *
     * ## OPTIONS
     *
     * --code=<code>
     * : The one-time code from the consent page.
     *
     * --verifier=<verifier>
     * : The PKCE code verifier that matches the challenge you gave the consent page.
     */
    public function connect(array $args, array $assoc): void
    {
        $this->guard(function () use ($assoc): void {
            $code = (string) ($assoc['code'] ?? '');
            $verifier = (string) ($assoc['verifier'] ?? '');
            if ($code === '' || $verifier === '') {
                \WP_CLI::error('Both --code and --verifier are required.');
            }
            try {
                Plugin::connector()->exchange($code, $verifier);
            } catch (ConnectException $e) {
                \WP_CLI::error($e->getMessage());
            }
            \WP_CLI::success('Connected to dosya.');
            $this->printConnection();
        });
    }

    /** Disconnect this site from dosya and forget the saved key. */
    public function disconnect(): void
    {
        $this->guard(function (): void {
            Plugin::connector()->disconnect();
            \WP_CLI::success('Disconnected.');
        });
    }

    private function printConnection(): void
    {
        $state = (new SiteKeyStore())->state();
        if ($state === 'unreadable') {
            \WP_CLI::line('connected: unreadable');
            \WP_CLI::line("Your site's security keys changed, so the saved dosya connection cannot be read. Run: wp dosya connect");
            return;
        }
        $c = $state === 'ok' ? Plugin::connection() : null;
        if ($c === null) {
            \WP_CLI::line('connected: no');
            return;
        }
        \WP_CLI::line('connected: yes');
        \WP_CLI::line('site id: ' . $c->siteId);
        \WP_CLI::line('workspace id: ' . $c->workspaceId);
        \WP_CLI::line('caps: ' . (string) wp_json_encode($c->caps));
    }

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    private function drive(string $id): void
    {
        $runner = Plugin::runner();
        $last = '';
        $busy = 0;
        while (true) {
            $limit = Budget::memoryLimitBytes();
            $r = $runner->step($id, new Budget(60.0, $limit > 0 ? (int) ($limit * 0.7) : 0));
            $phase = (string) ($r['progress']['phase'] ?? $r['state']);
            if ($phase !== $last) {
                \WP_CLI::log($phase . '...');
                $last = $phase;
            }
            if ($r['state'] === 'busy') {
                if (++$busy >= 150) {
                    \WP_CLI::error('Another process holds this job. If it is stuck, run: wp dosya cancel ' . $id);
                }
                sleep(2);
                continue;
            }
            $busy = 0;
            if (StepRunner::isTerminal($r['state'])) {
                Mailer::afterStep($r);
                break;
            }
        }
        if ($r['state'] === 'done') {
            \WP_CLI::success((string) wp_json_encode($r['result']));
        } else {
            $err = (string) ($r['error'] ?? '');
            \WP_CLI::error($r['state'] === 'cancelled' ? 'Cancelled.' : ucfirst($r['state']) . ': ' . ($err !== '' ? $err : 'unknown error'));
        }
    }
}
