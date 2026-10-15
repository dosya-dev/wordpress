<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI); engine code runs outside WordPress and streams large files, which WP_Filesystem cannot do.
namespace Dosya\Backup\Support;

final class AppendLog
{
    private string $path;
    private int $length;

    public function __construct(string $path, int $resumeAt)
    {
        if ($resumeAt < 0) {
            throw new \InvalidArgumentException('negative checkpoint offset');
        }
        $fh = fopen($path, 'c+b');
        if ($fh === false) {
            throw new \RuntimeException('cannot open log ' . $path);
        }
        $stat = fstat($fh);
        if ($stat === false) {
            fclose($fh);
            throw new \RuntimeException('cannot stat log ' . $path);
        }
        if ($resumeAt > $stat['size']) {
            fclose($fh);
            throw new \RuntimeException('log is shorter than the checkpoint');
        }
        if (!ftruncate($fh, $resumeAt)) {
            fclose($fh);
            throw new \RuntimeException('cannot open log ' . $path);
        }
        fclose($fh);
        clearstatcache(true, $path);
        $this->path = $path;
        $this->length = $resumeAt;
    }

    public function append(array $row): void
    {
        $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if (file_put_contents($this->path, $line, FILE_APPEND) !== strlen($line)) {
            throw new \RuntimeException('write failed (disk full?)');
        }
        $this->length += strlen($line);
    }

    public function length(): int
    {
        return $this->length;
    }

    /** @return array<int,array{0:array,1:int}> */
    public function readFrom(int $offset, int $maxRows): array
    {
        $fh = is_readable($this->path) ? fopen($this->path, 'rb') : false;
        if ($fh === false) {
            throw new \RuntimeException('cannot read log ' . $this->path);
        }
        try {
            if (fseek($fh, $offset) !== 0) {
                throw new \RuntimeException('cannot seek log ' . $this->path);
            }
            $out = [];
            while (count($out) < $maxRows && ($line = fgets($fh)) !== false) {
                $offset += strlen($line);
                $out[] = [json_decode($line, true, 512, JSON_THROW_ON_ERROR), $offset];
            }
            return $out;
        } finally {
            fclose($fh);
        }
    }
}
