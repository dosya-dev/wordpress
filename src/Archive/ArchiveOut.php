<?php
namespace Dosya\Backup\Archive;

interface ArchiveOut
{
    public function beginEntry(int $type, string $path, int $mode, int $mtime, bool $compress): void;
    public function write(string $data): void;
    /** @return array path fields + t, s, h, m, mt, loc{b,v,ho} */
    public function endEntry(): array;
}
