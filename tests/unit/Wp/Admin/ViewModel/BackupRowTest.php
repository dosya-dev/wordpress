<?php
namespace Dosya\Backup\Tests\unit\Wp\Admin\ViewModel;

use Dosya\Backup\Wp\Admin\ViewModel\BackupRow;
use PHPUnit\Framework\TestCase;

final class BackupRowTest extends TestCase
{
    private const NOW = 1791800000;

    private function row(array $o = []): array
    {
        return array_replace(['id' => str_repeat('a', 32), 'created' => self::NOW - 7200, 'kind' => 'full', 'scope' => 'full', 'label' => '', 'size' => 1288490188,
            'volumes' => 3, 'bytes_new' => 1288490188, 'bytes_total' => 1288490188, 'note' => '', 'origin' => null, 'remote_state' => 'none', 'locked_until' => null,
            'failed_reason' => null, 'is_safety' => false, 'is_uploaded' => false], $o);
    }

    private function ctx(array $o = []): array { return array_replace(['now' => self::NOW, 'tz' => 'UTC', 'dependents' => 0], $o); }

    private function t(): callable { return static function (string $s): string { return $s; }; }

    public function testUploadFailedRowKeepsItsActionsAndCarriesACautionBadge(): void
    {
        $r = BackupRow::build($this->row(['upload_failed_reason' => 'dosya upload failed (503). The backup is kept locally; run it again later.']), $this->ctx(), $this->t());
        $this->assertSame(['local', 'dosya-upload-failed'], array_column($r['badges'], 'kind'));
        $this->assertSame('dosya · upload failed', $r['badges'][1]['text']);
        $this->assertSame('dosya upload failed (503). The backup is kept locally; run it again later.', $r['badges'][1]['title']);
        $this->assertSame(['restore' => true, 'download' => true, 'log' => true, 'delete' => true], $r['actions']);
        $this->assertSame('1.2 GB', $r['size_text']);
        $none = BackupRow::build($this->row(['upload_failed_reason' => null]), $this->ctx(), $this->t());
        $this->assertSame(['local'], array_column($none['badges'], 'kind'));
    }

    public function testScheduledVerifiedLockedRow(): void
    {
        $r = BackupRow::build($this->row(['remote_state' => 'verified', 'locked_until' => self::NOW + 79200, 'kind' => 'incremental', 'bytes_new' => 39845888]), $this->ctx(), $this->t());
        $this->assertSame('Scheduled', $r['type_text']);
        $this->assertSame('Database + files', $r['contents_text']);
        $this->assertSame('incremental, 38 MB new', $r['contents_hint']);
        $kinds = array_column($r['badges'], 'kind');
        $this->assertSame(['dosya-verified', 'lock'], $kinds);
        $this->assertStringContainsString('cannot delete or change it', $r['badges'][1]['title']);
        $this->assertTrue($r['actions']['restore']);
    }

    public function testLockBadgeUsesLowerCaseDayWord(): void
    {
        $r = BackupRow::build($this->row(['remote_state' => 'verified', 'locked_until' => self::NOW + 79200]), $this->ctx(), $this->t());
        $this->assertSame('🔒 until tomorrow 08:13', $r['badges'][1]['text']);
        $this->assertSame('Protected in dosya until tomorrow 08:13; this site\'s key cannot delete or change it.', $r['badges'][1]['title']);
        $far = BackupRow::build($this->row(['remote_state' => 'verified', 'locked_until' => self::NOW + 5 * 86400]), $this->ctx(), $this->t());
        $this->assertSame('🔒 until 17 Oct 10:13', $far['badges'][1]['text']);
    }

    public function testFmtPassthroughIsUsedForDates(): void
    {
        $ctx = $this->ctx(['fmt' => static function (int $ts, string $p): string { return 'D'; }]);
        $r = BackupRow::build($this->row(['created' => self::NOW - 5 * 86400]), $ctx, $this->t());
        $this->assertSame('D 10:13', $r['created_text']);
    }

    public function testCreatedTextIsCapitalised(): void
    {
        $r = BackupRow::build($this->row(), $this->ctx(), $this->t());
        $this->assertSame('Today 08:13', $r['created_text']);
        $this->assertSame('2 hours ago', $r['created_rel']);
    }

    public function testManualWithNoteAndSafetyCopy(): void
    {
        $r = BackupRow::build($this->row(['label' => 'manual', 'note' => 'before theme update']), $this->ctx(), $this->t());
        $this->assertSame('Manual', $r['type_text']);
        $this->assertSame('before theme update', $r['note']);
        $s = BackupRow::build($this->row(['label' => 'pre-restore', 'is_safety' => true, 'scope' => 'db']), $this->ctx(), $this->t());
        $this->assertSame('Safety copy', $s['type_text']);
        $this->assertSame('Database', $s['contents_text']);
        $this->assertSame('made before a restore', $s['contents_hint']);
    }

    public function testUploadedTypeEvenWithEmptyLabel(): void
    {
        $r = BackupRow::build($this->row(['is_uploaded' => true, 'label' => '']), $this->ctx(), $this->t());
        $this->assertSame('Uploaded', $r['type_text']);
        $this->assertTrue($r['actions']['restore']);
        $this->assertTrue($r['actions']['download']);
    }

    public function testTypePrecedence(): void
    {
        $r = BackupRow::build($this->row(['is_uploaded' => true, 'label' => 'manual', 'is_safety' => true]), $this->ctx(), $this->t());
        $this->assertSame('Safety copy', $r['type_text']);
        $u = BackupRow::build($this->row(['is_uploaded' => true, 'label' => 'manual']), $this->ctx(), $this->t());
        $this->assertSame('Uploaded', $u['type_text']);
    }

