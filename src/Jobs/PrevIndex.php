<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI); engine code runs outside WordPress, where WP_Filesystem is not available.
namespace Dosya\Backup\Jobs;

use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\Manifest;
use Dosya\Backup\Archive\PathCodec;

/**
 * The previous backup's file rows on disk, looked up by path while an
 * incremental backup archives, so the job cursor stays small and a step's
 * memory does not grow with the site's file count.
 *
 * Two sidecar files in the backup folder:
 * - prev_rows.jsonl: one manifest row per line (the row an unchanged file reuses)
 * - prev_index.bin: a bucket table of 65537 big-endian uint32 (first record of
 *   each 2-byte md5 prefix, plus the end) followed by fixed 44-byte records
 *   sorted by md5(path): md5 (16) | size (8) | mtime (8) | row offset (8) | row length (4)
 *
 * A lookup reads the table once per open (256 KiB) and then one bucket (a few
 * records on average) per path, and the row only when size and mtime match.
 */
final class PrevIndex
{
    public const INDEX = 'prev_index.bin';
    public const ROWS = 'prev_rows.jsonl';
    private const REC = 44;
    private const BUCKETS = 65536;
    private const HEAD = (self::BUCKETS + 1) * 4;
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** @var resource */
    private $index;
    /** @var resource */
    private $rows;
    private string $head;

    /** @param resource $index @param resource $rows */
    private function __construct($index, $rows, string $head)
    {
        $this->index = $index;
        $this->rows = $rows;
        $this->head = $head;
    }

    public function __destruct()
    {
        fclose($this->index);
        fclose($this->rows);
    }

    /**
     * Writes the sidecar for $prev's regular files into $dir. Consumes $prev->files to keep the peak
     * at one copy of the rows. @return int the number of rows indexed
     */
    public static function build(Manifest $prev, string $dir): int
    {
        $rows = fopen($dir . '/' . self::ROWS, 'wb');
        $index = fopen($dir . '/' . self::INDEX, 'wb');
        if ($rows === false || $index === false) {
            self::closeAll($rows, $index);
            throw new \RuntimeException('Cannot write the previous backup index.');
        }
        $files = $prev->files;
        $prev->files = [];
        $recs = [];
        $off = 0;
        while (($row = array_pop($files)) !== null) {
            if (!is_array($row) || (int) ($row['t'] ?? -1) !== Format::TYPE_FILE || !is_array($row['loc'] ?? null)) {
                continue;
            }
            $line = json_encode($row, self::FLAGS) . "\n";
            if (fwrite($rows, $line) !== strlen($line)) {
                self::closeAll($rows, $index);
                throw new \RuntimeException('Cannot write the previous backup index (disk full?).');
            }
            $recs[] = md5(PathCodec::unpack($row), true) . pack('JJJN', max(0, (int) $row['s']), max(0, (int) $row['mt']), $off, strlen($line));
            $off += strlen($line);
        }
        unset($files);
        sort($recs, SORT_STRING);
        $n = count($recs);
        $head = '';
        $i = 0;
        for ($p = 0; $p <= self::BUCKETS; $p++) {
            while ($i < $n && self::prefix($recs[$i]) < $p) {
                $i++;
            }
            $head .= pack('N', $i);
        }
        $ok = fwrite($index, $head) === strlen($head);
        foreach (array_chunk($recs, 4096) as $chunk) {
            $bin = implode('', $chunk);
            $ok = $ok && fwrite($index, $bin) === strlen($bin);
        }
        fclose($rows);
        fclose($index);
        if (!$ok) {
            throw new \RuntimeException('Cannot write the previous backup index (disk full?).');
        }
        return $n;
    }

    /** Null when the sidecar is missing or damaged: the backup then archives every file. */
    public static function open(string $dir): ?self
    {
        $ip = $dir . '/' . self::INDEX;
        $rp = $dir . '/' . self::ROWS;
        if (!is_file($ip) || !is_file($rp)) {
            return null;
        }
        $index = fopen($ip, 'rb');
        $rows = fopen($rp, 'rb');
        if ($index === false || $rows === false) {
            self::closeAll($index, $rows);
            return null;
        }
        $head = fread($index, self::HEAD);
        if (!is_string($head) || strlen($head) !== self::HEAD) {
            fclose($index);
            fclose($rows);
            return null;
        }
        return new self($index, $rows, $head);
    }

    /** The previous row for $path when its size and mtime are unchanged, else null. @return array<string,mixed>|null */
    public function find(string $path, int $size, int $mtime): ?array
    {
        $h = md5($path, true);
        $p = (ord($h[0]) << 8) | ord($h[1]);
        $a = unpack('N', $this->head, $p * 4)[1];
        $b = unpack('N', $this->head, ($p + 1) * 4)[1];
        if ($b <= $a || fseek($this->index, self::HEAD + $a * self::REC) !== 0) {
            return null;
        }
        $buf = fread($this->index, ($b - $a) * self::REC);
        if (!is_string($buf)) {
            return null;
        }
        for ($o = 0; $o + self::REC <= strlen($buf); $o += self::REC) {
            if (substr($buf, $o, 16) !== $h) {
                continue;
            }
            $r = unpack('Js/Jmt/Joff/Nlen', $buf, $o + 16);
            if ($r['s'] !== $size || $r['mt'] !== $mtime || fseek($this->rows, $r['off']) !== 0) {
                return null;
            }
            $line = fread($this->rows, $r['len']);
            $row = is_string($line) ? json_decode($line, true) : null;
            if (!is_array($row) || PathCodec::unpack($row) !== $path) {
                return null;
            }
            return $row;
        }
        return null;
    }

    /** @param resource|false ...$handles */
    private static function closeAll(...$handles): void
    {
        foreach ($handles as $h) {
            if (is_resource($h)) {
                fclose($h);
            }
        }
    }

    private static function prefix(string $rec): int
    {
        return (ord($rec[0]) << 8) | ord($rec[1]);
    }
}
