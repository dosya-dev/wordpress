<?php
namespace Dosya\Backup\Tests\unit\Support;

use Dosya\Backup\Archive\BackupReader;
use Dosya\Backup\Archive\FileSink;
use Dosya\Backup\Archive\FileSource;
use Dosya\Backup\Archive\Manifest;
use Dosya\Backup\Archive\Sink;
use Dosya\Backup\Archive\Source;
use Dosya\Backup\Archive\VolumeSet;

final class ArchiveFixture
{
    /** @return array{0:Manifest,1:BackupReader} */
    public static function build(string $dir, array $entries): array
    {
        if (!is_dir($dir)) { mkdir($dir, 0777, true); }
        $id = bin2hex(random_bytes(16));
        $rows = [];
        $vs = new VolumeSet($id, time(), 1 << 20, function (int $n, int $at) use ($dir): Sink {
            return new FileSink(sprintf('%s/v%04d.dosya', $dir, $n), $at);
        }, function (array $e) use (&$rows): void { $rows[] = $e; }, 1024);
        foreach ($entries as $path => $e) {
            $vs->beginEntry($e['t'], (string) $path, $e['m'] ?? 0644, $e['mt'] ?? 1700000000, false);
            if (($e['data'] ?? '') !== '') { $vs->write($e['data']); }
            $vs->endEntry();
        }
        $vols = $vs->finish();
        $m = Manifest::fromEntries(['backup_id' => $id, 'created' => time(), 'kind' => 'full', 'volumes' => $vols], $rows);
        return [$m, new BackupReader(function (string $b, int $v) use ($dir): Source { return new FileSource(sprintf('%s/v%04d.dosya', $dir, $v)); })];
    }
}
