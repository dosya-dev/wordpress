<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI); engine code runs outside WordPress and streams large files, which WP_Filesystem cannot do.
namespace Dosya\Backup\Archive;

final class FileSink implements Sink
{
    /** @var resource|null */
    private $fh;
    private int $offset;

    public function __construct(string $path, int $resumeAt = 0)
    {
        if ($resumeAt < 0) {
            throw new \InvalidArgumentException('resume offset must not be negative');
        }
        if ($resumeAt === 0) {
            $fh = @fopen($path, 'wb');
            if ($fh === false) {
                throw new \RuntimeException('cannot open ' . $path . ' for writing');
            }
        } else {
            if (!is_file($path)) {
                throw new \RuntimeException('cannot resume ' . $path . ': file is missing');
            }
            $fh = @fopen($path, 'r+b');
            if ($fh === false) {
                throw new \RuntimeException('cannot open ' . $path . ' for writing');
            }
            if ((int) fstat($fh)['size'] < $resumeAt) {
                fclose($fh);
                throw new \RuntimeException('cannot resume ' . $path . ': file is shorter than the checkpoint');
            }
            if (!ftruncate($fh, $resumeAt) || fseek($fh, $resumeAt) !== 0) {
                fclose($fh);
                throw new \RuntimeException('cannot resume ' . $path . ' at ' . $resumeAt);
            }
        }
        $this->fh = $fh;
        $this->offset = $resumeAt;
    }

    public function write(string $bytes): void
    {
        $len = strlen($bytes);
        $done = 0;
        while ($done < $len) {
            $n = fwrite($this->fh, $done === 0 ? $bytes : substr($bytes, $done));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('write failed (disk full?)');
            }
            $done += $n;
        }
        $this->offset += $len;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function close(): void
    {
        if ($this->fh !== null) {
            fflush($this->fh);
            fclose($this->fh);
            $this->fh = null;
        }
    }
}
