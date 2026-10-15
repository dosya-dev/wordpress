<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI).
namespace Dosya\Backup\Db;

use Dosya\Backup\Archive\ArchiveOut;
use Dosya\Backup\Archive\Format;
use Dosya\Backup\Support\Budget;

final class DbDumper
{
    public const STMT_END = "\n-- dosya:end\n";
    public const TABLE_TOKEN = '__DOSYA_TABLE__';
    private const PART_TARGET = 4194304;
    private const STMT_TARGET = 1048576;
    private const DEFINER_RE = '/\sDEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|[^\s@]+)@(`[^`]*`|\'[^\']*\'|\S+)/i';

    private Db $db;
    private int $batch;

    public function __construct(Db $db, int $batch = 1000)
    {
        $this->db = $db;
        $this->batch = $batch;
    }

    public function plan(bool $multisite, array $excludeTables): array
    {
        $tables = [];
        $views = [];
        foreach ($this->db->rows('SHOW FULL TABLES') as $row) {
            [$name, $type] = array_values($row);
            if ($type === 'VIEW') { $views[] = $name; } else { $tables[] = $name; }
        }
        $prefix = $this->db->prefix();
        $tables = TableSelector::select($tables, $prefix, $multisite, $excludeTables);
        $views = TableSelector::select($views, $prefix, $multisite, $excludeTables);
        return ['tables' => $this->order($tables), 'views' => $views, 'i' => 0, 'stage' => 'schema', 'last' => null,
            'offset' => 0, 'part' => 0, 'batch' => $this->batch, 'rows' => [], 'extras' => false, 'done' => false];
    }

    public function step(array $c, Budget $b, ArchiveOut $out): array
    {
        $progressed = false;
        while ($c['i'] < count($c['tables'])) {
            if ($progressed && $b->exhausted()) {
                return $c;
            }
            $progressed = true;
            $t = $c['tables'][$c['i']];
            if ($c['stage'] === 'schema') {
                $this->dumpSchema($t, $out);
                $c['batch'] = $this->batchFor($t);
                $c['stage'] = 'rows';
                $c['rows'][$t] = 0;
                continue;
            }
            if ($this->dumpRows($t, $c, $b, $out)) {
                $c['i']++;
                $c['stage'] = 'schema';
                $c['last'] = null;
                $c['offset'] = 0;
                $c['part'] = 0;
            }
        }
        if (!$c['extras']) {
            $this->dumpExtras($c, $out);
            $c['extras'] = true;
        }
        $c['done'] = true;
        return $c;
    }

    /** @return string[] */
    public static function splitStatements(string $sql): array
    {
        $out = [];
        foreach (explode(self::STMT_END, $sql) as $s) {
            if (trim($s) !== '') {
                $out[] = $s;
            }
        }
        return $out;
    }

    private function dumpSchema(string $t, ArchiveOut $out): void
    {
        $row = $this->db->rows('SHOW CREATE TABLE ' . $this->db->ident($t))[0];
        $ddl = (string) ($row['Create Table'] ?? array_values($row)[1]);
        $head = 'CREATE TABLE ' . $this->db->ident($t);
        if (strncmp($ddl, $head, strlen($head)) !== 0) {
            throw new DbException('unexpected CREATE TABLE output for ' . $t);
        }
        $out->beginEntry(Format::TYPE_DB_SCHEMA, 'db/' . $t . '/schema.sql', 0644, time(), true);
        $out->write('CREATE TABLE `' . self::TABLE_TOKEN . '`' . substr($ddl, strlen($head)) . self::STMT_END);
        $out->endEntry();
    }

    private function dumpRows(string $t, array &$c, Budget $b, ArchiveOut $out): bool
    {
        $cols = Schema::columns($this->db, $t);
        $pk = Schema::primaryKey($this->db, $t);
        $insertCols = array_values(array_filter($cols, function (array $col): bool { return !$col['generated']; }));
        $head = 'INSERT INTO `' . self::TABLE_TOKEN . '` (' . implode(',', array_map(function (array $col): string {
            return $this->db->ident($col['name']);
        }, $insertCols)) . ') VALUES ';

        $c['part']++;
        $out->beginEntry(Format::TYPE_DB_PART, sprintf('db/%s/part-%04d.sql', $t, $c['part']), 0644, time(), true);
        $written = 0;
        $stmt = '';
        $finished = false;
        while (true) {
            $rows = $this->db->rows($this->batchSql($t, $cols, $pk, $c));
            $n = count($rows);
            $batchBytes = 0;
            foreach ($rows as $r) {
                $vals = [];
                foreach ($insertCols as $col) {
                    $vals[] = Schema::literal($this->db, $col, $r[$col['name']] === null ? null : (string) $r[$col['name']]);
                }
                $tuple = '(' . implode(',', $vals) . ')';
                $batchBytes += strlen($tuple);
                $stmt = $stmt === '' ? $head . $tuple : $stmt . ',' . $tuple;
                if (strlen($stmt) >= self::STMT_TARGET) {
                    $out->write($stmt . ';' . self::STMT_END);
                    $written += strlen($stmt);
                    $stmt = '';
                }
            }
            if ($n > 0) {
                if ($pk) {
                    $last = $rows[$n - 1];
                    $c['last'] = Schema::packKey($cols, $pk, $last);
                } else {
                    $c['offset'] += $n;
                }
                $c['rows'][$t] += $n;
            }
            if ($n < $c['batch']) {
                $finished = true;
                break;
            }
            if ($batchBytes > 8388608 && $c['batch'] > 10) {
                $c['batch'] = (int) max(10, $c['batch'] / 2);
            }
            if ($written + strlen($stmt) >= self::PART_TARGET || $b->exhausted()) {
                break;
            }
        }
        if ($stmt !== '') {
            $out->write($stmt . ';' . self::STMT_END);
        }
        $out->endEntry();
        return $finished;
    }

