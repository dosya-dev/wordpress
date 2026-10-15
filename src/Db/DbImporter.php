<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI).
namespace Dosya\Backup\Db;

use Dosya\Backup\Archive\BackupReader;
use Dosya\Backup\Archive\Manifest;
use Dosya\Backup\Support\Budget;

final class DbImporter
{
    private const CORE_META = '(capabilities|user_level|user-settings|user-settings-time|dashboard_quick_press_last_post_id|media_library_mode|persisted_preferences)';

    private Db $db;
    private BackupReader $reader;

    public function __construct(Db $db, BackupReader $reader)
    {
        $this->db = $db;
        $this->reader = $reader;
    }

    public static function shadowName(string $table): string { return 'dosya_t_' . substr(md5($table), 0, 16); }
    public static function oldName(string $table): string { return 'dosya_o_' . substr(md5($table), 0, 16); }

    public static function mapName(string $name, string $old, string $new): string
    {
        return strncmp($name, $old, strlen($old)) === 0 ? $new . substr($name, strlen($old)) : $name;
    }

    public function plan(Manifest $m, ?array $onlyTables, string $oldPrefix, string $newPrefix): array
    {
        $tables = [];
        foreach ($m->tables as $old => $t) {
            if (($onlyTables !== null && !in_array($old, $onlyTables, true)) || $t['schema'] === null) {
                continue;
            }
            $new = self::mapName($old, $oldPrefix, $newPrefix);
            $tables[] = ['old' => $old, 'new' => $new, 'shadow' => self::shadowName($new), 'schema' => $t['schema'], 'parts' => $t['parts']];
        }
        return ['tables' => $tables, 'views' => $onlyTables === null ? $m->views : [], 'triggers' => $onlyTables === null ? $m->triggers : [],
            'old_prefix' => $oldPrefix, 'new_prefix' => $newPrefix, 'i' => 0, 'part' => 0, 'created' => false, 'count' => 0,
            'checked' => false, 'collations' => [], 'charset' => $m->meta['site']['charset'] ?? null, 'done' => false, 'tr' => null, 'swapped' => null];
    }

    public function import(array $c, Budget $b): array
    {
        return $this->withCharset($c, function () use ($c, $b): array { return $this->doImport($c, $b); });
    }

    private function doImport(array $c, Budget $b): array
    {
        $supported = null;
        $progressed = false;
        while ($c['i'] < count($c['tables'])) {
            if ($progressed && $b->exhausted()) {
                $c['checked'] = false;
                return $c;
            }
            $progressed = true;
            $t = $c['tables'][$c['i']];
            $shadow = $this->db->ident($t['shadow']);
            if (!$c['created']) {
                $supported = $supported ?? array_column($this->db->rows('SHOW COLLATION'), 'Collation');
                $this->db->exec('DROP TABLE IF EXISTS ' . $shadow);
                foreach (DbDumper::splitStatements($this->reader->readAll($t['schema'])) as $stmt) {
                    [$stmt, $subs] = Collation::rewrite(str_replace('`' . DbDumper::TABLE_TOKEN . '`', $shadow, $stmt), $supported);
                    $c['collations'] = array_merge($c['collations'], $subs);
                    $this->db->exec($stmt);
                }
                $c['created'] = true;
                $c['part'] = 0;
                $c['count'] = 0;
                $c['checked'] = true;
                continue;
            }
            $unique = Schema::hasUniqueKey($this->db, $t['shadow']);
            if (!$c['checked']) {
                if (!$unique && (int) $this->db->value('SELECT COUNT(*) FROM ' . $shadow) !== (int) $c['count']) {
                    $this->db->exec('TRUNCATE TABLE ' . $shadow);
                    $c['part'] = 0;
                    $c['count'] = 0;
                }
                $c['checked'] = true;
            }
            if ($c['part'] < count($t['parts'])) {
                $head = 'INSERT INTO `' . DbDumper::TABLE_TOKEN . '`';
                $verb = $unique ? 'REPLACE INTO ' : 'INSERT INTO ';
                foreach (DbDumper::splitStatements($this->reader->readAll($t['parts'][$c['part']])) as $stmt) {
                    if (strncmp($stmt, $head, strlen($head)) !== 0) {
                        throw new DbException('Unexpected statement in the backup of ' . $t['old']);
                    }
                    $c['count'] += $this->db->exec($verb . $shadow . substr($stmt, strlen($head)));
                }
                $c['part']++;
                continue;
            }
            $c['i']++;
            $c['created'] = false;
        }
        $c['done'] = true;
        return $c;
    }

    public function transform(array $c, array $pairs, Budget $b, callable $warn): array
    {
        return $this->withCharset($c, function () use ($c, $pairs, $b, $warn): array { return $this->doTransform($c, $pairs, $b, $warn); });
    }

    private function doTransform(array $c, array $pairs, Budget $b, callable $warn): array
    {
        $tr = $c['tr'] ?? ['stage' => 'prefix', 'sr' => null];
        if ($tr['stage'] === 'prefix') {
            if ($c['old_prefix'] !== $c['new_prefix']) {
                $this->renamePrefixedKeys($c);
            }
            $tr['stage'] = 'replace';
        }
        if ($tr['stage'] === 'replace') {
            if ($pairs) {
                $tr['sr'] = (new SearchReplace($this->db))->run($tr['sr'] ?? SearchReplace::start(array_column($c['tables'], 'shadow')), $pairs, $b, $warn);
                if (empty($tr['sr']['done'])) {
                    $c['tr'] = $tr;
                    return $c;
                }
            }
            $tr['stage'] = 'done';
        }
        $c['tr'] = $tr;
        return $c;
    }

