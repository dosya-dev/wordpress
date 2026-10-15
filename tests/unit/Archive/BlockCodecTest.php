<?php
namespace Dosya\Backup\Tests\unit\Archive;

use Dosya\Backup\Archive\Bin;
use Dosya\Backup\Archive\BlockCodec;
use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\FormatException;
use PHPUnit\Framework\TestCase;

final class BlockCodecTest extends TestCase
{
    public function testCompressibleDataIsDeflated(): void
    {
        $plain = str_repeat('hello world ', 10000);
        $rec = BlockCodec::encode($plain, true);
        $this->assertLessThan(strlen($plain), strlen($rec));
        $this->assertSame(1, ord($rec[4]));
        $this->assertSame($plain, BlockCodec::decode($rec));
    }

    public function testIncompressibleDataStaysRaw(): void
    {
        $plain = random_bytes(5000);
        $rec = BlockCodec::encode($plain, true);
        $this->assertSame(0, ord($rec[4]));
        $this->assertSame(strlen($plain) + BlockCodec::OVERHEAD, strlen($rec));
        $this->assertSame($plain, BlockCodec::decode($rec));
    }

    public function testFlippedPayloadByteIsDetected(): void
    {
        $rec = BlockCodec::encode('abcdef', false);
        $rec[10] = 'X';
        $this->expectException(FormatException::class);
        BlockCodec::decode($rec);
    }

    public function testOversizedPlainLengthIsRefusedBeforeInflating(): void
    {
        $plain = str_repeat('a', Format::BLOCK_MAX + 1);
        $z = gzdeflate($plain, 6);
        $rec = Bin::u32(strlen($z)) . Bin::u8(Format::CODEC_DEFLATE) . Bin::u32(strlen($plain)) . $z . hash('sha256', $plain, true);
        $this->expectException(FormatException::class);
        $this->expectExceptionMessage('block too large');
        BlockCodec::decode($rec);
    }

    public function testCorruptDeflateStreamFailsWithoutAPhpWarning(): void
    {
        $rec = BlockCodec::encode(str_repeat('hello world ', 1000), true);
        $this->assertSame(Format::CODEC_DEFLATE, ord($rec[4]));
        $storedLen = Bin::readU32($rec, 0);
        for ($i = 9; $i < 9 + $storedLen; $i++) {
            $rec[$i] = "\xFF";
        }
        $warnings = [];
        set_error_handler(function (int $no, string $msg) use (&$warnings): bool {
            if ((error_reporting() & $no) !== 0) {
                $warnings[] = $msg; // a warning the caller would see; a silenced one (@) is not reported
            }
            return true;
        });
        try {
            BlockCodec::decode($rec);
            $this->fail('expected a FormatException');
        } catch (FormatException $e) {
            $this->assertSame('block does not inflate', $e->getMessage());
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $warnings);
    }

    public function testEncodeRefusesPlaintextLongerThanABlock(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BlockCodec::encode(str_repeat('a', Format::BLOCK_MAX + 1), true);
    }
}
