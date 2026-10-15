<?php
namespace Dosya\Backup\Tests\unit\Wp\Admin;

use Dosya\Backup\Wp\Admin\ListPayload;
use PHPUnit\Framework\TestCase;

final class ListPayloadTest extends TestCase
{
    private const NOW = 1791800000;

    private function id(int $i): string { return str_pad(dechex($i), 32, '0', STR_PAD_LEFT); }

    private function rows(int $n): array
    {
        $rows = [];
        for ($i = $n; $i >= 1; $i--) {
            $rows[] = ['id' => $this->id($i), 'created' => self::NOW - 10000 + $i * 100, 'kind' => 'full', 'scope' => 'full', 'label' => '',
                'size' => 10, 'volumes' => 1, 'bytes_new' => 10, 'bytes_total' => 10, 'note' => '', 'origin' => null];
        }
        return $rows;
    }

    private function job(string $id, int $created, array $cursor = [], string $error = 'Out of disk space'): array
    {
        return ['id' => $id, 'kind' => 'backup', 'state' => 'failed', 'cursor' => $cursor, 'error' => $error, 'result' => null, 'created_at' => $created, 'updated_at' => $created];
    }

    private function ctx(): array { return ['now' => self::NOW, 'tz' => 'UTC']; }
    private function t(): callable { return static function (string $s): string { return $s; }; }

    private function build(int $page, array $rows = null, array $remote = [], array $deps = [], array $ctx = []): array
    {
        $failed = [$this->job('jobwithid7', self::NOW - 50, ['id' => $this->id(7)]), $this->job('orphanjob1', self::NOW - 1, ['kind' => 'incremental', 'note' => 'x']),
            $this->job('nocursorid', self::NOW - 2)];
        return ListPayload::build($rows ?? $this->rows(45), $failed, $remote, $deps, $page, 20, $ctx + $this->ctx(), $this->t());
    }

    public function testPagingTotalAndOrder(): void
    {
        $p = $this->build(2);
        $this->assertSame(47, $p['total']);
        $this->assertSame(2, $p['page']);
        $this->assertSame(20, $p['per_page']);
        $this->assertCount(20, $p['backups']);
        $this->assertCount(20, $p['rows_vm']);
        $created = array_column($p['backups'], 'created');
        $sorted = $created;
        rsort($sorted);
        $this->assertSame($sorted, $created);
        $this->assertSame($p['backups'][0]['id'], $p['rows_vm'][0]['id']);
        $this->assertCount(7, $this->build(3)['backups']);
    }

    public function testOrphanFailedJobAppearsAndStoredIdDoesNotDuplicate(): void
    {
        $p = $this->build(1);
        $this->assertSame('orphanjob1', $p['backups'][0]['id']);
        $this->assertSame('Out of disk space', $p['backups'][0]['failed_reason']);
        $this->assertSame('incremental', $p['backups'][0]['kind']);
        $this->assertSame('x', $p['backups'][0]['note']);
        $this->assertSame(self::NOW - 1, $p['backups'][0]['created']);
        $ids = [];
        foreach ([1, 2, 3] as $pg) { $ids = array_merge($ids, array_column($this->build($pg)['backups'], 'id')); }
        $this->assertCount(47, array_unique($ids));
        $this->assertContains('nocursorid', $ids);
        $this->assertNotContains('jobwithid7', $ids);
        $this->assertFalse($p['backups'][0]['remote']);
        $this->assertSame(1, count(array_keys($ids, $this->id(7), true)));
    }

    public function testUploadFailureMarksTheStoredRowInsteadOfVanishing(): void
    {
        $rows = $this->rows(3);
        $mid = $rows[1]['id'];
        $jobs = [$this->job('uploadjob1', self::NOW - 5, ['id' => $mid], 'dosya upload failed (503). The backup is kept locally; run it again later.'),
            $this->job('olderjob01', self::NOW - 9, ['id' => $mid], 'an older failure')];
        $p = ListPayload::build($rows, $jobs, [], [], 1, 20, $this->ctx(), $this->t());
        $this->assertSame(3, $p['total']);
        $ids = array_column($p['backups'], 'id');
        $this->assertSame(1, count(array_keys($ids, $mid, true)));
        $this->assertNotContains('uploadjob1', $ids);
        $by = array_combine($ids, $p['backups']);
        $this->assertSame('dosya upload failed (503). The backup is kept locally; run it again later.', $by[$mid]['upload_failed_reason']);
        $this->assertNull($by[$mid]['failed_reason']);
        $this->assertNull($by[$rows[0]['id']]['upload_failed_reason']);
        $vm = array_combine(array_column($p['rows_vm'], 'id'), $p['rows_vm']);
        $this->assertContains('dosya-upload-failed', array_column($vm[$mid]['badges'], 'kind'));
        $this->assertTrue($vm[$mid]['actions']['restore']);
        // uploaded later after all: the failure no longer applies
        $p = ListPayload::build($rows, $jobs, [$mid => ['backup_id' => $mid]], [], 1, 20, $this->ctx(), $this->t());
        $by = array_combine(array_column($p['backups'], 'id'), $p['backups']);
        $this->assertNull($by[$mid]['upload_failed_reason']);
    }

