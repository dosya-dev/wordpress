<?php
namespace Dosya\Backup\Archive;

interface Source
{
    public function read(int $offset, int $length): string;
    public function size(): int;
}
