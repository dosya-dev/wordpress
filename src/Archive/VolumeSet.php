<?php
namespace Dosya\Backup\Archive;

final class VolumeSet implements ArchiveOut
{
    private string $backupId;
    private int $created;
    private int $target;
    private int $blockMax;
    /** @var callable */
    private $sinkFor;
    /** @var callable */
    private $record;
    private ?VolumeWriter $writer = null;
    private int $volumeNo = 0;
    /** @var array<int,array{v:int,size:int}> */
    private array $sealed = [];

    public function __construct(string $backupId, int $created, int $targetBytes, callable $sinkFor, callable $record, int $blockMax = Format::BLOCK_MAX)
    {
        $this->backupId = $backupId;
        $this->created = $created;
        $this->target = $targetBytes;
        $this->sinkFor = $sinkFor;
        $this->record = $record;
        $this->blockMax = $blockMax;
    }

    public static function resume(array $s, callable $sinkFor, callable $record): self
    {
        self::assertState($s);
        $vs = new self($s['id'], (int) $s['created'], (int) $s['target'], $sinkFor, $record, (int) $s['bm']);
        $vs->volumeNo = (int) $s['vol'];
        $vs->sealed = $s['sealed'];
        if ($s['w'] !== null) {
            $vs->writer = VolumeWriter::resume(($sinkFor)($vs->volumeNo, (int) $s['w']['o']), $s['w']);
        }
        return $vs;
    }

    public function beginEntry(int $type, string $path, int $mode, int $mtime, bool $compress): void
    {
        if ($this->writer !== null && $this->writer->bytesWritten() >= $this->target) {
            $this->seal();
        }
        if ($this->writer === null) {
            $this->volumeNo++;
            $this->writer = VolumeWriter::create(($this->sinkFor)($this->volumeNo, 0), $this->backupId, $this->volumeNo, $this->created, 0, $this->blockMax);
        }
        $this->writer->beginEntry($type, $path, $mode, $mtime, $compress);
    }

    public function write(string $data): void
    {
        if ($this->writer === null) {
            throw new \LogicException('no open entry');
        }
        $this->writer->write($data);
    }

    public function endEntry(): array
    {
        if ($this->writer === null) {
            throw new \LogicException('no open entry');
        }
        $e = $this->writer->endEntry();
        $path = isset($e['pb']) ? ['pb' => $e['pb']] : ['p' => $e['p']];
        $res = $path + ['t' => $e['t'], 's' => $e['s'], 'h' => $e['h'], 'm' => $e['m'], 'mt' => $e['mt'],
            'loc' => ['b' => $this->backupId, 'v' => $this->volumeNo, 'ho' => $e['ho']]];
        ($this->record)($res);
        return $res;
    }

    public function suspend(): array
    {
        $w = null;
        if ($this->writer !== null) {
            $w = $this->writer->checkpoint();
            $this->writer->close();
            $this->writer = null;
        }
        return ['id' => $this->backupId, 'created' => $this->created, 'target' => $this->target, 'bm' => $this->blockMax,
            'vol' => $this->volumeNo, 'sealed' => $this->sealed, 'w' => $w];
    }

    /** @return array<int,array{v:int,size:int}> */
    public function finish(): array
    {
        if ($this->writer !== null) {
            $this->seal();
        }
        return $this->sealed;
    }

    private static function assertState(array $s): void
    {
        $ok = isset($s['id']) && is_string($s['id']) && preg_match('/^[0-9a-f]{32}$/', $s['id']) === 1
            && is_int($s['created'] ?? null) && is_int($s['target'] ?? null) && is_int($s['bm'] ?? null)
            && is_int($s['vol'] ?? null) && $s['vol'] >= 0
            && isset($s['sealed']) && is_array($s['sealed'])
            && array_key_exists('w', $s) && ($s['w'] === null || is_array($s['w']));
        if (!$ok) {
            throw new \InvalidArgumentException('invalid volume set state');
        }
    }

    private function seal(): void
    {
        $this->sealed[] = ['v' => $this->volumeNo, 'size' => $this->writer->finish()];
        $this->writer = null;
    }
}