    public function testNoteWithALessThanSignPassesThroughUnchanged(): void
    {
        $rows = $this->rows(1);
        $rows[0]['note'] = 'size < 5MB';
        $p = ListPayload::build($rows, [], [], [], 1, 20, $this->ctx(), $this->t());
        $this->assertSame('size < 5MB', $p['backups'][0]['note']);
        $this->assertSame('size < 5MB', $p['rows_vm'][0]['note']);
    }

    public function testNewestBackupSkipsUploadedAndSafetyCopies(): void
    {
        $rows = $this->rows(3); // created ascending with the number: rows[0] is the newest
        $this->assertSame($rows[0]['id'], ListPayload::newestBackup($rows, [], null)['id']);

        $rows[0]['uploaded'] = true;
        $rows[1]['label'] = 'pre-restore';
        $lb = ListPayload::newestBackup($rows, [$rows[2]['id'] => ['verified' => true, 'locked_until' => self::NOW + 60]], null);
        $this->assertSame(['id' => $rows[2]['id'], 'created' => $rows[2]['created'], 'size' => 10, 'remote_state' => 'verified', 'locked_until' => self::NOW + 60], $lb);
        $this->assertSame('uploading', ListPayload::newestBackup($rows, [], $rows[2]['id'])['remote_state']);
        $this->assertSame('uploaded', ListPayload::newestBackup($rows, [$rows[2]['id'] => ['backup_id' => 'x']], '')['remote_state']);
        $this->assertSame('none', ListPayload::newestBackup($rows, [], null)['remote_state']);

        // only an uploaded backup: the card says "No backup yet"
        $only = $this->rows(1);
        $only[0]['uploaded'] = true;
        $this->assertNull(ListPayload::newestBackup($only, [], null));
        $this->assertNull(ListPayload::newestBackup([], [], null));
    }

    private function j(string $id, string $kind, string $state, int $at): array
    {
        return ['id' => $id, 'kind' => $kind, 'state' => $state, 'cursor' => [], 'error' => $state === 'failed' ? 'boom' : null, 'result' => null, 'created_at' => $at, 'updated_at' => $at];
    }

    /** @return string|null the id selectLastJob picks */
    private function pick(array $jobs): ?string
    {
        $j = ListPayload::selectLastJob($jobs);
        return $j === null ? null : $j['id'];
    }

    public function testSelectLastJobKeepsAFailedBackupUntilABackupSucceeds(): void
    {
        // a restore that succeeded later does not clear the failed backup
        $this->assertSame('b1', $this->pick([$this->j('r2', 'restore', 'done', 200), $this->j('b1', 'backup', 'failed', 100)]));
        // a backup that succeeded later does
        $this->assertSame('b2', $this->pick([$this->j('b1', 'backup', 'failed', 100), $this->j('b2', 'backup', 'done', 200)]));
        // cancelled and skipped runs are ignored
        $this->assertSame('b1', $this->pick([$this->j('b1', 'backup', 'failed', 100), $this->j('b2', 'backup', 'cancelled', 200), $this->j('b3', 'backup', 'skipped', 300)]));
        $this->assertSame('b1', $this->pick([$this->j('b1', 'backup', 'failed', 100), $this->j('r2', 'restore', 'cancelled', 200)]));
        // a running job is not an ended one
        $this->assertSame('b1', $this->pick([$this->j('b1', 'backup', 'failed', 100), $this->j('b2', 'backup', 'running', 200)]));
    }

