<?php
namespace Dosya\Backup\Tests\unit\Wp\Admin\ViewModel;

use Dosya\Backup\Wp\Admin\ViewModel\RunningCard;
use PHPUnit\Framework\TestCase;

final class RunningCardTest extends TestCase
{
    private function t(): callable { return static function (string $s): string { return $s; }; }

    public function testArchivePhaseHasPercentAndDetail(): void
    {
        $c = RunningCard::build(['kind' => 'backup', 'state' => 'running', 'progress' => ['phase' => 'archive', 'bytes_done' => 732000000, 'bytes_total' => 1181116006]], $this->t());
        $this->assertSame('Files', $c['phase_text']);
        $this->assertSame('phase_backup_files', $c['phase_key']);
        $this->assertSame(62, $c['percent']);
        $this->assertSame('698 MB of 1.1 GB', $c['detail']);
        $this->assertFalse($c['maintenance']);
        $this->assertSame('Backing up this site', $c['heading']);
    }

    public function testRemoteVolumes(): void
    {
        $c = RunningCard::build(['kind' => 'backup', 'state' => 'running', 'progress' => ['phase' => 'remote', 'step' => 'volumes', 'volume' => 2, 'volumes' => 5]], $this->t());
        $this->assertSame('Uploading to dosya, volume 2 of 5', $c['phase_text']);
        $this->assertSame('Uploading to dosya', $c['heading']);
    }

    public function testBackupPhases(): void
    {
        $cases = [
            [['phase' => 'queued'], 'Preparing'],
            [['phase' => 'db_dump', 'tables_done' => 3, 'tables' => 12], 'Database'],
            [['phase' => 'file_scan', 'files' => 1200, 'bytes' => 1048576], 'Scanning'],
            [['phase' => 'finalize', 'step' => 'seal'], 'Finishing'],
            [['phase' => 'finalize', 'step' => 'hash'], 'Finishing'],
            [['phase' => 'finalize', 'step' => 'write'], 'Finishing'],
            [['phase' => 'remote', 'step' => 'register'], 'Registering with dosya'],
            [['phase' => 'remote', 'step' => 'manifests'], 'Uploading manifests'],
            [['phase' => 'remote', 'step' => 'complete'], 'Finishing the upload to dosya'],
        ];
        foreach ($cases as $case) {
            $c = RunningCard::build(['kind' => 'backup', 'state' => 'running', 'progress' => $case[0]], $this->t());
            $this->assertSame($case[1], $c['phase_text'], json_encode($case[0]));
            $this->assertFalse($c['maintenance']);
        }
        $c = RunningCard::build(['kind' => 'backup', 'state' => 'running', 'progress' => ['phase' => 'db_dump', 'tables_done' => 3, 'tables' => 12]], $this->t());
        $this->assertSame('3 of 12 tables', $c['detail']);
        $this->assertSame(25, $c['percent']);
        $c = RunningCard::build(['kind' => 'backup', 'state' => 'running', 'progress' => ['phase' => 'file_scan', 'files' => 1200, 'bytes' => 1048576]], $this->t());
        $this->assertSame('1200 files, 1 MB', $c['detail']);
        $this->assertNull($c['percent']);
    }

    public function testRestorePhasesSetMaintenance(): void
    {
        $cases = [
            ['queued', 'Preparing'], ['snapshot', 'Making a safety copy'], ['files_fetch', 'Reading the backup'],
            ['db_import', 'Database'], ['db_transform', 'Updating links and paths'], ['files_move', 'Files'],
            ['db_swap', 'Switching to the restored database'], ['finalize', 'Finishing'], ['health', 'Checking the site'], ['cleanup', 'Cleaning up'],
        ];
        foreach ($cases as $case) {
            $c = RunningCard::build(['kind' => 'restore', 'state' => 'running', 'progress' => ['phase' => $case[0]]], $this->t());
            $this->assertSame($case[1], $c['phase_text'], $case[0]);
            $this->assertSame($case[0] !== 'queued', $c['maintenance'], $case[0]);
            $this->assertSame('Restoring the backup', $c['heading']);
        }
        $c = RunningCard::build(['kind' => 'restore', 'state' => 'running', 'progress' => ['phase' => 'db_import', 'done' => 1, 'total' => 4]], $this->t());
        $this->assertSame('1 of 4', $c['detail']);
        $this->assertSame(25, $c['percent']);
    }

