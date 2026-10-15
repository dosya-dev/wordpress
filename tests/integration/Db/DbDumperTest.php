<?php
namespace Dosya\Backup\Tests\integration\Db;

use Dosya\Backup\Support\Budget;
use Dosya\Backup\Support\Fs;
use Dosya\Backup\Tests\integration\DbTestCase;
use Dosya\Backup\Tests\integration\Support\Fixture;

final class DbDumperTest extends DbTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/dump-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        Fs::rrmdir($this->dir);
        parent::tearDown();
    }

    private function t(string $name): string { return $this->db->ident($this->prefix . $name); }

    private function allRows(string $sqlTable, string $order): array
    {
        return $this->db->rows("SELECT * FROM $sqlTable ORDER BY $order");
    }

    public function testRoundTripsAwkwardValues(): void
    {
        $this->db->exec('CREATE TABLE ' . $this->t('posts') . " (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title TEXT, body LONGTEXT, raw BLOB, d DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00', g INT AS (id * 2) VIRTUAL) DEFAULT CHARSET=utf8mb4");
        $vals = ["it's", "multi\nline\r\n\"quoted\" \\ back", 'ünïcödé 🎉', '', null];
        foreach ($vals as $i => $v) {
            $this->db->exec('INSERT INTO ' . $this->t('posts') . ' (id, title, body, raw) VALUES (' . ($i === 0 ? 0 : $i) . ', ' . $this->db->quote($v) . ', ' . $this->db->quote(str_repeat((string) $v, 3)) . ', ' . ($v === null ? 'NULL' : '0x' . bin2hex("\0\xff" . $v)) . ')');
        }
        [$m, $r] = Fixture::dump($this->db, $this->dir, 2);
        Fixture::replay($this->db, $m, $r, $this->prefix . 'posts', $this->prefix . 'copy');
        $this->assertSame($this->allRows($this->t('posts'), 'id'), $this->allRows($this->t('copy'), 'id'));
        $this->assertSame(5, $m->meta['rows'][$this->prefix . 'posts']);
    }

    public function testBigintPrimaryKeyAbove2pow53(): void
    {
        $this->db->exec('CREATE TABLE ' . $this->t('big') . ' (id BIGINT UNSIGNED PRIMARY KEY, v INT)');
        foreach (['9007199254740993', '9007199254740994', '9007199254740995', '18446744073709551615'] as $i => $id) {
            $this->db->exec('INSERT INTO ' . $this->t('big') . " VALUES ($id, $i)");
        }
        [$m, $r] = Fixture::dump($this->db, $this->dir, 1);
        Fixture::replay($this->db, $m, $r, $this->prefix . 'big', $this->prefix . 'bigcopy');
        $this->assertSame($this->allRows($this->t('big'), 'id'), $this->allRows($this->t('bigcopy'), 'id'));
    }

    public function testCompositePrimaryKey(): void
    {
        $this->db->exec('CREATE TABLE ' . $this->t('comp') . ' (a INT NOT NULL, b VARCHAR(20) NOT NULL, v INT, PRIMARY KEY (a, b))');
        foreach ([[1, 'b'], [1, 'a'], [2, 'a'], [1, 'c'], [3, "x'y"]] as $i => [$a, $b]) {
            $this->db->exec('INSERT INTO ' . $this->t('comp') . " VALUES ($a, " . $this->db->quote($b) . ", $i)");
        }
        [$m, $r] = Fixture::dump($this->db, $this->dir, 2);
        Fixture::replay($this->db, $m, $r, $this->prefix . 'comp', $this->prefix . 'compcopy');
        $this->assertSame($this->allRows($this->t('comp'), 'a, b'), $this->allRows($this->t('compcopy'), 'a, b'));
    }

    public function testTableWithoutPrimaryKey(): void
    {
        $this->db->exec('CREATE TABLE ' . $this->t('log') . ' (msg VARCHAR(20))');
        for ($i = 0; $i < 7; $i++) { $this->db->exec('INSERT INTO ' . $this->t('log') . " VALUES ('m$i')"); }
        [$m, $r] = Fixture::dump($this->db, $this->dir, 3);
        Fixture::replay($this->db, $m, $r, $this->prefix . 'log', $this->prefix . 'logcopy');
        $this->assertSame($this->allRows($this->t('log'), 'msg'), $this->allRows($this->t('logcopy'), 'msg'));
    }

    public function testTransientsAreSkipped(): void
    {
        $this->db->exec('CREATE TABLE ' . $this->t('options') . ' (option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) UNIQUE, option_value LONGTEXT)');
        foreach (['siteurl', '_transient_x', '_site_transient_y', 'my_transient_kept'] as $n) {
            $this->db->exec('INSERT INTO ' . $this->t('options') . " (option_name, option_value) VALUES ('$n', 'v')");
        }
        [$m, $r] = Fixture::dump($this->db, $this->dir);
        Fixture::replay($this->db, $m, $r, $this->prefix . 'options', $this->prefix . 'optcopy');
        $this->assertSame(['my_transient_kept', 'siteurl'], array_column($this->allRows($this->t('optcopy'), 'option_name'), 'option_name'));
    }

    public function testResumesAcrossTinyBudgets(): void
    {
        $this->db->exec('CREATE TABLE ' . $this->t('many') . ' (id INT PRIMARY KEY, v VARCHAR(50))');
        $values = [];
        for ($i = 1; $i <= 500; $i++) { $values[] = "($i, 'row $i')"; }
        $this->db->exec('INSERT INTO ' . $this->t('many') . ' VALUES ' . implode(',', $values));
        $t = 0.0;
        $clock = function () use (&$t): float { $t += 1.0; return $t; };
        [$m, $r] = Fixture::dump($this->db, $this->dir, 50, new Budget(1.5, 0, $clock));
        $this->assertGreaterThan(1, count($m->tables[$this->prefix . 'many']['parts']));
        Fixture::replay($this->db, $m, $r, $this->prefix . 'many', $this->prefix . 'manycopy');
        $this->assertSame('500', (string) $this->db->value('SELECT COUNT(*) FROM ' . $this->t('manycopy')));
    }

    public function testViewsAndTriggersAreDumpedWithoutDefiner(): void
    {
        $this->db->exec('CREATE TABLE ' . $this->t('a') . ' (id INT PRIMARY KEY, n INT)');
        $this->db->exec('CREATE VIEW ' . $this->t('v') . ' AS SELECT id FROM ' . $this->t('a'));
        $this->db->exec('CREATE TRIGGER ' . $this->t('trg') . ' BEFORE INSERT ON ' . $this->t('a') . ' FOR EACH ROW SET NEW.n = 1');
        [$m, $r] = Fixture::dump($this->db, $this->dir);
        $view = $r->readAll($m->views[$this->prefix . 'v']);
        $trig = $r->readAll($m->triggers[$this->prefix . 'trg']);
        $this->assertDoesNotMatchRegularExpression('/DEFINER\s*=/', $view);
        $this->assertDoesNotMatchRegularExpression('/DEFINER\s*=/', $trig);
        $this->assertStringContainsString('VIEW', $view);
        $this->assertArrayNotHasKey($this->prefix . 'v', $m->tables);
        $this->db->exec('DROP TRIGGER ' . $this->t('trg'));
    }
    public function testBinaryPrimaryKeyRoundTripsAcrossSteps(): void
    {
        $this->db->exec('CREATE TABLE ' . $this->t('bin') . ' (k BINARY(16) PRIMARY KEY, v INT)');
        $keys = ["\0" . random_bytes(15), "\xff" . random_bytes(15), random_bytes(16), random_bytes(16), random_bytes(16), random_bytes(16)];
        foreach ($keys as $i => $k) {
            $this->db->exec('INSERT INTO ' . $this->t('bin') . ' VALUES (0x' . bin2hex($k) . ", $i)");
        }
        $t = 0.0;
        $clock = function () use (&$t): float { $t += 1.0; return $t; };
        [$m, $r] = Fixture::dump($this->db, $this->dir, 2, new Budget(1.5, 0, $clock));
        Fixture::replay($this->db, $m, $r, $this->prefix . 'bin', $this->prefix . 'bincopy');
        $this->assertSame($this->allRows($this->t('bin'), 'k'), $this->allRows($this->t('bincopy'), 'k'));
        $this->assertSame(6, $m->meta['rows'][$this->prefix . 'bin']);
    }

    public function testBatchSizeAdaptsToRowLength(): void
    {
        $this->db->exec('CREATE TABLE ' . $this->t('fat') . ' (id INT PRIMARY KEY, body LONGTEXT)');
        for ($j = 0; $j < 2; $j++) {
            for ($i = 1; $i <= 10; $i++) {
                $this->db->exec('INSERT INTO ' . $this->t('fat') . ' VALUES (' . ($j * 10 + $i) . ", '" . str_repeat('x', 300000) . "')");
            }
        }
        $this->db->exec('ANALYZE TABLE ' . $this->t('fat'));
        mkdir($this->dir, 0777, true);
        $id = bin2hex(random_bytes(16));
        $sinkFor = function (int $n, int $at): \Dosya\Backup\Archive\Sink { return new \Dosya\Backup\Archive\FileSink(sprintf('%s/v%04d.dosya', $this->dir, $n), $at); };
        $vs = new \Dosya\Backup\Archive\VolumeSet($id, time(), 1 << 26, $sinkFor, function (array $e): void {});
        $d = new \Dosya\Backup\Db\DbDumper($this->db, 1000);
        $c = $d->step($d->plan(false, []), Budget::unlimited(), $vs);
        $this->assertLessThan(100, $c['batch']);
        $this->assertGreaterThanOrEqual(10, $c['batch']);
    }
}
