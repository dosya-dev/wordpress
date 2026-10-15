<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI).
namespace Dosya\Backup\Archive;

final class VolumeReader
{
    private Source $src;
    private ?array $index = null;
    /** @var array<int,int> header offset => index position */
    private array $byOffset = [];

    public function __construct(Source $src)
    {
        $this->src = $src;
    }

    public function header(): array
    {
        return Format::parseVolumeHeader($this->src->read(0, Format::HEADER_SIZE));
    }

    public function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $size = $this->src->size();
        if ($size < Format::HEADER_SIZE + Format::FOOTER_SIZE) {
            throw new FormatException('volume footer missing (incomplete upload or truncated file)');
        }
        $f = Format::parseFooter($this->src->read($size - Format::FOOTER_SIZE, Format::FOOTER_SIZE));
        if ($f['index_offset'] + $f['index_len'] !== $size - Format::FOOTER_SIZE) {
            throw new FormatException('index position does not match footer');
        }
        $raw = $this->src->read($f['index_offset'], $f['index_len']);
        if (!hash_equals($f['index_sha'], hash('sha256', $raw, true))) {
            throw new FormatException('index checksum mismatch');
        }
        $json = @gzdecode($raw);
        if ($json === false) {
            throw new FormatException('index does not decompress (corrupt or foreign file)');
        }
        try {
            $entries = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FormatException('index is not valid JSON');
        }
        self::validateIndex($entries);
        foreach ($entries as $i => $e) {
            $entries[$i]['path'] = PathCodec::unpack($e);
            if (isset($this->byOffset[(int) $e['ho']])) {
                throw new FormatException('index has duplicate entry offsets');
            }
            $this->byOffset[(int) $e['ho']] = $i;
        }
        return $this->index = $entries;
    }

    private static function validateIndex($entries): void
    {
        if (!is_array($entries)) {
            throw new FormatException('index is malformed');
        }
        $expect = 0;
        foreach ($entries as $k => $e) {
            if ($k !== $expect++ || !is_array($e)) {
                throw new FormatException('index is malformed');
            }
            if (!isset($e['ho'], $e['t'], $e['h'], $e['b'])
                || !is_int($e['ho']) || $e['ho'] < Format::HEADER_SIZE
                || !is_int($e['t'])
                || !is_string($e['h']) || preg_match('/^[0-9a-f]{64}$/', $e['h']) !== 1
                || !is_array($e['b'])) {
                throw new FormatException('index is malformed');
            }
            foreach ($e['b'] as $b) {
                if (!is_array($b) || count($b) !== 4 || !array_key_exists(0, $b) || !array_key_exists(1, $b)
                    || !array_key_exists(2, $b) || !array_key_exists(3, $b)
                    || !is_int($b[0]) || !is_int($b[1]) || !is_int($b[2])
                    || !is_string($b[3]) || preg_match('/^[0-9a-f]{64}$/', $b[3]) !== 1) {
                    throw new FormatException('index is malformed');
                }
            }
        }
    }

    public function entryAt(int $headerOffset): array
    {
        $this->index();
        if (!isset($this->byOffset[$headerOffset])) {
            throw new FormatException('no entry at offset ' . $headerOffset);
        }
        return $this->index[$this->byOffset[$headerOffset]];
    }

    public function readEntry(array $entry, callable $onBlock, int $fromBlock = 0, ?callable $shouldStop = null): int
    {
        if ($fromBlock < 0) {
            throw new \InvalidArgumentException('fromBlock must be >= 0');
        }
        $blocks = $entry['b'];
        $count = count($blocks);
        for ($n = $fromBlock; $n < $count; $n++) {
            if ($n > $fromBlock && $shouldStop !== null && $shouldStop()) {
                return $n;
            }
            [$offset, $recordLen, $plainLen, $sha] = $blocks[$n];
            $plain = BlockCodec::decode($this->src->read((int) $offset, (int) $recordLen));
            if (strlen($plain) !== (int) $plainLen || !hash_equals((string) $sha, hash('sha256', $plain))) {
                throw new FormatException('block ' . $n . ' does not match the index');
            }
            $onBlock($plain, $n);
        }
        if ($fromBlock === 0 && Format::treeHash(array_column($blocks, 3)) !== $entry['h']) {
            throw new FormatException('entry hash mismatch for ' . $entry['path']);
        }
        return -1;
    }

    /** @return array{entries:int,bytes:int} */
    public function verify(): array
    {
        $this->header();
        $bytes = 0;
        foreach ($this->index() as $e) {
            $head = $this->src->read((int) $e['ho'], 3);
            if (substr($head, 0, 2) !== Format::MAGIC_ENTRY || ord($head[2]) !== (int) $e['t']) {
                throw new FormatException('entry header mismatch for ' . $e['path']);
            }
            $this->readEntry($e, function (string $plain) use (&$bytes): void { $bytes += strlen($plain); });
        }
        return ['entries' => count($this->index()), 'bytes' => $bytes];
    }
}
