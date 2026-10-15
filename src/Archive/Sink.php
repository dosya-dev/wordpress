<?php
namespace Dosya\Backup\Archive;

interface Sink
{
    public function write(string $bytes): void;
    public function offset(): int;
    public function close(): void;
}
