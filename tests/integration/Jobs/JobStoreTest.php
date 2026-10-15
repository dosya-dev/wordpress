<?php
namespace Dosya\Backup\Tests\integration\Jobs;

use Dosya\Backup\Jobs\JobStore;
use Dosya\Backup\Tests\integration\DbTestCase;

final class JobStoreTest extends DbTestCase
{
    public function testMarkNotifiedIsOnceOnly(): void
    {
        $jobs = new JobStore($this->db);
        $jobs->install();
        $id = $jobs->create('backup', []);
        $this->assertNull($jobs->get($id)['notified_at']);
        $this->assertTrue($jobs->markNotified($id, 1760000000));
        $this->assertFalse($jobs->markNotified($id, 1760000001));
        $this->assertSame(1760000000, $jobs->get($id)['notified_at']);
    }

    public function testInstallAddsColumnToExistingTableAndBackfillsEndedJobs(): void
    {
        $table = $this->db->ident($this->prefix . 'dosya_jobs');
        $this->db->exec('CREATE TABLE ' . $table . ' (
            id CHAR(32) NOT NULL PRIMARY KEY, kind VARCHAR(16) NOT NULL, state VARCHAR(24) NOT NULL,
            cursor_json LONGTEXT NOT NULL, error TEXT NULL, result_json LONGTEXT NULL,
            created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, KEY kind_created (kind, created_at)
        ) DEFAULT CHARSET=utf8mb4');
        $this->db->exec('INSERT INTO ' . $table . " (id, kind, state, cursor_json, created_at, updated_at) VALUES ('" . str_repeat('a', 32) . "', 'backup', 'failed', '{}', 100, 200)");
        $this->assertSame([], $this->db->rows('SHOW COLUMNS FROM ' . $table . " LIKE 'notified_at'"));
        $jobs = new JobStore($this->db);
        $jobs->install();
        $this->assertCount(1, $this->db->rows('SHOW COLUMNS FROM ' . $table . " LIKE 'notified_at'"));
        $jobs->install();
        $this->assertSame(200, $jobs->get(str_repeat('a', 32))['notified_at']);
        $this->assertFalse($jobs->markNotified(str_repeat('a', 32), 300));
    }
}
