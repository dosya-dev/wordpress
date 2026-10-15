<?php
namespace Dosya\Backup\Archive;

final class VolumeWriter
{
    private Sink $sink;
    private int $blockMax;
    private int $flags;
    /** @var array<int,array> */
    private array $index = [];
    private ?array $cur = null;
    private string $buf = '';

    private function __construct(Sink $sink, int $blockMax, int $flags)
    {
        $this->sink = $sink;
        $this->blockMax = $blockMax;
        $this->flags = $flags;
    }

    public static function create(Sink $sink, string $backupId, int $volumeNo, int $created, int $flags = 0, int $blockMax = Format::BLOCK_MAX): self
    {
        $w = new self($sink, $blockMax, $flags);
        $sink->write(Format::volumeHeader($backupId, $volumeNo, $created, $flags));
        return $w;
    }

    public static function resume(Sink $sink, array $state): self
    {
        if (!is_int($state['o'] ?? null) || !is_int($state['bm'] ?? null) || !is_int($state['f'] ?? null)
            || !is_array($state['i'] ?? null) || !array_key_exists('c', $state)
            || ($state['c'] !== null && !is_array($state['c']))) {
            throw new \InvalidArgumentException('invalid volume writer state');
        }
        if ($sink->offset() !== $state['o']) {
            throw new \LogicException('sink offset does not match checkpoint');
        }
        $w = new self($sink, (int) $state['bm'], (int) $state['f']);
        $w->index = $state['i'];
        $w->cur = $state['c'];
        return $w;
    }

    public function beginEntry(int $type, string $path, int $mode, int $mtime, bool $compress): void
    {
        if ($this->cur !== null) {
            throw new \LogicException('an entry is already open');
        }
        $len = strlen($path);
        if ($len === 0 || $len > 65535) {
            throw new \InvalidArgumentException('path length must be 1..65535 bytes');
        }
        $ho = $this->sink->offset();
        $this->sink->write(Format::MAGIC_ENTRY . Bin::u8($type) . Bin::u16($len) . $path . Bin::u32($mode) . Bin::u64($mtime));
        $this->cur = PathCodec::pack($path) + ['t' => $type, 'm' => $mode, 'mt' => $mtime, 'ho' => $ho, 's' => 0, 'b' => [], 'z' => $compress];
    }

    public function write(string $data): void
    {
        if ($this->cur === null) {
            throw new \LogicException('no open entry');
        }
        $this->buf .= $data;
        while (strlen($this->buf) >= $this->blockMax) {
            $this->flushBlock(substr($this->buf, 0, $this->blockMax));
            $this->buf = (string) substr($this->buf, $this->blockMax);
        }
    }

    public function endEntry(): array
    {
        if ($this->cur === null) {
            throw new \LogicException('no open entry');
        }
        if ($this->buf !== '') {
            $this->flushBlock($this->buf);
            $this->buf = '';
        }
        $this->sink->write(Bin::u32(0) . Bin::u8(Format::END_CODEC));
        $e = $this->cur;
        unset($e['z']);
        $e['h'] = Format::treeHash(array_column($e['b'], 3));
        $this->index[] = $e;
        $this->cur = null;
        return $e;
    }

    public function checkpoint(): array
    {
        if ($this->buf !== '') {
            $this->flushBlock($this->buf);
            $this->buf = '';
        }
        return ['o' => $this->sink->offset(), 'i' => $this->index, 'c' => $this->cur, 'bm' => $this->blockMax, 'f' => $this->flags];
    }

    public function hasOpenEntry(): bool
    {
        return $this->cur !== null;
    }

    public function bytesWritten(): int
    {
        return $this->sink->offset() + strlen($this->buf);
    }

    public function close(): void
    {
        $this->sink->close();
    }

    public function finish(): int
    {
        if ($this->cur !== null) {
            throw new \LogicException('cannot finish with an open entry');
        }
        $json = gzencode(json_encode($this->index, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 6);
        $indexOffset = $this->sink->offset();
        $this->sink->write($json);
        $this->sink->write(Format::footer($indexOffset, strlen($json), hash('sha256', $json, true)));
        $size = $this->sink->offset();
        $this->sink->close();
        return $size;
    }

    private function flushBlock(string $plain): void
    {
        $offset = $this->sink->offset();
        $record = BlockCodec::encode($plain, (bool) $this->cur['z']);
        $this->sink->write($record);
        $this->cur['s'] += strlen($plain);
        $this->cur['b'][] = [$offset, strlen($record), strlen($plain), hash('sha256', $plain)];
    }
}
