<?php
namespace Dosya\Backup\Tests\unit\Wp\Admin\ViewModel;

use Dosya\Backup\Wp\Admin\ViewModel\StatusCard;
use PHPUnit\Framework\TestCase;

final class StatusCardTest extends TestCase
{
    private const NOW = 1791800000; // 2026-10-12 ~06:13 UTC
    private $t;

    protected function setUp(): void { $this->t = static function (string $s): string { return $s; }; }

    private function base(array $over = []): array
    {
        return array_replace_recursive([
            'now' => self::NOW, 'tz' => 'UTC', 'last_job' => null, 'last_backup' => null,
            'remote' => ['connected' => false, 'key_state' => 'none', 'used' => null, 'total' => null],
            'schedule' => ['on' => true, 'interval' => 86400, 'next_run' => self::NOW + 7 * 3600],
            'running' => false,
        ], $over);
    }

    public function testNextRunTextNamesDayAndTime(): void
    {
        $this->assertSame('Next backup today at 17:13.', StatusCard::nextRunText(self::NOW + 7 * 3600, self::NOW, 'UTC', $this->t));
        $this->assertSame('Next backup tomorrow at 10:13.', StatusCard::nextRunText(self::NOW + 86400, self::NOW, 'UTC', $this->t));
    }

    public function testNothingYetIsNeutral(): void
    {
        $c = StatusCard::build($this->base(), $this->t);
        $this->assertSame('neutral', $c['tone']);
        $this->assertSame('No backup yet', $c['headline']);
        $this->assertStringContainsString('Daily backups are already on', $c['sub']);
        $this->assertSame('backup', $c['button']['action']);
    }

    public function testFirstRunFailedIsRedNotGrey(): void
    {
        $c = StatusCard::build($this->base(['last_job' => ['kind' => 'backup', 'state' => 'failed', 'error' => 'Not enough disk space: needed 1.3 GB, found 0.4 GB', 'result' => null, 'created_at' => self::NOW - 600]]), $this->t);
        $this->assertSame('error', $c['tone']);
        $this->assertStringStartsWith('Last backup failed: Not enough disk space', $c['headline']);
        $this->assertStringContainsString('No earlier backup exists', $c['sub']);
        $this->assertSame('retry', $c['button']['action']);
    }

    public function testVerifiedOnlyWhenServerConfirmed(): void
    {
        $lb = ['id' => 'a', 'created' => self::NOW - 7200, 'size' => 1288490188, 'remote_state' => 'uploaded', 'locked_until' => self::NOW + 79200];
        $c = StatusCard::build($this->base(['last_backup' => $lb, 'remote' => ['connected' => true, 'key_state' => 'ok', 'used' => 3650722201, 'total' => 5368709120]]), $this->t);
        $this->assertSame('success', $c['tone']);
        $this->assertSame('Uploaded to dosya, not yet verified, 2 hours ago', $c['headline']);

        $lb['remote_state'] = 'verified';
        $c = StatusCard::build($this->base(['last_backup' => $lb, 'remote' => ['connected' => true, 'key_state' => 'ok', 'used' => 3650722201, 'total' => 5368709120]]), $this->t);
        $this->assertSame('Verified restorable, 2 hours ago', $c['headline']);
        $this->assertStringContainsString('protected until', $c['sub']);
        $this->assertStringContainsString('3.4 of 5 GB used', $c['sub']);

        $lb['remote_state'] = 'none';
        $c = StatusCard::build($this->base(['last_backup' => $lb]), $this->t);
        $this->assertSame('Backed up on this server, 2 hours ago', $c['headline']);
    }

    public function testOverdueIsCaution(): void
    {
        $lb = ['id' => 'a', 'created' => self::NOW - 3 * 86400, 'size' => 100, 'remote_state' => 'none', 'locked_until' => null];
        $c = StatusCard::build($this->base(['last_backup' => $lb]), $this->t);
        $this->assertSame('caution', $c['tone']);
        $this->assertSame('Backup overdue', $c['headline']);
    }

    public function testConnectionLostIsCautionOnThisCard(): void
    {
        $lb = ['id' => 'a', 'created' => self::NOW - 3600, 'size' => 100, 'remote_state' => 'none', 'locked_until' => null];
        $c = StatusCard::build($this->base(['last_backup' => $lb, 'remote' => ['connected' => false, 'key_state' => 'unreadable', 'used' => null, 'total' => null]]), $this->t);
        $this->assertSame('caution', $c['tone']);
        $this->assertStringContainsString('dosya connection lost, uploads paused', $c['sub']);
        $this->assertSame('dosya', $c['fix']['target']);
    }