    public function swap(array $c, callable $warn): array
    {
        return $this->withCharset($c, function () use ($c, $warn): array { return $this->doSwap($c, $warn); });
    }

    private function doSwap(array $c, callable $warn): array
    {
        $live = [];
        foreach ($this->db->rows('SHOW FULL TABLES') as $r) {
            [$n, $type] = array_values($r);
            $live[$n] = $type;
        }
        foreach ($c['tables'] as $t) {
            if (!isset($live[$t['shadow']])) {
                throw new DbException('The database import is incomplete for ' . $t['old']);
            }
        }
        $renames = [];
        $old = [];
        foreach ($c['tables'] as $t) {
            if (isset($live[$t['new']])) {
                if ($live[$t['new']] === 'VIEW') {
                    $this->db->exec('DROP VIEW ' . $this->db->ident($t['new']));
                } else {
                    $o = self::oldName($t['new']);
                    $this->db->exec('DROP TABLE IF EXISTS ' . $this->db->ident($o));
                    $renames[] = $this->db->ident($t['new']) . ' TO ' . $this->db->ident($o);
                    $old[] = $o;
                }
            }
            $renames[] = $this->db->ident($t['shadow']) . ' TO ' . $this->db->ident($t['new']);
        }
        if ($renames) {
            $this->db->exec('RENAME TABLE ' . implode(', ', $renames));
        }
        foreach (['views' => 'VIEW', 'triggers' => 'TRIGGER'] as $key => $kind) {
            foreach ($c[$key] as $name => $loc) {
                $newName = self::mapName((string) $name, $c['old_prefix'], $c['new_prefix']);
                try {
                    $this->db->exec('DROP ' . $kind . ' IF EXISTS ' . $this->db->ident($newName));
                    foreach (DbDumper::splitStatements($this->reader->readAll($loc)) as $stmt) {
                        $this->db->exec($c['old_prefix'] === $c['new_prefix'] ? $stmt : str_replace('`' . $c['old_prefix'], '`' . $c['new_prefix'], $stmt));
                    }
                } catch (\Throwable $e) {
                    $warn(sprintf('Could not restore %s %s: %s', strtolower($kind), $newName, $e->getMessage()));
                }
            }
        }
        $c['swapped'] = $old;
        return $c;
    }

    /** Runs $body on the dump's connection charset and puts the caller's charset back afterwards, so WordPress keeps its own. */
    private function withCharset(array $c, callable $body): array
    {
        $prev = $this->db->charset();
        try {
            $this->applyCharset($c);
            return $body();
        } finally {
            if ($this->db->charset() !== $prev) {
                $this->db->setCharset($prev);
            }
        }
    }

    private function applyCharset(array $c): void
    {
        $cs = $c['charset'] ?? null;
        if ($cs === null) {
            return;
        }
        $cs = strtolower((string) $cs);
        if (!in_array($cs, ['utf8mb4', 'utf8mb3', 'utf8', 'latin1'], true)) {
            throw new DbException('Unsupported dump charset: ' . $cs);
        }
        $this->db->setCharset($cs);
    }

    public function dropOld(array $c): void
    {
        foreach ($c['swapped'] ?? [] as $o) {
            $this->db->exec('DROP TABLE IF EXISTS ' . $this->db->ident($o));
        }
    }

    public function dropShadows(array $c): void
    {
        foreach ($c['tables'] as $t) {
            $this->db->exec('DROP TABLE IF EXISTS ' . $this->db->ident($t['shadow']));
        }
    }

    private function renamePrefixedKeys(array $c): void
    {
        $old = $c['old_prefix'];
        $new = $c['new_prefix'];
        $metaRe = '/^' . preg_quote($old, '/') . '(\d+_)?' . self::CORE_META . '$/';
        foreach ($c['tables'] as $t) {
            $shadow = $this->db->ident($t['shadow']);
            if (preg_match('/^' . preg_quote($new, '/') . '(\d+_)?options$/', $t['new'], $mm) === 1) {
                $site = $mm[1] ?? '';
                $this->db->exec("UPDATE $shadow SET option_name = " . $this->db->quote($new . $site . 'user_roles')
                    . ' WHERE option_name = ' . $this->db->quote($old . $site . 'user_roles'));
            } elseif ($t['new'] === $new . 'usermeta') {
                foreach ($this->db->rows("SELECT DISTINCT meta_key FROM $shadow WHERE meta_key LIKE " . $this->db->quote(Escape::like($old) . '%')) as $r) {
                    $k = (string) $r['meta_key'];
                    if (preg_match($metaRe, $k) === 1) {
                        $this->db->exec("UPDATE $shadow SET meta_key = " . $this->db->quote($new . substr($k, strlen($old)))
                            . ' WHERE meta_key = ' . $this->db->quote($k));
                    }
                }
            }
        }
    }
}