    private function batchSql(string $t, array $cols, array $pk, array $c): string
    {
        $sql = 'SELECT ' . implode(',', array_map(function (array $col): string { return $this->db->ident($col['name']); }, $cols))
            . ' FROM ' . $this->db->ident($t);
        $where = [];
        if (preg_match('/^' . preg_quote($this->db->prefix(), '/') . '(\d+_)?options$/', $t) === 1) {
            $where[] = "`option_name` NOT LIKE '\\_transient\\_%' AND `option_name` NOT LIKE '\\_site\\_transient\\_%'";
        }
        $keyset = Schema::keysetWhere($this->db, $cols, $pk, $c['last'] === null ? null : Schema::unpackKey($c['last']));
        if ($keyset !== '') {
            $where[] = $keyset;
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        if ($pk) {
            return $sql . ' ORDER BY ' . implode(',', array_map([$this->db, 'ident'], $pk)) . ' LIMIT ' . (int) $c['batch'];
        }
        return $sql . ' LIMIT ' . (int) $c['offset'] . ',' . (int) $c['batch'];
    }

    private function batchFor(string $t): int
    {
        $avg = (int) $this->db->value('SELECT AVG_ROW_LENGTH FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '
            . $this->db->quote($t));
        if ($avg <= 0) {
            return $this->batch;
        }
        return (int) max(min(10, $this->batch), min($this->batch, intdiv(4194304, $avg)));
    }

    private function dumpExtras(array $c, ArchiveOut $out): void
    {
        $dbQualifier = $this->db->ident((string) $this->db->value('SELECT DATABASE()')) . '.';
        foreach ($c['views'] as $v) {
            $row = $this->db->rows('SHOW CREATE VIEW ' . $this->db->ident($v))[0];
            $ddl = str_replace($dbQualifier, '', (string) ($row['Create View'] ?? array_values($row)[1]));
            $this->writeDdl('db/_views/' . $v . '.sql', $ddl, $out);
        }
        $tables = array_flip($c['tables']);
        foreach ($this->db->rows('SHOW TRIGGERS') as $tr) {
            if (!isset($tables[$tr['Table']])) {
                continue;
            }
            $row = $this->db->rows('SHOW CREATE TRIGGER ' . $this->db->ident($tr['Trigger']))[0];
            $this->writeDdl('db/_triggers/' . $tr['Trigger'] . '.sql', (string) $row['SQL Original Statement'], $out);
        }
    }

    private function writeDdl(string $path, string $ddl, ArchiveOut $out): void
    {
        $out->beginEntry(Format::TYPE_DB_SCHEMA, $path, 0644, time(), true);
        $out->write((string) preg_replace(self::DEFINER_RE, '', $ddl) . self::STMT_END);
        $out->endEntry();
    }

    /** WooCommerce order data last and back to back, to shrink the cross-table window. */
    private function order(array $tables): array
    {
        $late = [];
        $early = [];
        $options = $this->db->prefix() . 'options';
        $hpos = in_array($options, $tables, true)
            && (string) $this->db->value('SELECT option_value FROM ' . $this->db->ident($options)
                . " WHERE option_name = 'woocommerce_custom_orders_table_enabled' LIMIT 1") === 'yes';
        $hasWoo = in_array($this->db->prefix() . 'woocommerce_order_items', $tables, true);
        foreach ($tables as $t) {
            $rest = substr($t, strlen($this->db->prefix()));
            $isOrder = preg_match('/^(wc_orders|wc_order_|woocommerce_order_item)/', $rest) === 1
                || ($hasWoo && !$hpos && ($rest === 'posts' || $rest === 'postmeta'));
            if ($isOrder) { $late[] = $t; } else { $early[] = $t; }
        }
        return array_merge($early, $late);
    }
}
