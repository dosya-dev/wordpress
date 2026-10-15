<?php
namespace Dosya\Backup\Tests\integration\Support;

use Dosya\Backup\Archive\BackupReader;
use Dosya\Backup\Archive\FileSink;
use Dosya\Backup\Archive\FileSource;
use Dosya\Backup\Archive\Manifest;
use Dosya\Backup\Archive\Sink;
use Dosya\Backup\Archive\Source;
use Dosya\Backup\Archive\VolumeSet;
use Dosya\Backup\Db\Db;
use Dosya\Backup\Db\DbDumper;
use Dosya\Backup\Support\Budget;

final class Fixture
{
    /** @return array{0:Manifest,1:BackupReader} */
    public static function dump(Db $db, string $dir, int $batch = 1000, ?Budget $budget = null): array
    {
        if (!is_dir($dir)) { mkdir($dir, 0777, true); }
        $id = bin2hex(random_bytes(16));
        $rows = [];
        $sinkFor = function (int $n, int $at) use ($dir): Sink { return new FileSink(sprintf('%s/v%04d.dosya', $dir, $n), $at); };
        $record = function (array $e) use (&$rows): void { $rows[] = $e; };
        $dumper = new DbDumper($db, $batch);
        $c = $dumper->plan(false, []);
        $state = null;
        $guard = 0;
        do {
            $vs = $state === null ? new VolumeSet($id, time(), 1 << 26, $sinkFor, $record) : VolumeSet::resume($state, $sinkFor, $record);
            $c = $dumper->step($c, $budget ?? Budget::unlimited(), $vs);
            $state = $vs->suspend();
            $c = json_decode(json_encode($c, JSON_THROW_ON_ERROR), true);
        } while (empty($c['done']) && ++$guard < 10000);
        $vs = VolumeSet::resume($state, $sinkFor, $record);
        $vols = $vs->finish();
        $m = Manifest::fromEntries(['backup_id' => $id, 'created' => time(), 'kind' => 'full', 'volumes' => $vols, 'rows' => $c['rows'],
            'site' => ['prefix' => $db->prefix()]], $rows);
        $reader = new BackupReader(function (string $b, int $v) use ($dir): Source { return new FileSource(sprintf('%s/v%04d.dosya', $dir, $v)); });
        return [$m, $reader];
    }

    /** Replays a dumped table into $target (used by dumper tests; the importer has its own path). */
    public static function replay(Db $db, Manifest $m, BackupReader $r, string $table, string $target): void
    {
        $swap = function (string $s) use ($db, $target): string { return str_replace('`' . DbDumper::TABLE_TOKEN . '`', $db->ident($target), $s); };
        foreach (DbDumper::splitStatements($r->readAll($m->tables[$table]['schema'])) as $s) { $db->exec($swap($s)); }
        foreach ($m->tables[$table]['parts'] as $loc) {
            foreach (DbDumper::splitStatements($r->readAll($loc)) as $s) { $db->exec($swap($s)); }
        }
    }
}
