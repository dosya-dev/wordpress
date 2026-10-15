<?php
namespace Dosya\Backup\Jobs;

use Dosya\Backup\Db\Db;

final class JobStore
{
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private Db $db;
    private string $table;

    public function __construct(Db $db)
    {
        $this->db = $db;
        $this->table = $db->ident($db->prefix() . 'dosya_jobs');
    }

    public function install(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $this->table . ' (
            id CHAR(32) NOT NULL PRIMARY KEY,
            kind VARCHAR(16) NOT NULL,
            state VARCHAR(24) NOT NULL,
            cursor_json LONGTEXT NOT NULL,
            error TEXT NULL,
            result_json LONGTEXT NULL,
            created_at BIGINT NOT NULL,
            updated_at BIGINT NOT NULL,
            notified_at INT UNSIGNED NULL,
            KEY kind_created (kind, created_at)
        ) DEFAULT CHARSET=utf8mb4');
        if ($this->db->rows('SHOW COLUMNS FROM ' . $this->table . " LIKE 'notified_at'") === []) {
            $this->db->exec('ALTER TABLE ' . $this->table . ' ADD COLUMN notified_at INT UNSIGNED NULL');
            // jobs that ended before this column existed must never send a late email
            $this->db->exec('UPDATE ' . $this->table . ' SET notified_at = updated_at'
                . " WHERE state IN ('done','failed','skipped','cancelled')");
        }
    }

    /** True for exactly one caller per job: the atomic once-only guard for the terminal email. */
    public function markNotified(string $id, int $now): bool
    {
        return $this->db->exec('UPDATE ' . $this->table . ' SET notified_at = ' . $now
            . ' WHERE id = ' . $this->db->quote($id) . ' AND notified_at IS NULL') > 0;
    }

    public function create(string $kind, array $cursor): string
    {
        $id = bin2hex(random_bytes(16));
        $now = time();
        $this->db->exec('INSERT INTO ' . $this->table . ' (id, kind, state, cursor_json, created_at, updated_at) VALUES ('
            . $this->db->quote($id) . ', ' . $this->db->quote($kind) . ", 'queued', "
            . $this->db->quote(json_encode($cursor, self::FLAGS)) . ", $now, $now)");
        return $id;
    }

    public function get(string $id): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
            return null;
        }
        $rows = $this->db->rows('SELECT * FROM ' . $this->table . ' WHERE id = ' . $this->db->quote($id));
        return $rows ? $this->decode($rows[0]) : null;
    }

    public function save(string $id, string $state, array $cursor, ?string $error, ?array $result): void
    {
        $this->db->exec('UPDATE ' . $this->table . ' SET state = ' . $this->db->quote($state)
            . ', cursor_json = ' . $this->db->quote(json_encode($cursor, self::FLAGS))
            . ', error = ' . $this->db->quote($error)
            . ', result_json = ' . $this->db->quote($result === null ? null : json_encode($result, self::FLAGS))
            . ', updated_at = ' . time() . ' WHERE id = ' . $this->db->quote($id)
            . " AND state NOT IN ('done','failed','skipped','cancelled')");
    }

    public function cancel(string $id): bool
    {
        return $this->db->exec('UPDATE ' . $this->table . " SET state = 'cancelled', updated_at = " . time()
            . ' WHERE id = ' . $this->db->quote($id) . " AND state NOT IN ('done','failed','skipped','cancelled')") > 0;
    }

    public function recent(string $kind, int $limit): array
    {
        return array_map([$this, 'decode'], $this->db->rows('SELECT * FROM ' . $this->table . ' WHERE kind = '
            . $this->db->quote($kind) . ' ORDER BY created_at DESC LIMIT ' . max(1, $limit)));
    }

    public function active(): ?array
    {
        $rows = $this->db->rows('SELECT * FROM ' . $this->table
            . " WHERE state NOT IN ('done','failed','skipped','cancelled') ORDER BY created_at DESC LIMIT 1");
        return $rows ? $this->decode($rows[0]) : null;
    }

    private function decode(array $r): array
    {
        return [
            'id' => $r['id'], 'kind' => $r['kind'], 'state' => $r['state'],
            'cursor' => json_decode((string) $r['cursor_json'], true, 512, JSON_THROW_ON_ERROR),
            'error' => $r['error'],
            'result' => $r['result_json'] === null ? null : json_decode((string) $r['result_json'], true, 512, JSON_THROW_ON_ERROR),
            'created_at' => (int) $r['created_at'], 'updated_at' => (int) $r['updated_at'],
            'notified_at' => isset($r['notified_at']) ? (int) $r['notified_at'] : null,
        ];
    }
}
