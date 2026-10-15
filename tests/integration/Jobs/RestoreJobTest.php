<?php
namespace Dosya\Backup\Tests\integration\Jobs;

use Dosya\Backup\Archive\BackupReader;
use Dosya\Backup\Archive\FileSource;
use Dosya\Backup\Archive\Source;
use Dosya\Backup\Db\MysqliDb;
use Dosya\Backup\Jobs\BackupJob;
use Dosya\Backup\Jobs\FixedSiteInfo;
use Dosya\Backup\Jobs\LocalStore;
use Dosya\Backup\Jobs\RestoreJob;
use Dosya\Backup\Jobs\RestorePlan;
use Dosya\Backup\Jobs\Steppable;
use Dosya\Backup\Restore\MaintenanceGuard;
use Dosya\Backup\Scan\Excludes;
use Dosya\Backup\Support\Budget;
use Dosya\Backup\Support\Fs;
use Dosya\Backup\Tests\integration\DbTestCase;

final class RestoreJobTest extends DbTestCase
{
    private string $tmp;
    private string $rootA;
    private string $rootB;
    private string $target;
    private MysqliDb $tdb;
    private LocalStore $store;
    private array $fetched = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/rj-' . bin2hex(random_bytes(4));
        $this->rootA = $this->tmp . '/a';
        $this->rootB = $this->tmp . '/b';
        $this->store = new LocalStore($this->tmp . '/store');
        foreach (['index.php' => 'A INDEX', 'wp-config.php' => 'A CONFIG', '.htaccess' => 'A HTACCESS', 'wp-content/uploads/pic.jpg' => 'A PIC'] as $p => $d) {
            Fs::ensureDir(dirname($this->rootA . '/' . $p));
            file_put_contents($this->rootA . '/' . $p, $d);
        }
        foreach (['wp-config.php' => 'B CONFIG', '.htaccess' => 'B HTACCESS', 'old.txt' => 'B OLD'] as $p => $d) {
            Fs::ensureDir(dirname($this->rootB . '/' . $p));
            file_put_contents($this->rootB . '/' . $p, $d);
        }
        Fs::ensureDir($this->rootB . '/wp-content');
        $this->target = 'u' . bin2hex(random_bytes(3)) . '_';
        $this->tdb = new MysqliDb((string) getenv('DOSYA_TEST_DB_HOST'), (string) getenv('DOSYA_TEST_DB_USER'), (string) getenv('DOSYA_TEST_DB_PASS'), (string) getenv('DOSYA_TEST_DB_NAME'), $this->target);
        $this->wpTables($this->db, ['siteurl' => 'http://a.test', 'home' => 'http://a.test', 'active_plugins' => serialize(['akismet/akismet.php']),
            'dosya_backup_settings' => 'A-SETTINGS', 'dosya_backup_site_key' => 'SOURCE-KEY'], 3);
        $this->wpTables($this->tdb, ['siteurl' => 'https://b.test', 'home' => 'https://b.test', 'active_plugins' => serialize(['dosya-backup-restore-migration/dosya-backup.php']),
            'dosya_backup_settings' => 'B-SETTINGS'], 1);
    }

    protected function tearDown(): void
    {
        foreach ($this->tdb->rows('SHOW TABLES') as $r) {
            $n = array_values($r)[0];
            if (strpos($n, $this->target) === 0) { $this->tdb->exec('DROP TABLE ' . $this->tdb->ident($n)); }
        }
        Fs::rrmdir($this->tmp);
        parent::tearDown();
    }

    private function wpTables(MysqliDb $db, array $options, int $posts): void
    {
        $o = $db->ident($db->prefix() . 'options');
        $db->exec("CREATE TABLE $o (option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL UNIQUE, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'yes')");
        foreach ($options as $k => $v) {
            $db->exec("INSERT INTO $o (option_name, option_value) VALUES (" . $db->quote($k) . ', ' . $db->quote($v) . ')');
        }
        $p = $db->ident($db->prefix() . 'posts');
        $db->exec("CREATE TABLE $p (ID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_content LONGTEXT)");
        for ($i = 0; $i < $posts; $i++) {
            $db->exec("INSERT INTO $p (post_content) VALUES ('see http://a.test/page-$i')");
        }
    }

    private function siteA(): FixedSiteInfo
    {
        return new FixedSiteInfo(['home' => 'http://a.test', 'abspath' => $this->rootA, 'content_dir' => $this->rootA . '/wp-content', 'prefix' => $this->prefix]);
    }

    private function siteB(): FixedSiteInfo
    {
        return new FixedSiteInfo(['home' => 'https://b.test', 'abspath' => $this->rootB, 'content_dir' => $this->rootB . '/wp-content', 'prefix' => $this->target]);
    }

    private function guardB(): MaintenanceGuard
    {
        return new MaintenanceGuard($this->rootB . '/wp-content/mu-plugins', $this->rootB . '/wp-content');
    }

    private function drive(Steppable $job, array $cursor): array
    {
        $state = 'queued';
        $tick = 0.0;
        for ($i = 0; $i < 5000; $i++) {
            $clock = function () use (&$tick): float { $tick += 1.0; return $tick; };
            $r = $job->step($state, $cursor, new Budget(3.5, 0, $clock));
            $cursor = json_decode(json_encode($r['cursor'], JSON_THROW_ON_ERROR), true);
            $state = $r['state'];
            if (in_array($state, ['done', 'failed', 'skipped'], true)) {
                return $r;
            }
        }
        $this->fail('job did not finish');
    }

    private function restoreJob(int $status = 200): RestoreJob
    {
        return new RestoreJob($this->tdb, $this->store, $this->siteB(), $this->guardB(), function (string $url) use ($status): array {
            $this->fetched[] = $url;
            return ['status' => $status, 'body' => '<html>ok</html>'];
        });
    }

    private function backupA(): string
    {
        $r = $this->drive(new BackupJob($this->db, $this->store, $this->siteA(), new Excludes(Excludes::defaults())), BackupJob::initialCursor('full'));
        $this->assertSame('done', $r['state']);
        return $r['result']['backup_id'];
    }

    private function opt(string $name): ?string
    {
        $v = $this->tdb->value('SELECT option_value FROM ' . $this->tdb->ident($this->target . 'options') . ' WHERE option_name = ' . $this->tdb->quote($name));
        return $v === null ? null : (string) $v;
    }

    public function testMigratesSiteAOntoSiteB(): void
    {
        $id = $this->backupA();
        $plan = RestorePlan::build($this->store->load($id), $this->siteB(), []);
        $r = $this->drive($this->restoreJob(), RestoreJob::initialCursor($plan));
        $this->assertSame('done', $r['state'], (string) ($r['error'] ?? ''));

        $this->assertSame('B CONFIG', file_get_contents($this->rootB . '/wp-config.php'));
        $this->assertSame('B HTACCESS', file_get_contents($this->rootB . '/.htaccess'));
        $this->assertSame('A INDEX', file_get_contents($this->rootB . '/index.php'));
        $this->assertSame('A PIC', file_get_contents($this->rootB . '/wp-content/uploads/pic.jpg'));

        $this->assertSame('https://b.test', $this->opt('home'));
        $this->assertSame('B-SETTINGS', $this->opt('dosya_backup_settings'));
        $this->assertNull($this->opt('dosya_backup_site_key'));
        $this->assertContains('dosya-backup-restore-migration/dosya-backup.php', unserialize((string) $this->opt('active_plugins')));
        $this->assertSame('3', (string) $this->tdb->value('SELECT COUNT(*) FROM ' . $this->tdb->ident($this->target . 'posts')));
        $this->assertSame('see https://b.test/page-0', $this->tdb->value('SELECT post_content FROM ' . $this->tdb->ident($this->target . 'posts') . ' ORDER BY ID LIMIT 1'));

        $this->assertFileDoesNotExist($this->guardB()->muPluginPath());
        $this->assertFileDoesNotExist($this->guardB()->lockPath());
        $this->assertDirectoryDoesNotExist($this->rootB . '/wp-content/dosya-restore-staging/' . $id);
        $this->assertSame([], $this->tdb->rows("SHOW TABLES LIKE 'dosya\\_%'"));
        $this->assertSame(['https://b.test/'], $this->fetched);
        $this->assertTrue($r['result']['health']['ok']);
        $this->assertTrue($r['result']['migration']);

        $snap = $this->store->load($r['result']['snapshot_id']);
        $this->assertSame(LocalStore::SNAPSHOT_LABEL, $snap->meta['label']);
        $this->assertSame(1, $snap->meta['rows'][$this->target . 'posts']);
    }

    public function testUndoRestoresTheSnapshot(): void
    {
        $id = $this->backupA();
        $r = $this->drive($this->restoreJob(), RestoreJob::initialCursor(RestorePlan::build($this->store->load($id), $this->siteB(), [])));
        $undoPlan = RestorePlan::build($this->store->load($r['result']['snapshot_id']), $this->siteB(), ['scope' => 'db', 'snapshot' => false]);
        $this->assertFalse($undoPlan['migration']);
        $u = $this->drive($this->restoreJob(), RestoreJob::initialCursor($undoPlan));
        $this->assertSame('done', $u['state'], (string) ($u['error'] ?? ''));
        $this->assertSame('1', (string) $this->tdb->value('SELECT COUNT(*) FROM ' . $this->tdb->ident($this->target . 'posts')));
        $this->assertSame('https://b.test', $this->opt('home'));
    }

    public function testFailedHealthCheckIsAWarningNotAFailure(): void
    {
        $id = $this->backupA();
        $r = $this->drive($this->restoreJob(500), RestoreJob::initialCursor(RestorePlan::build($this->store->load($id), $this->siteB(), ['snapshot' => false])));
        $this->assertSame('done', $r['state']);
        $this->assertFalse($r['result']['health']['ok']);
        $this->assertStringContainsString('HTTP 500', implode("\n", $r['result']['warnings']));
    }

    public function testFailureRemovesGuardAndShadows(): void
    {
        $id = $this->backupA();
        $vol = $this->store->volumePath($id, 1);
        $bytes = file_get_contents($vol);
        // byte 200 lies inside a deflate stream here, which makes gzinflate() raise a PHP warning (a PHPUnit error)
        // before any checksum is compared; flip the last byte of the first schema block (its sha256) instead
        $tables = $this->store->load($id)->tables;
        $loc = reset($tables)['schema'];
        $entry = (new BackupReader(function (string $b, int $v): Source { return new FileSource($this->store->volumePath($b, $v)); }))->entry($loc);
        $at = (int) $entry['b'][0][0] + (int) $entry['b'][0][1] - 1;
        $bytes[$at] = chr(ord($bytes[$at]) ^ 0xFF);
        file_put_contents($vol, $bytes);
        $plan = RestorePlan::build($this->store->load($id), $this->siteB(), ['scope' => 'db', 'snapshot' => false]);
        try {
            $this->drive($this->restoreJob(), RestoreJob::initialCursor($plan));
            $this->fail('expected the corrupt volume to stop the restore');
        } catch (\UnexpectedValueException $e) {
        }
        $this->assertFileDoesNotExist($this->guardB()->muPluginPath());
        $this->assertSame([], $this->tdb->rows("SHOW TABLES LIKE 'dosya\\_t\\_%'"));
        $this->assertSame('1', (string) $this->tdb->value('SELECT COUNT(*) FROM ' . $this->tdb->ident($this->target . 'posts')));
    }

    public function testSwapIsIdempotentAfterACrash(): void
    {
        $id = $this->backupA();
        $cursor = RestoreJob::initialCursor(RestorePlan::build($this->store->load($id), $this->siteB(), []));
        $job = $this->restoreJob();
        $state = 'queued';
        $previous = null;
        $tick = 0.0;
        for ($i = 0; $i < 5000; $i++) {
            $clock = function () use (&$tick): float { $tick += 1.0; return $tick; };
            $r = $job->step($state, $cursor, new Budget(3.5, 0, $clock));
            if ($state === 'db_swap') {
                $this->assertSame('finalize', $r['state']);
                $previous = $cursor;
                $cursor = json_decode(json_encode($r['cursor'], JSON_THROW_ON_ERROR), true);
                $state = $r['state'];
                break;
            }
            $cursor = json_decode(json_encode($r['cursor'], JSON_THROW_ON_ERROR), true);
            $state = $r['state'];
        }
        $this->assertNotNull($previous, 'db_swap was never reached');
        // the process "died" before the cursor persisted: run db_swap again from the old cursor
        $again = $job->step('db_swap', $previous, Budget::unlimited());
        $this->assertSame('finalize', $again['state']);
        $cursor = json_decode(json_encode($again['cursor'], JSON_THROW_ON_ERROR), true);
        $state = $again['state'];
        for ($i = 0; $i < 50 && !in_array($state, ['done', 'failed'], true); $i++) {
            $r = $job->step($state, $cursor, Budget::unlimited());
            $cursor = json_decode(json_encode($r['cursor'], JSON_THROW_ON_ERROR), true);
            $state = $r['state'];
        }
        $this->assertSame('done', $state);
        $this->assertSame('3', (string) $this->tdb->value('SELECT COUNT(*) FROM ' . $this->tdb->ident($this->target . 'posts')));
        $this->assertSame('B-SETTINGS', $this->opt('dosya_backup_settings'));
        $this->assertNull($this->opt('dosya_backup_site_key'));
        $this->assertContains('dosya-backup-restore-migration/dosya-backup.php', unserialize((string) $this->opt('active_plugins')));
        $this->assertSame([], $this->tdb->rows("SHOW TABLES LIKE 'dosya\\_%'"));
    }

    public function testSnapshotHonoursRetention(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $r = $this->drive(new BackupJob($this->db, $this->store, $this->siteA(), new Excludes([]), 5), BackupJob::initialCursor('db'));
            $this->assertSame('done', $r['state'], (string) ($r['error'] ?? ''));
            $ids[] = $r['result']['backup_id'];
        }
        $this->assertCount(5, $this->store->list());
        $restored = $ids[4];
        $plan = RestorePlan::build($this->store->load($restored), $this->siteB(), ['scope' => 'db']);
        $this->assertTrue($plan['migration']);
        $job = new RestoreJob($this->tdb, $this->store, $this->siteB(), $this->guardB(),
            function (string $url): array { return ['status' => 200, 'body' => '<html>ok</html>']; }, null, 5);
        $r = $this->drive($job, RestoreJob::initialCursor($plan));
        $this->assertSame('done', $r['state'], (string) ($r['error'] ?? ''));
        $list = $this->store->list();
        $this->assertCount(6, $list);
        $this->assertSame(1, count(array_filter($list, function (array $b): bool { return $b['label'] === LocalStore::SNAPSHOT_LABEL; })));
        $this->assertContains($restored, array_column($list, 'id'));
        $this->assertContains($r['result']['snapshot_id'], array_column($list, 'id'));
    }
}