    public function testSelectLastJobClearsAFailedRestoreOnTheNextSuccess(): void
    {
        $this->assertSame('b2', $this->pick([$this->j('r1', 'restore', 'failed', 100), $this->j('b2', 'backup', 'done', 200)]));
        $this->assertSame('r2', $this->pick([$this->j('r1', 'restore', 'failed', 100), $this->j('r2', 'restore', 'done', 200)]));
        // newer than every backup job and nothing succeeded since: shown
        $this->assertSame('r2', $this->pick([$this->j('b1', 'backup', 'done', 100), $this->j('r2', 'restore', 'failed', 200)]));
        // both failed: the newer failure
        $this->assertSame('r2', $this->pick([$this->j('b1', 'backup', 'failed', 100), $this->j('r2', 'restore', 'failed', 200)]));
        $this->assertSame('b2', $this->pick([$this->j('r1', 'restore', 'failed', 100), $this->j('b2', 'backup', 'failed', 200)]));
    }

    public function testSelectLastJobOtherwiseNewestDoneOrNull(): void
    {
        $this->assertSame('r2', $this->pick([$this->j('b1', 'backup', 'done', 100), $this->j('r2', 'restore', 'done', 200)]));
        $this->assertNull($this->pick([]));
        $this->assertNull($this->pick([$this->j('b1', 'backup', 'cancelled', 100), $this->j('b2', 'backup', 'skipped', 200)]));
    }

    public function testDependentsRemoteStateAndNotePassThrough(): void
    {
        $rows = $this->rows(3);
        $rows[0]['note'] = '<script>alert(1)</script>';
        $top = $rows[0]['id'];
        $remote = [$rows[1]['id'] => ['backup_id' => $rows[1]['id'], 'locked_until' => self::NOW + 100], $rows[2]['id'] => ['verified' => true, 'locked_until' => null]];
        $p = ListPayload::build($rows, [], $remote, [$rows[2]['id'] => 2], 1, 20, $this->ctx() + ['uploading_id' => $top], $this->t());
        $by = [];
        foreach ($p['backups'] as $b) { $by[$b['id']] = $b; }
        $this->assertSame('<script>alert(1)</script>', $by[$top]['note']);
        $this->assertSame('uploading', $by[$top]['remote_state']);
        $this->assertTrue($by[$top]['remote']);
        $this->assertTrue($by[$rows[1]['id']]['remote']);
        $this->assertTrue($by[$rows[2]['id']]['remote']);
        $this->assertSame('uploaded', $by[$rows[1]['id']]['remote_state']);
        $this->assertSame(self::NOW + 100, $by[$rows[1]['id']]['locked_until']);
        $this->assertSame('verified', $by[$rows[2]['id']]['remote_state']);
        $this->assertSame(2, $by[$rows[2]['id']]['dependents']);
        $this->assertSame(0, $by[$top]['dependents']);
        $this->assertFalse($by[$top]['is_safety']);
        $this->assertNull($by[$top]['failed_reason']);
    }

    public function testSafetyAndUploadedFlags(): void
    {
        $rows = $this->rows(2);
        $rows[0]['label'] = 'pre-restore';
        $rows[1]['uploaded'] = true;
        $p = ListPayload::build($rows, [], [], [], 1, 20, $this->ctx(), $this->t());
        $this->assertTrue($p['backups'][0]['is_safety']);
        $this->assertTrue($p['backups'][1]['is_uploaded']);
    }

    public function testPageBelowOneAndPastTheEnd(): void
    {
        $this->assertSame(1, $this->build(0)['page']);
        $this->assertSame(1, $this->build(-3)['page']);
        $p = $this->build(9);
        $this->assertSame([], $p['backups']);
        $this->assertSame([], $p['rows_vm']);
        $this->assertSame(47, $p['total']);
    }

    public function testStoredRowWithoutNoteOrOriginKeys(): void
    {
        $row = ['id' => $this->id(1), 'created' => self::NOW - 5, 'kind' => 'full', 'scope' => 'full', 'label' => '', 'size' => 1, 'volumes' => 1, 'bytes_new' => 1, 'bytes_total' => 1];
        $p = ListPayload::build([$row], [], [], [], 1, 20, $this->ctx(), $this->t());
        $this->assertSame('', $p['backups'][0]['note']);
        $this->assertNull($p['backups'][0]['origin']);
        $this->assertFalse($p['backups'][0]['remote']);
    }

    public function testEmpty(): void
    {
        $p = ListPayload::build([], [], [], [], 1, 20, $this->ctx(), $this->t());
        $this->assertSame(['backups' => [], 'rows_vm' => [], 'page' => 1, 'per_page' => 20, 'total' => 0], $p);
    }
}
