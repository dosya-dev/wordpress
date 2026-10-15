<?php
namespace Dosya\Backup\Db;

interface Db
{
    /** Same as mysqldump: no backslash-escape surprises, zero dates allowed, AUTO_INCREMENT 0 kept, UTC so TIMESTAMPs round-trip across zones. */
    public const SESSION_SQL = "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO', SESSION foreign_key_checks = 0, SESSION time_zone = '+00:00'";

    public function exec(string $sql): int;
    public function rows(string $sql): array;
    /** @return mixed */
    public function value(string $sql);
    public function quote(?string $v): string;
    public function ident(string $name): string;
    public function prefix(): string;
    public function serverVersion(): string;
    public function charset(): string;
    /** Changes the connection charset the client escapes with, as mysqli does it. */
    public function setCharset(string $charset): void;
}
