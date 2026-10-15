<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI).
namespace Dosya\Backup\Jobs;

use Dosya\Backup\Archive\BackupReader;
use Dosya\Backup\Archive\FileSource;
use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\Manifest;
use Dosya\Backup\Archive\Source;
use Dosya\Backup\Db\Db;
use Dosya\Backup\Db\DbImporter;
use Dosya\Backup\Db\Escape;
use Dosya\Backup\Restore\FileRestorer;
use Dosya\Backup\Restore\MaintenanceGuard;
use Dosya\Backup\Scan\Excludes;
use Dosya\Backup\Support\Budget;
use Dosya\Backup\Support\Text;

final class RestoreJob implements Steppable
{
    public const PLUGIN = 'dosya-backup-restore-migration/dosya-backup.php';
    private const GUARDED = ['queued', 'snapshot', 'files_fetch', 'db_import', 'db_transform', 'files_move', 'db_swap', 'finalize'];
    private const GUARD_TTL = 900;

    private Db $db;
    private LocalStore $store;
    private SiteInfo $site;
    private MaintenanceGuard $guard;
    /** @var callable */
    private $httpGet;
    /** @var callable|null */
    private $afterSwap;
    private int $keep;
    private ?Manifest $manifest = null;

    /** @param int $keep the user's retention setting, which the safety snapshot's prune must honour */
    public function __construct(Db $db, LocalStore $store, SiteInfo $site, MaintenanceGuard $guard, callable $httpGet, ?callable $afterSwap = null, int $keep = 3)
    {
        $this->keep = $keep;
        $this->db = $db;
        $this->store = $store;
        $this->site = $site;
        $this->guard = $guard;
        $this->httpGet = $httpGet;
        $this->afterSwap = $afterSwap;
    }

    public static function initialCursor(array $plan): array
    {
        return ['plan' => $plan, 'warnings' => [], 'progress' => ['phase' => 'queued']];
    }

    public function step(string $state, array $c, Budget $b): array
    {
        try {
            if (in_array($state, self::GUARDED, true)) {
                $this->guard->install(time() + self::GUARD_TTL);
            }
            return $this->dispatch($state, $c, $b);
        } catch (\Throwable $e) {
            $this->abort($c);
            throw $e;
        }
    }

