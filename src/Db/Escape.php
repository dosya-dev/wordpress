<?php
namespace Dosya\Backup\Db;

/** Escaping for utf8/utf8mb4/latin1 connections (never used with GBK/SJIS/BIG5; see DbDumper preflight). */
final class Escape
{
    private const MAP = ["\\" => "\\\\", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "'" => "\\'", '"' => '\\"', "\x1A" => "\\Z"];

    public static function mysql(string $v): string
    {
        return strtr($v, self::MAP);
    }

    public static function like(string $v): string
    {
        return strtr($v, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
