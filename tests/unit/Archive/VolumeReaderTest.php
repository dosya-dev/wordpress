<?php
namespace Dosya\Backup\Tests\unit\Archive;

use Dosya\Backup\Archive\FileSink;
use Dosya\Backup\Archive\FileSource;
use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\FormatException;
use Dosya\Backup\Archive\VolumeReader;
use Dosya\Backup\Archive\VolumeWriter;
use PHPUnit\Framework\TestCase;

final class VolumeReaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/vr-' . bin2hex(random_bytes(4)) . '.dosya';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) { unlink($this->path); }
    }

    /** @param array<string,string> $files */
    private function build(array $files, int $blockMax = 1024): void
    {
        $w = VolumeWriter::create(new FileSink($this->path), str_repeat('c', 32), 7, 99, 0, $blockMax);
        foreach ($files as $p => $data) {
            $w->beginEntry(Format::TYPE_FILE, $p, 0640, 42, true);
            $w->write($data);
            $w->endEntry();
        }
        $w->finish();
    }

    private function readAll(VolumeReader $r, array $entry): string
    {
        $out = '';
        $r->readEntry($entry, function (string $plain) use (&$out): void { $out .= $plain; });
        return $out;
    }

    public function testRoundTripsEveryEntry(): void
    {
        $files = ['a.txt' => str_repeat('A', 3000), 'empty.txt' => '', 'dir/b.bin' => random_bytes(2049)];
        $this->build($files);
        $r = new VolumeReader(new FileSource($this->path));
        $this->assertSame(7, $r->header()['volume']);
        $index = $r->index();
        $this->assertSame(array_keys($files), array_column($index, 'path'));
        foreach ($index as $e) {
            $this->assertSame($files[$e['path']], $this->readAll($r, $e));
        }
        $this->assertSame(['entries' => 3, 'bytes' => 5049], $r->verify());
    }

    public function testNonUtf8PathRoundTrip(): void
    {
        $name = "wp-content/uploads/\xFFweird\xC3.txt";
        $this->build([$name => 'payload']);
        $r = new VolumeReader(new FileSource($this->path));
        $this->assertSame($name, $r->index()[0]['path']);
    }

    public function testResumesFromAGivenBlock(): void
    {
        $this->build(['big' => str_repeat('Z', 5000)]);
        $r = new VolumeReader(new FileSource($this->path));
        $e = $r->index()[0];
        $seen = [];
        $next = $r->readEntry($e, function (string $p, int $n) use (&$seen): void { $seen[] = $n; }, 0,
            function () use (&$seen): bool { return count($seen) >= 2; });
        $this->assertSame(2, $next);
        $next = $r->readEntry($e, function (string $p, int $n) use (&$seen): void { $seen[] = $n; }, $next);
        $this->assertSame(-1, $next);
        $this->assertSame([0, 1, 2, 3, 4], $seen);
    }

    public function testDetectsCorruptBlock(): void
    {
        $this->build(['a' => str_repeat('A', 3000)]);
        $bytes = file_get_contents($this->path);
        $bytes[Format::HEADER_SIZE + 40] = chr(ord($bytes[Format::HEADER_SIZE + 40]) ^ 0xFF);
        file_put_contents($this->path, $bytes);
        $r = new VolumeReader(new FileSource($this->path));
        $this->expectException(FormatException::class);
        $r->verify();
    }

    public function testTruncatedVolumeIsReportedAsIncomplete(): void
    {
        $this->build(['a' => 'x']);
        $fh = fopen($this->path, 'r+b');
        ftruncate($fh, filesize($this->path) - 10);
        fclose($fh);
        $this->expectExceptionMessage('footer missing');
        (new VolumeReader(new FileSource($this->path)))->index();
    }

    /**
     * Rewrites the index of a built volume and recomputes the footer sha, so the
     * checksum passes and only the shape/decoding checks can fail.
     * $mutate receives the decoded index array and returns the new index (any JSON-able
     * value), or a string used as the raw index bytes as-is.
     */
    private function rewriteIndex(callable $mutate): void
    {
        $bytes = file_get_contents($this->path);
        $f = Format::parseFooter(substr($bytes, -Format::FOOTER_SIZE));
        $head = substr($bytes, 0, $f['index_offset']);
        $entries = json_decode((string) gzdecode(substr($bytes, $f['index_offset'], $f['index_len'])), true);
        $new = $mutate($entries);
        $raw = is_string($new)
            ? $new
            : gzencode(json_encode($new, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $out = $head . $raw . Format::footer(strlen($head), strlen($raw), hash('sha256', $raw, true));
        file_put_contents($this->path, $out);
    }

    public function testIndexThatIsAScalarIsMalformed(): void
    {
        $this->build(['a' => 'x']);
        $this->rewriteIndex(function () { return 5; });
        $this->expectException(FormatException::class);
        $this->expectExceptionMessage('index is malformed');
        (new VolumeReader(new FileSource($this->path)))->index();
    }

    public function testEntryMissingBlockListIsMalformed(): void
    {
        $this->build(['a' => 'x']);
        $this->rewriteIndex(function (array $e) { unset($e[0]['b']); return $e; });
        $this->expectException(FormatException::class);
        $this->expectExceptionMessage('index is malformed');
        (new VolumeReader(new FileSource($this->path)))->index();
    }

    public function testBlockTupleWithThreeItemsIsMalformed(): void
    {
        $this->build(['a' => str_repeat('A', 3000)]);
        $this->rewriteIndex(function (array $e) { $e[0]['b'][0] = array_slice($e[0]['b'][0], 0, 3); return $e; });
        $this->expectException(FormatException::class);
        $this->expectExceptionMessage('index is malformed');
        (new VolumeReader(new FileSource($this->path)))->index();
    }

    public function testDuplicateEntryOffsetsAreRejected(): void
    {
        $this->build(['a' => 'x', 'b' => 'y']);
        $this->rewriteIndex(function (array $e) { $e[1]['ho'] = $e[0]['ho']; return $e; });
        $this->expectException(FormatException::class);
        $this->expectExceptionMessage('index has duplicate entry offsets');
        (new VolumeReader(new FileSource($this->path)))->index();
    }

    public function testNegativeFromBlockIsAnInvalidArgument(): void
    {
        $this->build(['big' => str_repeat('Z', 5000)]);
        $r = new VolumeReader(new FileSource($this->path));
        $e = $r->index()[0];
        $this->expectException(\InvalidArgumentException::class);
        $r->readEntry($e, function (): void {}, -1);
    }

    public function testNonGzipIndexBytesAreRejectedWithoutWarning(): void
    {
        $this->build(['a' => 'x']);
        $this->rewriteIndex(function () { return 'this is not gzip at all'; });
        $this->expectException(FormatException::class);
        $this->expectExceptionMessage('index does not decompress');
        (new VolumeReader(new FileSource($this->path)))->index();
    }

    public function testEntryAtFindsEntryByHeaderOffsetAndRejectsUnknownOffsets(): void
    {
        $this->build(['a' => 'x', 'b' => 'yy']);
        $r = new VolumeReader(new FileSource($this->path));
        $second = $r->index()[1];
        $this->assertSame('b', $r->entryAt((int) $second['ho'])['path']);
        $this->expectException(FormatException::class);
        $r->entryAt(999999);
    }
}
