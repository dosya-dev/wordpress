<?php
namespace Dosya\Backup\Tests\unit\Wp\Admin\ViewModel;

use Dosya\Backup\Wp\Admin\ViewModel\ResultBanner;
use PHPUnit\Framework\TestCase;

final class ResultBannerTest extends TestCase
{
    private const NOW = 1791767600; // 2026-10-12 06:13 UTC

    private function t(): callable { return static function (string $s): string { return $s; }; }
    private function ctx(array $o = []): array { return array_replace(['now' => self::NOW, 'tz' => 'UTC'], $o); }

    public function testBackupDoneStoredInDosya(): void
    {
        $b = ResultBanner::build(['kind' => 'backup', 'state' => 'done', 'error' => null, 'result' => ['backup_id' => 'a', 'size' => 1288490188, 'warnings' => [], 'remote' => ['locked_until' => 1, 'expires_at' => 2, 'bytes_new' => 1, 'bytes_total' => 2]]], $this->ctx(), $this->t());
        $this->assertSame('success', $b['tone']);
        $this->assertSame('Backup complete, 1.2 GB, stored in dosya, not yet verified.', $b['text']);
        $this->assertNull($b['action']);
    }

    public function testBackupDoneOnThisServer(): void
    {
        $b = ResultBanner::build(['kind' => 'backup', 'state' => 'done', 'result' => ['backup_id' => 'a', 'size' => 1288490188, 'warnings' => []]], $this->ctx(), $this->t());
        $this->assertSame('success', $b['tone']);
        $this->assertSame('Backup complete, 1.2 GB, on this server.', $b['text']);
    }

    public function testBackupDoneWithWarningsIsCaution(): void
    {
        $b = ResultBanner::build(['kind' => 'backup', 'state' => 'done', 'result' => ['backup_id' => 'a', 'size' => 1288490188, 'warnings' => ['Skipped unreadable file a.txt', 'Other']]], $this->ctx(), $this->t());
        $this->assertSame('caution', $b['tone']);
        $this->assertSame('Backup complete, 1.2 GB, on this server. With 2 warnings: Skipped unreadable file a.txt', $b['text']);
        $b = ResultBanner::build(['kind' => 'backup', 'state' => 'done', 'result' => ['backup_id' => 'a', 'size' => 10, 'warnings' => ['One']]], $this->ctx(), $this->t());
        $this->assertStringContainsString('With 1 warning: One', $b['text']);
    }

    public function testRestoreDoneOffersUndo(): void
    {
        $b = ResultBanner::build(['kind' => 'restore', 'state' => 'done', 'result' => ['backup_id' => 'a', 'snapshot_id' => 'snap1', 'migration' => false, 'health' => ['ok' => true, 'status' => 200], 'collations' => [], 'warnings' => []]], $this->ctx(['backup_created' => self::NOW - 90000]), $this->t());
        $this->assertSame('success', $b['tone']);
        $this->assertSame('Site restored to yesterday 00:13. Links checked, cache cleared, maintenance page removed.', $b['text']);
        $this->assertSame(['label' => 'Undo this restore', 'kind' => 'undo', 'id' => 'snap1'], $b['action']);
    }

    public function testRestoreDoneWithoutSnapshotHasNoAction(): void
    {
        $b = ResultBanner::build(['kind' => 'restore', 'state' => 'done', 'result' => ['backup_id' => 'a', 'snapshot_id' => null]], $this->ctx(), $this->t());
        $this->assertNull($b['action']);
        $this->assertSame('success', $b['tone']);
    }

    public function testFailures(): void
    {
        $b = ResultBanner::build(['kind' => 'backup', 'state' => 'failed', 'error' => 'Not enough disk space'], $this->ctx(), $this->t());
        $this->assertSame('error', $b['tone']);
        $this->assertSame('Backup failed: Not enough disk space', $b['text']);
        $this->assertSame('retry', $b['action']['kind']);
        $this->assertSame('Retry now', $b['action']['label']);

        $b = ResultBanner::build(['kind' => 'restore', 'state' => 'failed', 'error' => 'Database import failed'], $this->ctx(['snapshot_id' => 'snap9']), $this->t());
        $this->assertSame('error', $b['tone']);
        $this->assertSame('Restore failed: Database import failed', $b['text']);
        $this->assertSame(['label' => 'Restore safety copy', 'kind' => 'restore_safety', 'id' => 'snap9'], $b['action']);

        $b = ResultBanner::build(['kind' => 'restore', 'state' => 'failed', 'error' => 'Database import failed'], $this->ctx(), $this->t());
        $this->assertSame('log', $b['action']['kind']);
        $this->assertNull($b['action']['id']);
    }

    public function testSkippedAndCancelled(): void
    {
        $b = ResultBanner::build(['kind' => 'backup', 'state' => 'skipped', 'error' => 'A backup ran a moment ago'], $this->ctx(), $this->t());
        $this->assertSame('caution', $b['tone']);
        $this->assertSame('Backup skipped: A backup ran a moment ago', $b['text']);
        $b = ResultBanner::build(['kind' => 'backup', 'state' => 'cancelled'], $this->ctx(), $this->t());
        $this->assertSame('caution', $b['tone']);
        $this->assertSame('Cancelled.', $b['text']);
    }

    public function testMissingErrorFallsBack(): void
    {
        $b = ResultBanner::build(['kind' => 'backup', 'state' => 'failed', 'error' => null], $this->ctx(), $this->t());
        $this->assertSame('Backup failed: Unknown error', $b['text']);
        $this->assertStringNotContainsString("\u{2014}", $b['text']);
    }

    public function testBoundedAndNormalisedError(): void
    {
        $b = ResultBanner::build(['kind' => 'backup', 'state' => 'failed', 'error' => str_repeat('x', 5000)], $this->ctx(), $this->t());
        $this->assertSame('Backup failed: ' . str_repeat('x', 297) . '...', $b['text']);
        $b = ResultBanner::build(['kind' => 'backup', 'state' => 'failed', 'error' => "  disk\n\n  full\t now "], $this->ctx(), $this->t());
        $this->assertSame('Backup failed: disk full now', $b['text']);
        $b = ResultBanner::build(['kind' => 'backup', 'state' => 'failed', 'error' => str_repeat('y', 300)], $this->ctx(), $this->t());
        $this->assertSame('Backup failed: ' . str_repeat('y', 300), $b['text']);
        $b = ResultBanner::build(['kind' => 'backup', 'state' => 'failed', 'error' => str_repeat("\u{e9}", 400)], $this->ctx(), $this->t());
        $this->assertSame('Backup failed: ' . str_repeat("\u{e9}", 297) . '...', $b['text']);
    }

    public function testSnapshotIdMustBeNonEmptyString(): void
    {
        foreach ([0, false, '', null, 5] as $bad) {
            $b = ResultBanner::build(['kind' => 'restore', 'state' => 'done', 'result' => ['snapshot_id' => $bad]], $this->ctx(), $this->t());
            $this->assertNull($b['action'], var_export($bad, true));
            $f = ResultBanner::build(['kind' => 'restore', 'state' => 'failed', 'error' => 'x'], $this->ctx(['snapshot_id' => $bad]), $this->t());
            $this->assertSame('log', $f['action']['kind'], var_export($bad, true));
        }
    }

    public function testSkippedWithoutReason(): void
    {
        $b = ResultBanner::build(['kind' => 'backup', 'state' => 'skipped', 'error' => ''], $this->ctx(), $this->t());
        $this->assertSame('Backup skipped.', $b['text']);
        $this->assertSame('caution', $b['tone']);
    }
}
