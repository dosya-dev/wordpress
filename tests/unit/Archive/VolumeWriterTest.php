<?php
namespace Dosya\Backup\Tests\unit\Archive;

use Dosya\Backup\Archive\FileSink;
use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\VolumeWriter;
use PHPUnit\Framework\TestCase;

final class VolumeWriterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/vw-' . bin2hex(random_bytes(4)) . '.dosya';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) { unlink($this->path); }
    }

    public function testSplitsDataIntoBlocksAndRecordsIndex(): void
    {
        $w = VolumeWriter::create(new FileSink($this->path), str_repeat('0', 32), 1, 100, 0, 1024);
        $w->beginEntry(Format::TYPE_FILE, 'a/b.txt', 0644, 50, true);
        $w->write(str_repeat('x', 2500));
        $e = $w->endEntry();
        $this->assertSame(2500, $e['s']);
        $this->assertCount(3, $e['b']);
        $this->assertSame([1024, 1024, 452], array_column($e['b'], 2));
        $this->assertSame(Format::HEADER_SIZE, $e['ho']);
        $size = $w->finish();
        $this->assertSame(filesize($this->path), $size);
        $this->assertSame(Format::MAGIC_END, substr(file_get_contents($this->path), -20, 8));
    }

    public function testCheckpointAndResumeContinueAnOpenEntry(): void
    {
        $w = VolumeWriter::create(new FileSink($this->path), str_repeat('1', 32), 1, 100, 0, 1024);
        $w->beginEntry(Format::TYPE_FILE, 'big.bin', 0644, 1, false);
        $w->write(str_repeat('a', 1500));
        $state = $w->checkpoint();
        $w->close();
        // simulate a crashed step that appended garbage after the checkpoint
        file_put_contents($this->path, 'GARBAGE', FILE_APPEND);

        $w2 = VolumeWriter::resume(new FileSink($this->path, $state['o']), $state);
        $this->assertTrue($w2->hasOpenEntry());
        $w2->write(str_repeat('b', 10));
        $e = $w2->endEntry();
        $this->assertSame(1510, $e['s']);
        $this->assertSame([1024, 476, 10], array_column($e['b'], 2));
        $w2->finish();
        $this->assertStringNotContainsString('GARBAGE', file_get_contents($this->path));
    }

    public function testStateIsJsonSafeForNonUtf8Paths(): void
    {
        $w = VolumeWriter::create(new FileSink($this->path), str_repeat('2', 32), 1, 100);
        $w->beginEntry(Format::TYPE_FILE, "x/\xFF.txt", 0644, 1, true);
        $w->write('data');
        $json = json_encode($w->checkpoint(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('"pb"', $json);
    }

    public function testResumeRefusesFileShorterThanCheckpoint(): void
    {
        file_put_contents($this->path, str_repeat('s', 10));
        $this->expectException(\RuntimeException::class);
        new FileSink($this->path, 100);
    }

    public function testResumeRejectsStateWithMissingKeys(): void
    {
        $sink = new FileSink($this->path);
        $this->expectException(\InvalidArgumentException::class);
        VolumeWriter::resume($sink, ['o' => 64]);
    }
}
