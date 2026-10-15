<?php
namespace Dosya\Backup\Tests\integration\Db;

use Dosya\Backup\Db\DbImporter;
use Dosya\Backup\Db\MysqliDb;
use Dosya\Backup\Db\UrlPairs;
use Dosya\Backup\Support\Budget;
use Dosya\Backup\Support\Fs;
use Dosya\Backup\Tests\integration\DbTestCase;
use Dosya\Backup\Tests\integration\Support\Fixture;

final class DbImporterTest extends DbTestCase
{
    private string $dir;
    private string $target;
    private MysqliDb $tdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/imp-' . bin2hex(random_bytes(4));
        $this->target = 'u' . bin2hex(random_bytes(3)) . '_';
        $this->tdb = new MysqliDb((string) getenv('DOSYA_TEST_DB_HOST'), (string) getenv('DOSYA_TEST_DB_USER'), (string) getenv('DOSYA_TEST_DB_PASS'), (string) getenv('DOSYA_TEST_DB_NAME'), $this->target);
        $o = $this->db->ident($this->prefix . 'options');
        $this->db->exec("CREATE TABLE $o (option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL UNIQUE, option_value LONGTEXT NOT NULL)");
        $rows = [
            ['siteurl', 'http://a.test'],
            ['home', 'http://a.test'],
            [$this->prefix . 'user_roles', serialize(['administrator' => ['name' => 'Administrator']])],
            [$this->prefix . 'rocket_settings', 'keep-me'],
            ['widget', serialize(['url' => 'http://a.test/page', 'n' => 3])],
        ];
        foreach ($rows as [$n, $v]) {
            $this->db->exec("INSERT INTO $o (option_name, option_value) VALUES (" . $this->db->quote($n) . ', ' . $this->db->quote($v) . ')');
        }
        $um = $this->db->ident($this->prefix . 'usermeta');
        $this->db->exec("CREATE TABLE $um (umeta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT, meta_key VARCHAR(255), meta_value LONGTEXT)");
        foreach ([$this->prefix . 'capabilities', $this->prefix . 'user_level', $this->prefix . 'rocket_meta', 'nickname'] as $k) {
            $this->db->exec("INSERT INTO $um (user_id, meta_key, meta_value) VALUES (1, " . $this->db->quote($k) . ", 'v')");
        }
        $log = $this->db->ident($this->prefix . 'log');
        $this->db->exec("CREATE TABLE $log (msg VARCHAR(20))");
        for ($i = 0; $i < 7; $i++) { $this->db->exec("INSERT INTO $log VALUES ('m$i')"); }
    }

    protected function tearDown(): void
    {
        foreach ($this->tdb->rows('SHOW TABLES') as $r) {
            $n = array_values($r)[0];
            if (strpos($n, $this->target) === 0) { $this->tdb->exec('DROP TABLE ' . $this->tdb->ident($n)); }
        }
        Fs::rrmdir($this->dir);
        parent::tearDown();
    }

    private function runAll(DbImporter $imp, array $c, array $pairs): array
    {
        do { $c = $imp->import($c, Budget::unlimited()); } while (empty($c['done']));
        do { $c = $imp->transform($c, $pairs, Budget::unlimited(), function (): void {}); } while ($c['tr']['stage'] !== 'done');
        return $imp->swap($c, function (): void {});
    }

    public function testMigratesIntoANewPrefixAndUrl(): void
    {
        $other = $this->tdb->ident($this->target . 'other');
        $this->tdb->exec("CREATE TABLE $other (id INT)");
        $live = $this->tdb->ident($this->target . 'options');
        $this->tdb->exec("CREATE TABLE $live (option_id INT PRIMARY KEY, option_name VARCHAR(191), option_value TEXT)");
        $this->tdb->exec("INSERT INTO $live VALUES (1, 'siteurl', 'http://old-target.test')");

        [$m, $reader] = Fixture::dump($this->db, $this->dir);
        $imp = new DbImporter($this->tdb, $reader);
        $c = $this->runAll($imp, $imp->plan($m, null, $this->prefix, $this->target), UrlPairs::build('http://a.test', 'https://b.test'));

        $this->assertSame('https://b.test', $this->tdb->value("SELECT option_value FROM $live WHERE option_name = 'siteurl'"));
        $widget = unserialize((string) $this->tdb->value("SELECT option_value FROM $live WHERE option_name = 'widget'"));
        $this->assertSame(['url' => 'https://b.test/page', 'n' => 3], $widget);
        $this->assertSame('0', (string) $this->tdb->value("SELECT COUNT(*) FROM $other"));
        $this->assertSame([DbImporter::oldName($this->target . 'options')], $c['swapped']);
        $imp->dropOld($c);
        $this->assertNull($this->tdb->value("SHOW TABLES LIKE '" . DbImporter::oldName($this->target . 'options') . "'"));
    }

    public function testPrefixChangeRenamesOnlyCoreKeys(): void
    {
        [$m, $reader] = Fixture::dump($this->db, $this->dir);
        $imp = new DbImporter($this->tdb, $reader);
        $this->runAll($imp, $imp->plan($m, null, $this->prefix, $this->target), []);
        $names = array_column($this->tdb->rows('SELECT option_name FROM ' . $this->tdb->ident($this->target . 'options')), 'option_name');
        $this->assertContains($this->target . 'user_roles', $names);
        $this->assertContains($this->prefix . 'rocket_settings', $names);
        $this->assertNotContains($this->prefix . 'user_roles', $names);
        $keys = array_column($this->tdb->rows('SELECT meta_key FROM ' . $this->tdb->ident($this->target . 'usermeta') . ' ORDER BY umeta_id'), 'meta_key');
        $this->assertSame([$this->target . 'capabilities', $this->target . 'user_level', $this->prefix . 'rocket_meta', 'nickname'], $keys);
    }

    public function testFailedImportLeavesLiveTablesUntouched(): void
    {
        [$m, $reader] = Fixture::dump($this->db, $this->dir);
        $live = $this->tdb->ident($this->target . 'options');
        $this->tdb->exec("CREATE TABLE $live (option_id INT PRIMARY KEY, option_name VARCHAR(191), option_value TEXT)");
        $this->tdb->exec("INSERT INTO $live VALUES (1, 'siteurl', 'http://live.test')");
        $vol = $this->dir . '/v0001.dosya';
        $bytes = file_get_contents($vol);
        $bytes[200] = chr(ord($bytes[200]) ^ 0xFF);
        file_put_contents($vol, $bytes);

        $imp = new DbImporter($this->tdb, $reader);
        try {
            $this->runAll($imp, $imp->plan($m, null, $this->prefix, $this->target), []);
            $this->fail('expected a checksum failure');
        } catch (\UnexpectedValueException $e) {
            $imp->dropShadows($imp->plan($m, null, $this->prefix, $this->target));
        }
        $this->assertSame('http://live.test', $this->tdb->value("SELECT option_value FROM $live WHERE option_id = 1"));
    }

    public function testResumeAfterPartialPartOnTableWithoutUniqueKey(): void
    {
        // a budget that is spent after its first checks makes the dumper close a part after every batch: parts of 3, 3, 1 rows
        $d = 0.0;
        $dumpClock = function () use (&$d): float { $d += 1.0; return $d; };
        [$m, $reader] = Fixture::dump($this->db, $this->dir, 3, new Budget(1.5, 0, $dumpClock));
        $this->assertCount(3, $m->tables[$this->prefix . 'log']['parts']);
        $imp = new DbImporter($this->tdb, $reader);
        $c = $imp->plan($m, [$this->prefix . 'log'], $this->prefix, $this->target);
        $t = 0.0;
        $clock = function () use (&$t): float { $t += 1.0; return $t; };
        $c = $imp->import($c, new Budget(2.5, 0, $clock));
        $this->assertEmpty($c['done']);
        $shadow = $this->tdb->ident(DbImporter::shadowName($this->target . 'log'));
        $this->tdb->exec("INSERT INTO $shadow VALUES ('junk-from-crash')");
        do { $c = $imp->import($c, Budget::unlimited()); } while (empty($c['done']));
        $this->assertSame('7', (string) $this->tdb->value("SELECT COUNT(*) FROM $shadow"));
        $this->assertSame('0', (string) $this->tdb->value("SELECT COUNT(*) FROM $shadow WHERE msg = 'junk-from-crash'"));
    }

    public function testSetsTheConnectionCharsetFromTheManifest(): void
    {
        $tdb = new MysqliDb((string) getenv('DOSYA_TEST_DB_HOST'), (string) getenv('DOSYA_TEST_DB_USER'), (string) getenv('DOSYA_TEST_DB_PASS'), (string) getenv('DOSYA_TEST_DB_NAME'), $this->target, 'latin1');
        $this->assertSame('latin1', $tdb->value('SELECT @@session.character_set_connection'));
        $log = $this->db->ident($this->prefix . 'log');
        $this->db->exec("ALTER TABLE $log CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->db->exec("INSERT INTO $log VALUES ('caf\u{e9} \u{1F600}')");
        [$m, $reader] = Fixture::dump($this->db, $this->dir);
        $m->meta['site']['charset'] = 'utf8mb4';
        $imp = new DbImporter($tdb, $reader);
        $c = $imp->plan($m, [$this->prefix . 'log'], $this->prefix, $this->target);
        $this->assertSame('utf8mb4', $c['charset']);
        do { $c = $imp->import($c, Budget::unlimited()); } while (empty($c['done']));
        // the import ran on utf8mb4 (a 4-byte character survives) and the caller's latin1 is back afterwards
        $this->assertSame("caf\u{e9} \u{1F600}", $this->tdb->value('SELECT msg FROM ' . $this->tdb->ident($c['tables'][0]['shadow']) . " WHERE msg LIKE 'caf%'"));
        $this->assertSame('latin1', $tdb->value('SELECT @@session.character_set_connection'));
        $this->assertSame('latin1', $tdb->charset());
        $imp->dropShadows($c);
    }

    public function testUnsupportedDumpCharsetIsRejected(): void
    {
        [$m, $reader] = Fixture::dump($this->db, $this->dir);
        $m->meta['site']['charset'] = 'gbk';
        $imp = new DbImporter($this->tdb, $reader);
        $c = $imp->plan($m, [$this->prefix . 'log'], $this->prefix, $this->target);
        $this->expectException(\Dosya\Backup\Db\DbException::class);
        $imp->import($c, Budget::unlimited());
    }
}