    private function dispatch(string $state, array $c, Budget $b): array
    {
        $plan = $c['plan'];
        $warn = $this->warner($c);
        switch ($state) {
            case 'queued':
                if ($plan['scope'] !== 'db') {
                    $this->assertReferencedVolumes($this->manifest($plan));
                }
                return $this->next($plan['snapshot'] ? 'snapshot' : 'files_fetch', $c);
            case 'snapshot':
                return $this->snapshot($c, $b);
            case 'files_fetch':
                if ($plan['scope'] === 'db') {
                    return $this->next('db_import', $c);
                }
                $c['fr'] = $this->files($plan)->fetch($c['fr'] ?? FileRestorer::start(), $b, $warn);
                $c['progress'] = ['phase' => 'files_fetch', 'done' => $c['fr']['i'], 'total' => count($this->manifest($plan)->files)];
                return $c['fr']['done'] ? $this->next('db_import', $c) : ['state' => 'files_fetch', 'cursor' => $c];
            case 'db_import':
                if ($plan['scope'] === 'files' || !$this->manifest($plan)->tables) {
                    return $this->next('files_move', $c);
                }
                $imp = $this->importer();
                $c['imp'] = $imp->import($c['imp'] ?? $imp->plan($this->manifest($plan), $plan['tables'], $plan['old']['prefix'], $plan['new']['prefix']), $b);
                $c['progress'] = ['phase' => 'db_import', 'done' => $c['imp']['i'], 'total' => count($c['imp']['tables'])];
                return empty($c['imp']['done']) ? ['state' => 'db_import', 'cursor' => $c] : $this->next('db_transform', $c);
            case 'db_transform':
                if (!isset($c['imp'])) {
                    return $this->next('files_move', $c);
                }
                $c['imp'] = $this->importer()->transform($c['imp'], $plan['pairs'], $b, $warn);
                return $c['imp']['tr']['stage'] === 'done' ? $this->next('files_move', $c) : ['state' => 'db_transform', 'cursor' => $c];
            case 'files_move':
                if ($plan['scope'] === 'db') {
                    return $this->next('db_swap', $c);
                }
                $c['fr'] = $this->files($plan)->move($c['fr'], $b, $warn);
                return $c['fr']['moved'] ? $this->next('db_swap', $c) : ['state' => 'files_move', 'cursor' => $c];
            case 'db_swap':
                if (isset($c['imp'])) {
                    $options = $this->importedOptionsTable($c);
                    $live = $this->liveTables();
                    if ($this->swapAlreadyDone($c['imp'], $live)) {
                        $c['imp']['swapped'] = [];
                        foreach ($c['imp']['tables'] as $t) {
                            $o = DbImporter::oldName($t['new']);
                            if (isset($live[$o])) {
                                $c['imp']['swapped'][] = $o;
                            }
                        }
                        $keep = $options === null ? [] : $this->dosyaOptions(DbImporter::oldName($options));
                    } else {
                        $keep = $options === null ? [] : $this->dosyaOptions($options);
                        $c['imp'] = $this->importer()->swap($c['imp'], $warn);
                    }
                    if ($options !== null) {
                        try {
                            $this->db->exec('DELETE FROM ' . $this->db->ident($options) . " WHERE option_name LIKE 'dosya\\_%'");
                            $this->writeOptions($options, $keep);
                            $this->ensurePluginActive($options);
                        } catch (\Throwable $e) {
                            $warn('Could not finish the post-swap step: ' . $e->getMessage());
                        }
                    }
                }
                if ($this->afterSwap !== null) {
                    try {
                        ($this->afterSwap)($plan);
                    } catch (\Throwable $e) {
                        $warn('Could not finish the post-swap step: ' . $e->getMessage());
                    }
                }
                return $this->next('finalize', $c);
            case 'finalize':
                $this->guard->remove();
                return $this->next($plan['health'] ? 'health' : 'cleanup', $c);
            case 'health':
                $r = ($this->httpGet)($plan['new']['home'] . '/');
                $status = (int) $r['status'];
                $body = (string) $r['body'];
                $ok = $status >= 200 && $status < 400 && stripos($body, 'critical error') === false
                    && stripos($body, 'Error establishing a database connection') === false;
                $c['health'] = ['ok' => $ok, 'status' => $status];
                if (!$ok) {
                    $warn(sprintf('The site did not load cleanly after the restore (HTTP %d).', $status)
                        . (isset($c['snapshot_id']) ? ' You can undo the restore from the Backups list.' : ''));
                }
                return $this->next('cleanup', $c);
            case 'cleanup':
                if (isset($c['imp'])) {
                    $this->importer()->dropOld($c['imp']);
                }
                if ($plan['scope'] !== 'db') {
                    $this->files($plan)->cleanup();
                }
                return ['state' => 'done', 'cursor' => ['plan' => ['backup_id' => $plan['backup_id']], 'progress' => ['phase' => 'done']],
                    'result' => ['backup_id' => $plan['backup_id'], 'snapshot_id' => $c['snapshot_id'] ?? null, 'migration' => $plan['migration'],
                        'health' => $c['health'] ?? null, 'collations' => $c['imp']['collations'] ?? [], 'warnings' => $c['warnings']]];
        }
        throw new \LogicException('unknown restore state ' . $state);
    }

    private function snapshot(array $c, Budget $b): array
    {
        $job = new BackupJob($this->db, $this->store, $this->site, new Excludes([]), $this->keep, 67108864, Format::BLOCK_MAX, [$c['plan']['backup_id']]);
        $r = $job->step($c['snap_state'] ?? 'queued', $c['snap'] ?? BackupJob::initialCursor('db', LocalStore::SNAPSHOT_LABEL), $b);
        if ($r['state'] === 'done') {
            $c['snapshot_id'] = $r['result']['backup_id'];
            unset($c['snap'], $c['snap_state']);
            return $this->next('files_fetch', $c);
        }
        if ($r['state'] === 'failed' || $r['state'] === 'skipped') {
            throw new \RuntimeException('The safety snapshot could not be made, so nothing was changed: ' . ($r['error'] ?? 'unknown error'));
        }
        $c['snap'] = $r['cursor'];
        $c['snap_state'] = $r['state'];
        $c['progress'] = ['phase' => 'snapshot'];
        return ['state' => 'snapshot', 'cursor' => $c];
    }

    private function abort(array $c): void
    {
        try {
            $this->guard->remove();
            $plan = $c['plan'];
            if ($plan['scope'] !== 'files') {
                $imp = $c['imp'] ?? $this->importer()->plan($this->manifest($plan), $plan['tables'], $plan['old']['prefix'], $plan['new']['prefix']);
                if ($imp['swapped'] === null) {
                    $this->importer()->dropShadows($imp);
                }
            }
            if ($c['plan']['scope'] !== 'db') {
                $this->files($c['plan'])->cleanup();
            }
        } catch (\Throwable $ignored) {
            // the original error is what the user needs to see
        }
    }

