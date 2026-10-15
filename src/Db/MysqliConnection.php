<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI).
namespace Dosya\Backup\Db;

/** Db over an existing mysqli connection (the plugin passes $wpdb->dbh). Only method calls, no mysqli_* functions. */
class MysqliConnection implements Db
{
    protected \mysqli $m;
    private string $prefix;
    private string $charset;

    public function __construct(\mysqli $m, string $prefix, string $charset = 'utf8mb4')
    {
        $this->m = $m;
        $this->prefix = $prefix;
        $this->charset = $charset;
        $this->exec(Db::SESSION_SQL);
    }

    public function exec(string $sql): int
    {
        if ($this->m->query($sql) === false) {
            throw new DbException($this->m->error . ' [' . substr($sql, 0, 200) . ']');
        }
        return max(0, (int) $this->m->affected_rows);
    }

    public function rows(string $sql): array
    {
        $res = $this->m->query($sql);
        if ($res === false) {
            throw new DbException($this->m->error . ' [' . substr($sql, 0, 200) . ']');
        }
        if ($res === true) {
            return [];
        }
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
        return $rows;
    }

    public function value(string $sql)
    {
        $rows = $this->rows($sql);
        return $rows ? array_values($rows[0])[0] : null;
    }

    public function quote(?string $v): string
    {
        return $v === null ? 'NULL' : "'" . Escape::mysql($v) . "'";
    }

    public function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function prefix(): string { return $this->prefix; }
    public function serverVersion(): string { return (string) $this->value('SELECT VERSION()'); }
    public function charset(): string { return $this->charset; }

    public function setCharset(string $charset): void
    {
        if (!$this->m->set_charset($charset)) {
            throw new DbException('Could not set the connection charset to ' . $charset);
        }
        $this->charset = $charset;
    }
}
