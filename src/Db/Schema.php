<?php
namespace Dosya\Backup\Db;

final class Schema
{
    /** @return array<int,array{name:string,type:string,int:bool,binary:bool,generated:bool,text:bool}> */
    public static function columns(Db $db, string $table): array
    {
        $out = [];
        foreach ($db->rows('SHOW COLUMNS FROM ' . $db->ident($table)) as $r) {
            $type = strtolower((string) $r['Type']);
            $out[] = [
                'name' => (string) $r['Field'],
                'type' => $type,
                'int' => preg_match('/^(tiny|small|medium|big)?int\b/', $type) === 1,
                'binary' => preg_match('/^(binary|varbinary|tinyblob|blob|mediumblob|longblob|bit|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)\b/', $type) === 1,
                'generated' => self::isGeneratedExtra((string) $r['Extra']),
                'text' => preg_match('/^(char|varchar|tinytext|text|mediumtext|longtext|json)\b/', $type) === 1,
            ];
        }
        return $out;
    }

    /** True for virtual/stored generated columns only; MySQL 8 "DEFAULT_GENERATED" is an ordinary data column. */
    public static function isGeneratedExtra(string $extra): bool
    {
        return preg_match('/\b(VIRTUAL|STORED|PERSISTENT)\b/i', $extra) === 1;
    }

    /** @return string[] */
    public static function primaryKey(Db $db, string $table): array
    {
        $rows = $db->rows('SHOW KEYS FROM ' . $db->ident($table) . " WHERE Key_name = 'PRIMARY'");
        usort($rows, function (array $a, array $b): int { return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']; });
        return array_map(function (array $r): string { return (string) $r['Column_name']; }, $rows);
    }

    public static function hasUniqueKey(Db $db, string $table): bool
    {
        return (bool) $db->rows('SHOW KEYS FROM ' . $db->ident($table) . ' WHERE Non_unique = 0');
    }

    public static function literal(Db $db, array $col, ?string $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if ($col['int'] && preg_match('/^-?\d+$/', $v) === 1) {
            return $v;
        }
        if ($col['binary']) {
            return $v === '' ? "''" : '0x' . bin2hex($v);
        }
        return $db->quote($v);
    }

    /** Binary key bytes are not valid UTF-8, so they travel base64-wrapped inside the JSON cursor. */
    public static function packKey(array $cols, array $pk, array $row): array
    {
        $binary = [];
        foreach ($cols as $col) { $binary[$col['name']] = $col['binary']; }
        $out = [];
        foreach ($pk as $k) {
            $v = $row[$k];
            $out[] = ($v !== null && !empty($binary[$k])) ? ['b64' => base64_encode((string) $v)] : $v;
        }
        return $out;
    }

    public static function unpackKey(array $last): array
    {
        return array_map(function ($v) { return is_array($v) ? base64_decode($v['b64'], true) : $v; }, $last);
    }

    /** Keyset condition "after $last" for $pk (no float comparison: integer literals stay unquoted). */
    public static function keysetWhere(Db $db, array $cols, array $pk, ?array $last): string
    {
        if ($last === null || $pk === []) {
            return '';
        }
        $byName = [];
        foreach ($cols as $c) { $byName[$c['name']] = $c; }
        $lits = [];
        foreach ($pk as $i => $k) {
            $lits[] = self::literal($db, $byName[$k], $last[$i] === null ? null : (string) $last[$i]);
        }
        $idents = array_map([$db, 'ident'], $pk);
        if (count($pk) === 1) {
            return $idents[0] . ' > ' . $lits[0];
        }
        $terms = [];
        for ($i = 0, $n = count($pk); $i < $n; $i++) {
            $parts = [];
            for ($j = 0; $j < $i; $j++) {
                $parts[] = $idents[$j] . ' = ' . $lits[$j];
            }
            $parts[] = $idents[$i] . ' > ' . $lits[$i];
            $terms[] = $i === 0 ? $parts[0] : '(' . implode(' AND ', $parts) . ')';
        }
        return '(' . implode(' OR ', $terms) . ')';
    }
}