    public function testNearlyFullIsCaution(): void
    {
        $lb = ['id' => 'a', 'created' => self::NOW - 3600, 'size' => 100, 'remote_state' => 'verified', 'locked_until' => null];
        $c = StatusCard::build($this->base(['last_backup' => $lb, 'remote' => ['connected' => true, 'key_state' => 'ok', 'used' => 4831838208, 'total' => 5368709120]]), $this->t);
        $this->assertSame('caution', $c['tone']);
        $this->assertStringContainsString('dosya storage almost full', $c['sub']);
    }

    public function testErrorBeatsCaution(): void
    {
        $c = StatusCard::build($this->base([
            'last_job' => ['kind' => 'backup', 'state' => 'failed', 'error' => 'boom', 'result' => null, 'created_at' => self::NOW - 60],
            'last_backup' => ['id' => 'a', 'created' => self::NOW - 90000, 'size' => 100, 'remote_state' => 'verified', 'locked_until' => null],
            'remote' => ['connected' => true, 'key_state' => 'ok', 'used' => 4831838208, 'total' => 5368709120],
        ]), $this->t);
        $this->assertSame('error', $c['tone']);
        $this->assertStringContainsString('Your last good backup is from', $c['sub']);
    }

    public function testCopyHasNoEmDashes(): void
    {
        foreach ([$this->base(), $this->base(['last_job' => ['kind' => 'backup', 'state' => 'failed', 'error' => 'x', 'result' => null, 'created_at' => self::NOW]])] as $in) {
            $c = StatusCard::build($in, $this->t);
            $this->assertStringNotContainsString("\u{2014}", $c['headline'] . $c['sub']);
        }
    }

    private function lb(array $o = []): array
    {
        return array_merge(['id' => 'a', 'created' => self::NOW - 3600, 'size' => 100, 'remote_state' => 'none', 'locked_until' => null], $o);
    }

    private function failedJob(int $at): array
    {
        return ['kind' => 'backup', 'state' => 'failed', 'error' => 'boom', 'result' => null, 'created_at' => $at];
    }

    public function testStaleFailedJobIsIgnored(): void
    {
        $c = StatusCard::build($this->base(['last_job' => $this->failedJob(self::NOW - 3 * 86400), 'last_backup' => $this->lb()]), $this->t);
        $this->assertSame('success', $c['tone']);
    }

    public function testUploadFailureOfTheLatestBackupIsCaution(): void
    {
        $lb = $this->lb(['id' => 'b1', 'created' => self::NOW - 3600]);
        $job = ['backup_id' => 'b1', 'error' => 'dosya upload failed (503). The backup is kept locally; run it again later.', 'created_at' => self::NOW - 3601] + $this->failedJob(0);
        $ok = ['connected' => true, 'key_state' => 'ok', 'used' => null, 'total' => null];
        $c = StatusCard::build($this->base(['last_job' => $job, 'last_backup' => $lb, 'remote' => $ok]), $this->t);
        $this->assertSame('caution', $c['tone']);
        $this->assertSame('Backed up on this server, 1 hour ago', $c['headline']);
        $this->assertStringContainsString('upload to dosya failed: dosya upload failed (503).', $c['sub']);
        $this->assertSame(['label' => 'View log', 'target' => 'log'], $c['fix']);
        $this->assertSame('backup', $c['button']['action']);
        // a job a second newer than the backup's own timestamp is still that backup's upload, not a failed run
        $c = StatusCard::build($this->base(['last_job' => ['created_at' => self::NOW - 3599] + $job, 'last_backup' => $lb, 'remote' => $ok]), $this->t);
        $this->assertSame('caution', $c['tone']);
        // the connection is gone: the one fix is to connect again
        foreach (['none', 'unreadable', 'revoked'] as $state) {
            $c = StatusCard::build($this->base(['last_job' => $job, 'last_backup' => $lb, 'remote' => ['connected' => false, 'key_state' => $state]]), $this->t);
            $this->assertSame('caution', $c['tone']);
            $this->assertSame(['label' => 'Connect again', 'target' => 'dosya'], $c['fix']);
        }
        // a different backup's failed upload, older than the latest backup, stays stale
        $c = StatusCard::build($this->base(['last_job' => ['backup_id' => 'old'] + $job, 'last_backup' => $lb, 'remote' => $ok]), $this->t);
        $this->assertSame('success', $c['tone']);
    }

    private function failedRestore(int $at, ?string $snapshot): array
    {
        return ['kind' => 'restore', 'state' => 'failed', 'error' => 'The database import stopped at table wp_posts.', 'result' => null, 'created_at' => $at, 'snapshot_id' => $snapshot];
    }