    public function testFilesScope(): void
    {
        $r = BackupRow::build($this->row(['scope' => 'files']), $this->ctx(), $this->t());
        $this->assertSame('Files', $r['contents_text']);
    }

    public function testFailedRow(): void
    {
        $r = BackupRow::build($this->row(['failed_reason' => 'Not enough disk space', 'size' => 0]), $this->ctx(), $this->t());
        $this->assertSame(['failed'], array_column($r['badges'], 'kind'));
        $this->assertSame('Failed · Not enough disk space', $r['badges'][0]['text']);
        $this->assertFalse($r['actions']['restore']);
        $this->assertFalse($r['actions']['download']);
        $this->assertTrue($r['actions']['log']);
        $this->assertSame('', $r['size_text']);
        $this->assertSame('Today 08:13', $r['created_text']);
        $this->assertNotSame('', $r['created_rel']);
    }

    public function testZeroStringFailedReasonCountsAsFailure(): void
    {
        $r = BackupRow::build($this->row(['failed_reason' => '0']), $this->ctx(), $this->t());
        $this->assertSame(['failed'], array_column($r['badges'], 'kind'));
        $this->assertFalse($r['actions']['restore']);
    }

    public function testEmptyFailedReasonIsNotFailure(): void
    {
        $r = BackupRow::build($this->row(['failed_reason' => '']), $this->ctx(), $this->t());
        $this->assertSame(['local'], array_column($r['badges'], 'kind'));
    }

    public function testBadgeTextsAndTitles(): void
    {
        $b = function (string $rs): array { return BackupRow::build($this->row(['remote_state' => $rs]), $this->ctx(), $this->t())['badges'][0]; };
        $this->assertSame(['kind' => 'local', 'text' => 'local', 'title' => 'Stored on this server only.'], $b('none'));
        $this->assertSame(['kind' => 'dosya-uploading', 'text' => 'dosya · uploading', 'title' => ''], $b('uploading'));
        $this->assertSame(['kind' => 'dosya-unverified', 'text' => 'dosya · not verified', 'title' => 'Uploaded; dosya has not confirmed the volumes yet.'], $b('uploaded'));
        $this->assertSame(['kind' => 'dosya-verified', 'text' => 'dosya · verified', 'title' => 'dosya confirmed every volume of this backup.'], $b('verified'));
    }

    public function testDefaultFullRowHasNoHint(): void
    {
        $r = BackupRow::build($this->row(), $this->ctx(), $this->t());
        $this->assertSame('', $r['contents_hint']);
    }

    public function testDeleteRefusedWhenBase(): void
    {
        $r = BackupRow::build($this->row(), $this->ctx(['dependents' => 2]), $this->t());
        $this->assertFalse($r['actions']['delete']);
        $this->assertSame('This backup is the base of 2 newer backups. Delete those first.', $r['delete_refusal']);
    }

    public function testDeleteRefusalSingular(): void
    {
        $r = BackupRow::build($this->row(), $this->ctx(['dependents' => 1]), $this->t());
        $this->assertSame('This backup is the base of 1 newer backup. Delete it first.', $r['delete_refusal']);
    }

    public function testNoDependentsAllowsDelete(): void
    {
        $r = BackupRow::build($this->row(), $this->ctx(), $this->t());
        $this->assertTrue($r['actions']['delete']);
        $this->assertNull($r['delete_refusal']);
    }

    public function testFutureCreatedReadsJustNow(): void
    {
        $r = BackupRow::build($this->row(['created' => self::NOW + 3600]), $this->ctx(), $this->t());
        $this->assertSame('just now', $r['created_rel']);
    }

    public function testExpiredLockShowsNoPadlock(): void
    {
        $r = BackupRow::build($this->row(['remote_state' => 'verified', 'locked_until' => self::NOW - 10]), $this->ctx(), $this->t());
        $this->assertSame(['dosya-verified'], array_column($r['badges'], 'kind'));
    }

    public function testLocalOnlyRowNeverShowsPadlock(): void
    {
        $r = BackupRow::build($this->row(['remote_state' => 'none', 'locked_until' => self::NOW + 3600]), $this->ctx(), $this->t());
        $this->assertSame(['local'], array_column($r['badges'], 'kind'));
    }

    public function testRemoteStateBadges(): void
    {
        $u = BackupRow::build($this->row(['remote_state' => 'uploading']), $this->ctx(), $this->t());
        $this->assertSame(['dosya-uploading'], array_column($u['badges'], 'kind'));
        $n = BackupRow::build($this->row(['remote_state' => 'uploaded']), $this->ctx(), $this->t());
        $this->assertSame(['dosya-unverified'], array_column($n['badges'], 'kind'));
    }

    public function testNoteIsNotEscaped(): void
    {
        $r = BackupRow::build($this->row(['note' => '<script>alert(1)</script>']), $this->ctx(), $this->t());
        $this->assertSame('<script>alert(1)</script>', $r['note']);
    }

    public function testNoEmDashesInOutputs(): void
    {
        $rows = [
            $this->row(['remote_state' => 'verified', 'locked_until' => self::NOW + 79200, 'kind' => 'incremental']),
            $this->row(['is_safety' => true]),
            $this->row(['failed_reason' => 'Not enough disk space']),
            $this->row(['remote_state' => 'uploaded']),
            $this->row(),
        ];
        foreach ($rows as $row) {
            foreach ([0, 1, 3] as $dep) {
                $json = json_encode(BackupRow::build($row, $this->ctx(['dependents' => $dep]), $this->t()), JSON_UNESCAPED_UNICODE);
                $this->assertStringNotContainsString("\u{2014}", $json);
            }
        }
    }
}
