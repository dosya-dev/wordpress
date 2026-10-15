<?php
namespace Dosya\Backup\Tests\integration;

use Dosya\Backup\Db\MysqliDb;
use PHPUnit\Framework\TestCase;

abstract class DbTestCase extends TestCase
{
    protected MysqliDb $db;
    protected string $prefix;

    protected function setUp(): void
    {
        $host = getenv('DOSYA_TEST_DB_HOST');
        if (!$host) {
            $this->markTestSkipped('DOSYA_TEST_DB_HOST not set; run through bin/test integration');
        }
        $this->prefix = 't' . bin2hex(random_bytes(3)) . '_';
        $this->db = new MysqliDb($host, (string) getenv('DOSYA_TEST_DB_USER'), (string) getenv('DOSYA_TEST_DB_PASS'),
            (string) getenv('DOSYA_TEST_DB_NAME'), $this->prefix);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->db->rows('SHOW FULL TABLES') as $row) {
            [$name, $type] = array_values($row);
            if (strpos($name, $this->prefix) === 0 || strpos($name, 'dosya_t_') === 0 || strpos($name, 'dosya_o_') === 0) {
                $this->db->exec(($type === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . $this->db->ident($name));
            }
        }
    }
}