    public function testFailedRestoreIsRedWithTheSafetyCopyAsTheFix(): void
    {
        $lb = $this->lb(['remote_state' => 'verified', 'created' => self::NOW - 7200]);
        $c = StatusCard::build($this->base(['last_job' => $this->failedRestore(self::NOW - 60, 'snap1'), 'last_backup' => $lb]), $this->t);
        $this->assertSame('error', $c['tone']);
        $this->assertSame('Last restore failed: The database import stopped at table wp_posts.', $c['headline']);
        $this->assertStringStartsWith('Your last good backup is from ', $c['sub']);
        $this->assertStringContainsString('and is verified in dosya.', $c['sub']);
        $this->assertStringContainsString('The safety copy made before the restore puts the site back as it was.', $c['sub']);
        $this->assertSame(['label' => 'Restore safety copy', 'target' => 'restore_safety', 'snapshot_id' => 'snap1'], $c['fix']);
        $this->assertSame('backup', $c['button']['action']);
    }

    public function testFailedRestoreWithoutSafetyCopyPointsAtTheLog(): void
    {
        $c = StatusCard::build($this->base(['last_job' => $this->failedRestore(self::NOW - 60, null)]), $this->t);
        $this->assertSame('error', $c['tone']);
        $this->assertStringContainsString('No earlier backup exists yet.', $c['sub']);
        $this->assertStringContainsString('Open the log to see what stopped it.', $c['sub']);
        $this->assertSame(['label' => 'View log', 'target' => 'log'], $c['fix']);
    }

    public function testFailedRestoreClearsOnTheNextGoodBackupOrRestore(): void
    {
        // a backup made after the failed restore
        $c = StatusCard::build($this->base(['last_job' => $this->failedRestore(self::NOW - 600, 'snap1'), 'last_backup' => $this->lb(['created' => self::NOW - 60])]), $this->t);
        $this->assertSame('success', $c['tone']);
        // a restore that succeeded after it: the newest ended job is that one
        $done = ['state' => 'done', 'error' => null, 'created_at' => self::NOW - 30] + $this->failedRestore(0, null);
        $c = StatusCard::build($this->base(['last_job' => $done, 'last_backup' => $this->lb(['created' => self::NOW - 7200])]), $this->t);
        $this->assertSame('success', $c['tone']);
    }

    public function testUploadingIsNotUploaded(): void
    {
        $c = StatusCard::build($this->base(['last_backup' => $this->lb(['remote_state' => 'uploading', 'created' => self::NOW - 7200])]), $this->t);
        $this->assertSame('Uploading to dosya, 2 hours ago', $c['headline']);
        $this->assertStringNotContainsString('stored in dosya', $c['sub']);
        $c = StatusCard::build($this->base([
            'last_job' => $this->failedJob(self::NOW - 60),
            'last_backup' => $this->lb(['remote_state' => 'uploading', 'created' => self::NOW - 7200]),
        ]), $this->t);
        $this->assertStringContainsString('and is in dosya, upload in progress.', $c['sub']);
    }

    public function testRevokedIsCautionWithDosyaFix(): void
    {
        $c = StatusCard::build($this->base(['last_backup' => $this->lb(), 'remote' => ['connected' => false, 'key_state' => 'revoked']]), $this->t);
        $this->assertSame('caution', $c['tone']);
        $this->assertStringContainsString('dosya connection lost, uploads paused', $c['sub']);
        $this->assertSame('dosya', $c['fix']['target']);
    }

    public function testNeutralSentences(): void
    {
        $c = StatusCard::build($this->base(['schedule' => ['time' => '03:00', 'keep' => 7]]), $this->t);
        $this->assertSame('Your first backup is saved on this server. Daily backups are already on (03:00, keeps 7). Connect to dosya to store copies off-site, protected from deletion.', $c['sub']);
        $c = StatusCard::build($this->base(['schedule' => ['interval' => 604800, 'time' => '03:00', 'keep' => 4], 'remote' => ['connected' => true, 'key_state' => 'ok']]), $this->t);
        $this->assertSame('Your first backup is saved on this server. Weekly backups are already on (03:00, keeps 4).', $c['sub']);
        $c = StatusCard::build($this->base(['schedule' => ['interval' => 3600, 'time' => '03:00', 'keep' => 4]]), $this->t);
        $this->assertStringContainsString('Automatic backups are already on', $c['sub']);
        $c = StatusCard::build($this->base(['schedule' => ['on' => false]]), $this->t);
        $this->assertStringContainsString('Automatic backups are off; turn them on under Schedule.', $c['sub']);
    }

