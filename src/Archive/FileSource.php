<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI); engine code runs outside WordPress and streams large files, which WP_Filesystem cannot do.
namespace Dosya\Backup\Archive;

final class FileSource implements Source
{
    /** @var resource */
    private $fh;
    private int $size;

    public function __construct(string $path)
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new FormatException('cannot open ' . basename($path));
        }
        $this->fh = $fh;
        $this->size = (int) fstat($fh)['size'];
    }

    public function read(int $offset, int $length): string
    {
        if ($offset < 0 || $offset + $length > $this->size || fseek($this->fh, $offset) !== 0) {
            throw new FormatException('read outside volume');
        }
        $out = '';
        while (strlen($out) < $length) {
            $chunk = fread($this->fh, $length - strlen($out));
            if ($chunk === false || $chunk === '') {
                throw new FormatException('short read');
            }
            $out .= $chunk;
        }
        return $out;
    }

    public function size(): int
    {
        return $this->size;
    }
}