    public function testUnknownPhaseAndMissingProgressDoNotBreak(): void
    {
        $c = RunningCard::build(['kind' => 'backup', 'state' => 'queued', 'progress' => null], $this->t());
        $this->assertSame('Preparing', $c['phase_text']);
        $c = RunningCard::build(['kind' => 'backup', 'state' => 'running', 'progress' => ['phase' => 'zzz']], $this->t());
        $this->assertSame('Working', $c['phase_text']);
    }

    public function testPhaseMapKeysAreParallel(): void
    {
        foreach (RunningCard::PHASES as $path => $key) {
            $this->assertArrayHasKey($key, RunningCard::PHASE_TEXT, $path);
        }
        foreach (RunningCard::PHASE_TEXT as $key => $text) {
            $this->assertStringNotContainsString("\u{2014}", $text);
        }
        $this->assertArrayHasKey('backup.remote.volumes', RunningCard::PHASES);
        $this->assertArrayHasKey('restore.cleanup', RunningCard::PHASES);
    }

    public function testFinalizeWithoutStepAndOtherStepless(): void
    {
        foreach ([['finalize', 'phase_finishing', 'Finishing'], ['remote', 'phase_registering', 'Registering with dosya'], ['skipped', 'phase_done', 'Finishing'], ['done', 'phase_done', 'Finishing']] as $c) {
            $r = RunningCard::build(['kind' => 'backup', 'state' => 'running', 'progress' => ['phase' => $c[0]]], $this->t());
            $this->assertSame($c[1], $r['phase_key'], $c[0]);
            $this->assertSame($c[2], $r['phase_text']);
        }
        $r = RunningCard::build(['kind' => 'restore', 'state' => 'running', 'progress' => ['phase' => 'done']], $this->t());
        $this->assertSame('phase_done', $r['phase_key']);
        $r = RunningCard::build(['kind' => 'backup', 'state' => 'running', 'progress' => ['phase' => 'finalize', 'step' => 'hash']], $this->t());
        $this->assertSame('phase_finishing', $r['phase_key']);
        $r = RunningCard::build(['kind' => 'backup', 'state' => 'running', 'progress' => ['phase' => 'finalize', 'step' => 'remote']], $this->t());
        $this->assertSame('phase_finishing', $r['phase_key']);
    }

    public function testMaintenanceRange(): void
    {
        foreach (['queued' => false, 'snapshot' => true, 'files_fetch' => true, 'db_swap' => true, 'cleanup' => true, 'done' => false] as $ph => $exp) {
            $r = RunningCard::build(['kind' => 'restore', 'state' => 'running', 'progress' => ['phase' => $ph]], $this->t());
            $this->assertSame($exp, $r['maintenance'], $ph);
        }
    }

    public function testRestoreHeadingWithAndWithoutDate(): void
    {
        $s = ['kind' => 'restore', 'state' => 'running', 'progress' => ['phase' => 'snapshot']];
        $this->assertSame('Restoring the backup', RunningCard::build($s, $this->t())['heading']);
        $this->assertSame('Restoring the backup', RunningCard::build($s, $this->t(), ['now' => 1791767600])['heading']);
        $h = RunningCard::build($s, $this->t(), ['backup_created' => 1791767600 - 90000, 'now' => 1791767600, 'tz' => 'UTC'])['heading'];
        $this->assertSame('Restoring the backup from yesterday 00:13', $h);
    }

    public function testSingularFile(): void
    {
        $c = RunningCard::build(['kind' => 'backup', 'state' => 'running', 'progress' => ['phase' => 'file_scan', 'files' => 1, 'bytes' => 2048]], $this->t());
        $this->assertSame('1 file, 2 KB', $c['detail']);
    }
}