    public function testOverdueBoundary(): void
    {
        $at = static function (int $age) { return ['id' => 'a', 'created' => self::NOW - $age, 'size' => 100, 'remote_state' => 'none', 'locked_until' => null]; };
        $c = StatusCard::build($this->base(['last_backup' => $at(129600)]), $this->t);
        $this->assertSame('success', $c['tone']);
        $c = StatusCard::build($this->base(['last_backup' => $at(129601)]), $this->t);
        $this->assertSame('caution', $c['tone']);
        $this->assertStringContainsString('Changes since then are not protected.', $c['sub']);
        $this->assertSame('schedule', $c['fix']['target']);
    }

    public function testSuccessSubLineOrder(): void
    {
        $now = (new \DateTimeImmutable('2026-10-12 09:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $ts = static function (string $s): int { return (new \DateTimeImmutable($s, new \DateTimeZone('UTC')))->getTimestamp(); };
        $c = StatusCard::build($this->base([
            'now' => $now,
            'last_backup' => ['id' => 'a', 'created' => $now - 7200, 'size' => 1288490188, 'remote_state' => 'verified', 'locked_until' => $ts('2026-10-13 03:00:00')],
            'remote' => ['connected' => true, 'key_state' => 'ok', 'used' => 3650722201, 'total' => 5368709120],
            'schedule' => ['next_run' => $ts('2026-10-13 03:00:00')],
        ]), $this->t);
        $this->assertSame('1.2 GB · stored in dosya, protected until tomorrow 03:00 · next backup tomorrow at 03:00 · 3.4 of 5 GB used', $c['sub']);
    }

    public function testNoLockAndPastLock(): void
    {
        $c = StatusCard::build($this->base(['last_backup' => $this->lb(['remote_state' => 'verified', 'locked_until' => self::NOW - 5])]), $this->t);
        $this->assertStringNotContainsString('protected until', $c['sub']);
        $this->assertStringContainsString('stored in dosya', $c['sub']);
    }

    public function testPastNextRunIsOmitted(): void
    {
        $c = StatusCard::build($this->base(['last_backup' => $this->lb(), 'schedule' => ['next_run' => self::NOW - 10]]), $this->t);
        $this->assertStringNotContainsString('next backup', $c['sub']);
    }

    public function testFailedWithGoodBackupSubLine(): void
    {
        $now = (new \DateTimeImmutable('2026-10-12 09:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $y = (new \DateTimeImmutable('2026-10-11 03:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $c = StatusCard::build($this->base([
            'now' => $now,
            'last_job' => $this->failedJob($now - 60),
            'last_backup' => ['id' => 'a', 'created' => $y, 'size' => 1, 'remote_state' => 'verified', 'locked_until' => null],
            'schedule' => ['next_run' => $now + 3600],
        ]), $this->t);
        $this->assertStringStartsWith('Your last good backup is from yesterday 03:00 and is verified in dosya.', $c['sub']);
        $this->assertStringContainsString('Next backup today at 10:00.', $c['sub']);
    }

    public function testStorageUnderOneGbAndNullUsed(): void
    {
        $c = StatusCard::build($this->base(['last_backup' => $this->lb(), 'remote' => ['connected' => true, 'key_state' => 'ok', 'used' => null, 'total' => 524288000]]), $this->t);
        $this->assertStringContainsString('0 B of 500 MB used', $c['sub']);
    }

    public function testLongCauseIsBounded(): void
    {
        $long = str_repeat('x', 400);
        $c = StatusCard::build($this->base(['last_job' => ['error' => $long] + $this->failedJob(self::NOW - 5)]), $this->t);
        $this->assertSame('Last backup failed: ' . str_repeat('x', 160) . '...', $c['headline']);
    }

    public function testNoEmDashInAnyState(): void
    {
        $states = [
            $this->base(), $this->base(['schedule' => ['on' => false]]),
            $this->base(['last_job' => $this->failedJob(self::NOW), 'last_backup' => $this->lb(['created' => self::NOW - 9])]),
            $this->base(['last_backup' => $this->lb(['created' => self::NOW - 400000, 'remote_state' => 'uploading'])]),
            $this->base(['last_backup' => $this->lb(['remote_state' => 'verified', 'locked_until' => self::NOW + 99999]), 'remote' => ['connected' => true, 'key_state' => 'ok', 'used' => 5, 'total' => 5]]),
            $this->base(['last_backup' => $this->lb(), 'remote' => ['key_state' => 'revoked']]),
        ];
        foreach ($states as $in) {
            $c = StatusCard::build($in, $this->t);
            $this->assertStringNotContainsString("\u{2014}", $c['headline'] . $c['sub']);
        }
    }
}
