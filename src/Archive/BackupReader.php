<?php
namespace Dosya\Backup\Archive;

final class BackupReader
{
    /** @var callable */
    private $sourceFor;
    /** @var array<string,VolumeReader> */
    private array $open = [];

    public function __construct(callable $sourceFor)
    {
        $this->sourceFor = $sourceFor;
    }

    public function entry(array $loc): array
    {
        return $this->reader($loc)->entryAt((int) $loc['ho']);
    }

    public function readEntry(array $loc, callable $onBlock, int $fromBlock = 0, ?callable $shouldStop = null): int
    {
        return $this->reader($loc)->readEntry($this->entry($loc), $onBlock, $fromBlock, $shouldStop);
    }

    public function readAll(array $loc): string
    {
        $out = '';
        $this->readEntry($loc, function (string $plain) use (&$out): void { $out .= $plain; });
        return $out;
    }

    private function reader(array $loc): VolumeReader
    {
        $key = $loc['b'] . ':' . (int) $loc['v'];
        if (!isset($this->open[$key])) {
            if (count($this->open) >= 4) {
                array_shift($this->open);
            }
            $this->open[$key] = new VolumeReader(($this->sourceFor)((string) $loc['b'], (int) $loc['v']));
        }
        return $this->open[$key];
    }
}
