<?php
namespace Dosya\Backup\Db;

/** Opens its own connection: tests and the standalone restorer. Never loaded by the plugin; excluded from the release zip. */
final class MysqliDb extends MysqliConnection
{
    public function __construct(string $host, string $user, string $pass, string $name, string $prefix, string $charset = 'utf8mb4')
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        [$h, $port, $socket] = self::parseHost($host);
        $m = mysqli_init();
        if (!$m || !$m->real_connect($h, $user, $pass, $name, $port ?? 3306, $socket)) {
            throw new DbException('Could not connect to the database: ' . mysqli_connect_error());
        }
        $m->set_charset($charset);
        parent::__construct($m, $prefix, $charset);
    }

    /** @return array{0:string,1:?int,2:?string} */
    public static function parseHost(string $host): array
    {
        if (preg_match('/^\[([^\]]+)\](?::(\d+))?$/', $host, $m)) {
            return [$m[1], isset($m[2]) ? (int) $m[2] : null, null];
        }
        $pos = strpos($host, ':');
        if ($pos === false) {
            return [$host, null, null];
        }
        $rest = substr($host, $pos + 1);
        $h = substr($host, 0, $pos);
        return ctype_digit($rest) ? [$h, (int) $rest, null] : [$h, null, $rest];
    }
}
