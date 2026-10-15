<?php
namespace Dosya\Backup\Db;

use Dosya\Backup\Support\Budget;

final class SearchReplace
{
    private const BATCH = 200;
    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /** @param string[] $tables */
    public static function start(array $tables): array
    {
        return ['tables' => array_values($tables), 'i' => 0, 'last' => null, 'changed' => 0, 'done' => false];
    }

    public function run(array $c, array $pairs, Budget $b, callable $warn): array
    {
        $needles = array_keys($pairs);
        $progressed = false;
        while ($c['i'] < count($c['tables'])) {
            if ($progressed && $b->exhausted()) {
                return $c;
            }
            $progressed = true;
            $t = $c['tables'][$c['i']];
            $cols = Schema::columns($this->db, $t);
            $pk = Schema::primaryKey($this->db, $t);
            $byName = [];
            foreach ($cols as $col) { $byName[$col['name']] = $col; }
            $text = array_values(array_filter($cols, function (array $col) use ($pk): bool {
                return $col['text'] && !$col['generated'] && !in_array($col['name'], $pk, true);
            }));
            if (!$text || !$pk) {
                if ($text && !$pk) {
                    $warn('Addresses were not updated in table ' . $t . ' because it has no primary key.');
                }
                $c['i']++;
                $c['last'] = null;
                continue;
            }
            $conds = [];
            foreach ($text as $col) {
                foreach ($needles as $n) {
                    $conds[] = $this->db->ident($col['name']) . ' LIKE ' . $this->db->quote('%' . Escape::like($n) . '%');
                }
            }
            $where = '(' . implode(' OR ', $conds) . ')';
            $keyset = Schema::keysetWhere($this->db, $cols, $pk, $c['last'] === null ? null : Schema::unpackKey($c['last']));
            if ($keyset !== '') {
                $where .= ' AND ' . $keyset;
            }
            $select = array_merge($pk, array_column($text, 'name'));
            $rows = $this->db->rows('SELECT ' . implode(',', array_map([$this->db, 'ident'], $select)) . ' FROM ' . $this->db->ident($t)
                . ' WHERE ' . $where . ' ORDER BY ' . implode(',', array_map([$this->db, 'ident'], $pk)) . ' LIMIT ' . self::BATCH);
            foreach ($rows as $r) {
                $sets = [];
                foreach ($text as $col) {
                    $v = $r[$col['name']];
                    if ($v === null) {
                        continue;
                    }
                    $nv = SerializedReplace::apply((string) $v, $pairs);
                    if ($nv !== (string) $v) {
                        $sets[] = $this->db->ident($col['name']) . ' = ' . $this->db->quote($nv);
                    }
                }
                if ($sets) {
                    $match = [];
                    foreach ($pk as $k) {
                        $match[] = $this->db->ident($k) . ' = ' . Schema::literal($this->db, $byName[$k], $r[$k] === null ? null : (string) $r[$k]);
                    }
                    $this->db->exec('UPDATE ' . $this->db->ident($t) . ' SET ' . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $match));
                    $c['changed']++;
                }
            }
            if (count($rows) < self::BATCH) {
                $c['i']++;
                $c['last'] = null;
            } else {
                $last = $rows[count($rows) - 1];
                $c['last'] = Schema::packKey($cols, $pk, $last);
            }
        }
        $c['done'] = true;
        return $c;
    }
}
