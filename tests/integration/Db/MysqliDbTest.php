<?php
namespace Dosya\Backup\Tests\integration\Db;

use Dosya\Backup\Db\DbException;
use Dosya\Backup\Db\MysqliDb;
use Dosya\Backup\Db\Schema;
use Dosya\Backup\Tests\integration\DbTestCase;

final class MysqliDbTest extends DbTestCase
{
    public function testQuoteRoundTripsAwkwardStrings(): void
    {
        $t = $this->db->ident($this->prefix . 'q');
        $this->db->exec("CREATE TABLE $t (id INT PRIMARY KEY, v LONGTEXT) DEFAULT CHARSET=utf8mb4");
        $nasty = "it's \"q\" \\ back\nslash \0 null \x1A sub ünïcödé 🎉";
        $this->db->exec("INSERT INTO $t VALUES (1, " . $this->db->quote($nasty) . ')');
        $this->assertSame($nasty, $this->db->value("SELECT v FROM $t WHERE id = 1"));
    }

    public function testErrorsThrow(): void
    {
        $this->expectException(DbException::class);
        $this->db->exec('SELECT * FROM no_such_table_xyz');
    }

    public function testParseHost(): void
    {
        $this->assertSame(['db', 3307, null], MysqliDb::parseHost('db:3307'));
        $this->assertSame(['localhost', null, '/tmp/mysql.sock'], MysqliDb::parseHost('localhost:/tmp/mysql.sock'));
        $this->assertSame(['::1', 3306, null], MysqliDb::parseHost('[::1]:3306'));
        $this->assertSame(['db', null, null], MysqliDb::parseHost('db'));
    }

    public function testSchemaIntrospection(): void
    {
        $t = $this->prefix . 's';
        $this->db->exec('CREATE TABLE ' . $this->db->ident($t) . ' (a BIGINT UNSIGNED NOT NULL, b VARCHAR(10) NOT NULL, c BLOB, d INT AS (a + 1) VIRTUAL, PRIMARY KEY (a, b))');
        $cols = Schema::columns($this->db, $t);
        $this->assertSame(['a', 'b', 'c', 'd'], array_column($cols, 'name'));
        $this->assertSame([true, false, false, true], array_column($cols, 'int'));
        $this->assertSame([false, false, true, false], array_column($cols, 'binary'));
        $this->assertSame([false, false, false, true], array_column($cols, 'generated'));
        $this->assertSame(['a', 'b'], Schema::primaryKey($this->db, $t));
        $this->assertTrue(Schema::hasUniqueKey($this->db, $t));
        $this->assertSame('9007199254740993', Schema::literal($this->db, $cols[0], '9007199254740993'));
        $this->assertSame('0x00ff', Schema::literal($this->db, $cols[2], "\x00\xff"));
        $this->assertSame('NULL', Schema::literal($this->db, $cols[2], null));
    }

    public function testKeysetWhere(): void
    {
        $t = $this->prefix . 'k';
        $this->db->exec('CREATE TABLE ' . $this->db->ident($t) . ' (a BIGINT UNSIGNED NOT NULL, b VARCHAR(10) NOT NULL, PRIMARY KEY (a, b))');
        $cols = Schema::columns($this->db, $t);
        $this->assertSame('`a` > 9007199254740993', Schema::keysetWhere($this->db, $cols, ['a'], ['9007199254740993']));
        $this->assertSame("(`a` > 5 OR (`a` = 5 AND `b` > 'x\\'y'))", Schema::keysetWhere($this->db, $cols, ['a', 'b'], ['5', "x'y"]));
        $this->assertSame('', Schema::keysetWhere($this->db, $cols, ['a', 'b'], null));
    }
}
