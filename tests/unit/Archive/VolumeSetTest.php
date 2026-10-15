<?php
namespace Dosya\Backup\Tests\unit\Archive;

use Dosya\Backup\Archive\BackupReader;
use Dosya\Backup\Archive\FileSink;
use Dosya\Backup\Archive\FileSource;
use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\Sink;
use Dosya\Backup\Archive\Source;
use Dosya\Backup\Archive\VolumeSet;
use Dosya\Backup\Support\Fs;
use PHPUnit\Framework\TestCase;

final class VolumeSetTest extends TestCase
{
    private string $dir;
    private string $id;
    private array $recorded = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vs-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->id = bin2hex(random_bytes(16));
    }

    protected function tearDown(): void
    {
        Fs::rrmdir($this->dir);
    }

    private function sinkFor(): callable
    {
        return function (int $n, int $at): Sink { return new FileSink(sprintf('%s/v%04d.dosya', $this->dir, $n), $at); };
    }

    private function record(): callable
    {
        return function (array $e): void { $this->recorded[] = $e; };
    }

    private function reader(): BackupReader
    {
        return new BackupReader(function (string $b, int $v): Source { return new FileSource(sprintf('%s/v%04d.dosya', $this->dir, $v)); });
    }

    public function testRollsToANewVolumeAtTheTarget(): void
    {
        // each entry below is ~2710 bytes on disk, so a 2000-byte target seals after every entry
        $vs = new VolumeSet($this->id, 1, 2000, $this->sinkFor(), $this->record(), 1024);
        foreach (['a' => 2500, 'b' => 2500, 'c' => 10] as $name => $len) {
            $vs->beginEntry(Format::TYPE_FILE, $name, 0644, 1, false);
            $vs->write(str_repeat($name, $len));
            $vs->endEntry();
        }
        $vols = $vs->finish();
        $this->assertSame([1, 2, 3], array_column($vols, 'v'));
        $this->assertSame([1, 2, 3], array_map(function ($e) { return $e['loc']['v']; }, $this->recorded));
        $this->assertSame(str_repeat('b', 2500), $this->reader()->readAll($this->recorded[1]['loc']));
    }

    public function testSuspendAndResumeMidEntry(): void
    {
        $vs = new VolumeSet($this->id, 1, 1 << 20, $this->sinkFor(), $this->record(), 1024);
        $vs->beginEntry(Format::TYPE_FILE, 'f', 0644, 1, true);
        $vs->write(str_repeat('q', 1500));
        $state = $vs->suspend();
        $state = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true);

        $vs2 = VolumeSet::resume($state, $this->sinkFor(), $this->record());
        $vs2->write(str_repeat('r', 600));
        $e = $vs2->endEntry();
        $vs2->finish();
        $this->assertSame(str_repeat('q', 1500) . str_repeat('r', 600), $this->reader()->readAll($e['loc']));
    }

    public function testResumeRejectsInvalidState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VolumeSet::resume(['id' => 'x'], $this->sinkFor(), $this->record());
    }

    public function testWriteBeforeAnyBeginEntryThrows(): void
    {
        $vs = new VolumeSet($this->id, 1, 1 << 20, $this->sinkFor(), $this->record(), 1024);
        $this->expectException(\LogicException::class);
        $vs->write('x');
    }

    public function testWriteAfterSuspendThrows(): void
    {
        $vs = new VolumeSet($this->id, 1, 1 << 20, $this->sinkFor(), $this->record(), 1024);
        $vs->beginEntry(Format::TYPE_FILE, 'f', 0644, 1, false);
        $vs->write('x');
        $vs->suspend();
        $this->expectException(\LogicException::class);
        $vs->write('y');
    }
}