    /** @return array<string,string> table name => type */
    private function liveTables(): array
    {
        $live = [];
        foreach ($this->db->rows('SHOW FULL TABLES') as $r) {
            [$n, $type] = array_values($r);
            $live[$n] = $type;
        }
        return $live;
    }

    private function swapAlreadyDone(array $imp, array $live): bool
    {
        foreach ($imp['tables'] as $t) {
            if (isset($live[$t['shadow']]) || !isset($live[$t['new']])) {
                return false;
            }
        }
        return true;
    }

    private function importedOptionsTable(array $c): ?string
    {
        $want = $c['plan']['new']['prefix'] . 'options';
        return in_array($want, array_column($c['imp']['tables'], 'new'), true) ? $want : null;
    }

    private function dosyaOptions(string $table): array
    {
        if ($this->db->value('SHOW TABLES LIKE ' . $this->db->quote(Escape::like($table))) === null) {
            return [];
        }
        return $this->db->rows('SELECT option_name, option_value, autoload FROM ' . $this->db->ident($table) . " WHERE option_name LIKE 'dosya\\_%'");
    }

    private function writeOptions(string $table, array $rows): void
    {
        foreach ($rows as $r) {
            $this->upsertOption($table, (string) $r['option_name'], (string) $r['option_value'], (string) $r['autoload']);
        }
    }

    private function ensurePluginActive(string $table): void
    {
        $v = $this->db->value('SELECT option_value FROM ' . $this->db->ident($table) . " WHERE option_name = 'active_plugins'");
        $list = is_string($v) && preg_match('/^a:\d+:\{/', $v) === 1 ? unserialize($v, ['allowed_classes' => false]) : [];
        $list = is_array($list) ? array_values($list) : [];
        if (!in_array(self::PLUGIN, $list, true)) {
            $list[] = self::PLUGIN;
            $this->upsertOption($table, 'active_plugins', serialize($list), 'yes');
        }
        $this->db->exec('DELETE FROM ' . $this->db->ident($table) . " WHERE option_name = 'rewrite_rules'");
    }

    private function upsertOption(string $table, string $name, string $value, string $autoload): void
    {
        $this->db->exec('INSERT INTO ' . $this->db->ident($table) . ' (option_name, option_value, autoload) VALUES ('
            . $this->db->quote($name) . ', ' . $this->db->quote($value) . ', ' . $this->db->quote($autoload)
            . ') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)');
    }

    private function manifest(array $plan): Manifest
    {
        return $this->manifest ?? ($this->manifest = $this->store->load($plan['backup_id']));
    }

    private function reader(): BackupReader
    {
        return new BackupReader(function (string $b, int $v): Source {
            $path = $this->store->volumePath($b, $v);
            if (!is_file($path)) {
                throw new \RuntimeException(self::missingMessage($b));
            }
            return new FileSource($path);
        });
    }

    /** An incremental backup reads volumes of earlier backups; refuse before changing anything when one is gone. */
    private function assertReferencedVolumes(Manifest $m): void
    {
        foreach ($m->publicView()['refs'] as $r) {
            if (!is_file($this->store->volumePath((string) $r['b'], (int) $r['v']))) {
                throw new \RuntimeException(self::missingMessage((string) $r['b']));
            }
        }
    }

    private static function missingMessage(string $backupId): string
    {
        return sprintf('This backup builds on the earlier backup %s, which is no longer on this site, so nothing was restored.', $backupId);
    }

    private function importer(): DbImporter
    {
        return new DbImporter($this->db, $this->reader());
    }

    private function files(array $plan): FileRestorer
    {
        return new FileRestorer($this->site->absPath(), $this->site->contentDir() . '/dosya-restore-staging/' . $plan['backup_id'],
            $this->manifest($plan), $this->reader(), RestorePlan::selector($plan));
    }

    private function warner(array &$c): callable
    {
        return function (string $w) use (&$c): void {
            if (count($c['warnings']) < 200) {
                $c['warnings'][] = Text::utf8($w);
            }
        };
    }

    private function next(string $state, array $c): array
    {
        $c['progress'] = ['phase' => $state];
        return ['state' => $state, 'cursor' => $c];
    }
}
