<?php
namespace Dosya\Backup\Tests\unit\Archive;

use Dosya\Backup\Archive\Bin;
use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\FormatException;
use Dosya\Backup\Archive\PathCodec;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testU64RoundTripAbove4GiB(): void
    {
        $v = 5 * 1024 * 1024 * 1024 + 7;
        $this->assertSame($v, Bin::readU64(Bin::u64($v), 0));
        $this->assertSame(8, strlen(Bin::u64($v)));
    }

    public function testTruncatedReadThrows(): void
    {
        $this->expectException(FormatException::class);
        Bin::readU32("\x01\x02", 0);
    }

    public function testVolumeHeaderRoundTrip(): void
    {
        $id = str_repeat('ab', 16);
        $h = Format::volumeHeader($id, 3, 1791607200, Format::FLAG_ENCRYPTED);
        $this->assertSame(64, strlen($h));
        $this->assertSame(
            ['flags' => 1, 'backup_id' => $id, 'volume' => 3, 'created' => 1791607200],
            Format::parseVolumeHeader($h)
        );
    }

    public function testRejectsForeignFile(): void
    {
        $this->expectException(FormatException::class);
        Format::parseVolumeHeader(str_repeat("\0", 64));
    }

    public function testFooterRoundTrip(): void
    {
        $sha = hash('sha256', 'x', true);
        $f = Format::footer(6000000000, 1234, $sha);
        $this->assertSame(64, strlen($f));
        $this->assertSame(['index_offset' => 6000000000, 'index_len' => 1234, 'index_sha' => $sha], Format::parseFooter($f));
    }

    public function testTreeHashOfNoBlocksIsHashOfEmptyString(): void
    {
        $this->assertSame(hash('sha256', ''), Format::treeHash([]));
    }

    public function testPathCodecKeepsInvalidUtf8Bytes(): void
    {
        $bad = "wp-content/uploads/caf\xE9.jpg";
        $packed = PathCodec::pack($bad);
        $this->assertArrayHasKey('pb', $packed);
        json_encode($packed, JSON_THROW_ON_ERROR);
        $this->assertSame($bad, PathCodec::unpack($packed));
        $this->assertSame(['p' => 'a/b.txt'], PathCodec::pack('a/b.txt'));
    }

    public function testCompressionSkipsCompressedMedia(): void
    {
        $this->assertFalse(Format::shouldCompress('a/b/Photo.JPG'));
        $this->assertTrue(Format::shouldCompress('wp-includes/version.php'));
    }
}
