<?php
namespace Dosya\Backup\Tests\unit\Remote;

use Dosya\Backup\Connect\DosyaClient;
use Dosya\Backup\Connect\HttpException;
use Dosya\Backup\Remote\RemoteStage;
use Dosya\Backup\Remote\RemoteStore;
use Dosya\Backup\Remote\RemoteUploader;
use Dosya\Backup\Support\Budget;
use Dosya\Backup\Support\Fs;
use PHPUnit\Framework\TestCase;

final class RemoteStageTest extends TestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';
    private string $dir;
    private FakeDosyaApi $fake;
    private RemoteStore $remoteStore;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dosya-rs-' . bin2hex(random_bytes(4));
        Fs::ensureDir($this->dir . '/' . self::ID . '/volumes');
        $this->fake = new FakeDosyaApi(65536);
        $this->remoteStore = new RemoteStore($this->dir);
    }

    protected function tearDown(): void
    {
        Fs::rrmdir($this->dir);
    }

    private function stage(): RemoteStage
    {
        $api = new DosyaClient($this->fake, 'https://api.test', 'secret-site-key');
        return new RemoteStage($api, new RemoteUploader($api, 'ws1', 100000), $this->remoteStore, 'site1');
    }

    /** Two volumes (one above the one-shot limit, one below) and both manifests. @return array<string,mixed> */
    private function cursor(): array
    {
        $b = $this->dir . '/' . self::ID;
        $vols = [];
        foreach ([1 => 200000, 2 => 5000] as $n => $size) {
            $p = sprintf('%s/volumes/v%04d.dosya', $b, $n);
            file_put_contents($p, random_bytes($size));
            $vols[] = ['v' => $n, 'size' => $size, 'sha256' => hash_file('sha256', $p), 'path' => $p];
        }
        file_put_contents($b . '/manifest.json.gz', gzencode('{"m":1}'));
        file_put_contents($b . '/manifest.public.json', '{"backup_id":"' . self::ID . '"}');
        return RemoteStage::start(self::ID, 'full', 1700000000, $vols,
            ['manifest.json.gz' => $b . '/manifest.json.gz', 'manifest.public.json' => $b . '/manifest.public.json'], []);
    }

    private function oneUnit(): Budget
    {
        $t = 0.0;
        return new Budget(0.5, 0, static function () use (&$t): float { return $t += 1.0; });
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function drive(RemoteStage $s, array $c, int $cap = 50): array
    {
        for ($i = 0; $i < $cap && empty($c['done']); $i++) {
            $c = json_decode((string) json_encode($s->step($c, $this->oneUnit()), JSON_THROW_ON_ERROR), true);
        }
        return $c;
    }

    public function testRegistersUploadsAndCompletes(): void
    {
        $c0 = $this->cursor();
        $this->assertSame('register', $c0['stage']);
        $this->assertSame(['step' => 'register', 'volume' => 1, 'volumes' => 2], RemoteStage::progress($c0));
        $c = $this->drive($this->stage(), $c0);
        $this->assertTrue($c['done']);

        $this->assertSame(1, $this->fake->registerCalls);
        $this->assertSame(1, $this->fake->completeCalls);
        $srv = $this->fake->backups[self::ID];
        $this->assertSame('uploaded', $srv['state']);
        $body = $this->fake->completeBodies[0];
        $this->assertSame([], $body['refs']);
        $this->assertSame(205000, $body['bytes_new']);
        $this->assertSame(205000, $body['bytes_total']);
        foreach ($body['volumes'] as $i => $v) {
            $this->assertSame($c0['vols'][$i]['v'], $v['volume_no']);
            $this->assertSame($c0['vols'][$i]['size'], $v['size']);
            $this->assertSame($c0['vols'][$i]['sha256'], $v['sha256']);
            $this->assertSame($c0['vols'][$i]['sha256'], $this->fake->fileSha256($v['file_id']));
            $this->assertSame(['folder' => $srv['volumes_folder_id'], 'name' => sprintf('v%04d.dosya', $i + 1)], $this->fake->fileMeta[$v['file_id']]);
        }
        $this->assertSame(['folder' => $srv['folder_id'], 'name' => 'manifest.json.gz'], $this->fake->fileMeta[$body['manifest_file_id']]);
        $this->assertSame(['folder' => $srv['folder_id'], 'name' => 'manifest.public.json'], $this->fake->fileMeta[$body['public_manifest_file_id']]);
        $this->assertSame(1800000000, $c['result']['locked_until']);

        $this->assertTrue($this->remoteStore->has(self::ID));
        $this->assertSame(gzencode('{"m":1}'), file_get_contents($this->remoteStore->manifestPath(self::ID)));
        $last = $this->remoteStore->last();
        $this->assertSame(self::ID, $last['backup_id']);
        $this->assertSame(1700000000, $last['created']);
        // complete answered uploaded: the record does not claim a verified backup
        $this->assertFalse($this->remoteStore->info(self::ID)['verified']);
        // the site key never reaches the cursor
        $this->assertStringNotContainsString('secret-site-key', json_encode($c, JSON_THROW_ON_ERROR));
        // a finished stage is a no-op
        $before = count($this->fake->requests);
        $this->assertSame($c, $this->stage()->step($c, $this->oneUnit()));
        $this->assertCount($before, $this->fake->requests);
    }

    public function testRegisterRefusedForAnInactiveSiteIsNotRetryable(): void
    {
        $this->fake->refuseRegister = 'site_not_active';
        try {
            $this->stage()->step($this->cursor(), $this->oneUnit());
            $this->fail('expected a refusal');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('site_not_active', $e->code);
            $this->assertFalse($e->retryable);
            $this->assertSame('This site is not active in dosya. Connect it again.', $e->getMessage());
        }
        $this->assertSame(['POST /api/wp/backups'], $this->fake->requestLines());
    }

    public function testRegisterIsIdempotent(): void
    {
        $c0 = $this->cursor();
        $s = $this->stage();
        $a = $s->step($c0, $this->oneUnit());
        $b = $s->step($c0, $this->oneUnit());
        $this->assertSame(2, $this->fake->registerCalls);
        $this->assertCount(1, $this->fake->backups);
        $this->assertSame($a, $b);
        $this->assertSame('volumes', $b['stage']);
        $this->assertNotNull($b['folder']);
        $this->assertNotNull($b['volumes_folder']);
        $this->assertSame(['step' => 'volumes', 'volume' => 1, 'volumes' => 2], RemoteStage::progress($b));
    }

    public function testRetryableFailureMidVolumeAbortsTheSessionAndSaysSo(): void
    {
        $s = $this->stage();
        $c = $s->step($this->cursor(), $this->oneUnit()); // register
        $c = $s->step($c, $this->oneUnit()); // opens the session
        $sid = $c['up']['session']['id'];
        $this->fake->failNext('PUT', '#/part/#', 503);
        try {
            $s->step($c, $this->oneUnit());
            $this->fail('expected a failure');
        } catch (HttpException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame('dosya upload failed (503). The backup is kept locally; run it again later.', $e->getMessage());
        }
        $this->assertContains('DELETE /api/upload/' . $sid, $this->fake->requestLines());
        $this->assertSame(0, $this->fake->completeCalls);
    }

    public function testAnInProgress409WithRetryAfterIsRetryable(): void
    {
        $s = $this->stage();
        $c = $s->step($this->cursor(), $this->oneUnit()); // register
        $c = $s->step($c, $this->oneUnit()); // opens the session
        $sid = $c['up']['session']['id'];
        $this->fake->failNext('PUT', '#/part/#', 409, false, ['retry-after' => '5']);
        try {
            $s->step($c, $this->oneUnit());
            $this->fail('expected a failure');
        } catch (HttpException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame(409, $e->status);
            $this->assertSame(5, $e->retryAfter);
            $this->assertSame('dosya upload failed (409). The backup is kept locally; run it again later.', $e->getMessage());
        }
        $this->assertContains('DELETE /api/upload/' . $sid, $this->fake->requestLines());
    }

    public function testA409WithoutRetryAfterStaysAPlainFailure(): void
    {
        $s = $this->stage();
        $c = $s->step($this->cursor(), $this->oneUnit());
        $c = $s->step($c, $this->oneUnit());
        $this->fake->failNext('PUT', '#/part/#', 409);
        try {
            $s->step($c, $this->oneUnit());
            $this->fail('expected a failure');
        } catch (HttpException $e) {
            $this->assertFalse($e->retryable);
            $this->assertSame('Upload session is already complete', $e->getMessage());
        }
    }

    public function testAbortErrorsAreSwallowed(): void
    {
        $s = $this->stage();
        $c = $s->step($this->cursor(), $this->oneUnit());
        $c = $s->step($c, $this->oneUnit());
        $this->fake->failNext('PUT', '#/part/#', 502)->failNext('DELETE', '#^/api/upload/#', 500);
        try {
            $s->step($c, $this->oneUnit());
            $this->fail('expected a failure');
        } catch (HttpException $e) {
            $this->assertSame(502, $e->status);
            $this->assertSame('dosya upload failed (502). The backup is kept locally; run it again later.', $e->getMessage());
        }
    }

    public function testCompleteFailures(): void
    {
        $s = $this->stage();
        $c = $this->cursor();
        while ($c['stage'] !== 'complete') {
            $c = $s->step($c, $this->oneUnit());
        }
        $this->assertSame(['step' => 'complete', 'volume' => 2, 'volumes' => 2], RemoteStage::progress($c));
        $this->fake->failNext('POST', '#/complete$#', 503);
        try {
            $s->step($c, $this->oneUnit());
            $this->fail('expected a failure');
        } catch (HttpException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame('dosya upload failed (503). The backup is kept locally; run it again later.', $e->getMessage());
        }
        $this->assertFalse($this->remoteStore->has(self::ID));

        $bad = $c;
        $bad['vols'][0]['size'] += 1;
        try {
            $s->step($bad, $this->oneUnit());
            $this->fail('expected a refusal');
        } catch (HttpException $e) {
            $this->assertFalse($e->retryable);
            $this->assertSame('bad_file', $e->code);
            $this->assertSame('Volume 1 is not a valid upload', $e->getMessage());
        }

        $done = $s->step($c, $this->oneUnit());
        $this->assertTrue($done['done']);
        // a repeat (a lost response) is answered with the stored values
        $again = $s->step(array_merge($c, []), $this->oneUnit());
        $this->assertSame($done['result'], $again['result']);
    }

    public function testAOneShotVolumeReplayedAfterACrashIsStoredOnce(): void
    {
        $s = $this->stage();
        $c = $s->step($this->cursor(), $this->oneUnit());
        while ($c['vi'] < 1) {
            $c = $s->step($c, $this->oneUnit());
        }
        // volume 2 (5000 bytes) is a one-shot; run its step twice from the same cursor
        $files = $this->fake->fileCount();
        $a = $s->step($c, $this->oneUnit());
        $b = $s->step($c, $this->oneUnit());
        $this->assertSame($a, $b);
        $this->assertSame($files + 1, $this->fake->fileCount());
        $this->assertSame(['PUT /api/upload/file', 'PUT /api/upload/file'], array_slice($this->fake->requestLines(), -2));
        $this->assertSame(RemoteStage::idempotencyKey(self::ID, 'v0002.dosya'), $this->fake->requests[count($this->fake->requests) - 1]['headers']['X-Dosya-Idempotency-Key']);
        // manifests too, and the whole stage still completes
        $done = $this->drive($s, $b);
        $this->assertTrue($done['done']);
        $this->assertSame(1, $this->fake->completeCalls);
    }

    public function testIdempotencyKeysAreStableUuidV4s(): void
    {
        $k = RemoteStage::idempotencyKey(self::ID, 'manifest.json.gz');
        $this->assertMatchesRegularExpression(DosyaClient::UUID_V4, $k);
        $this->assertSame($k, RemoteStage::idempotencyKey(self::ID, 'manifest.json.gz'));
        $this->assertNotSame($k, RemoteStage::idempotencyKey(self::ID, 'manifest.public.json'));
        $this->assertNotSame($k, RemoteStage::idempotencyKey(str_repeat('f', 32), 'manifest.json.gz'));
        foreach (['a', 'b', 'c', 'v0001.dosya', 'v0002.dosya', 'v9999.dosya'] as $n) {
            $this->assertMatchesRegularExpression(DosyaClient::UUID_V4, RemoteStage::idempotencyKey(self::ID, $n));
        }
    }

    public function testAVerifiedBackupCountsAsCompleted(): void
    {
        $s = $this->stage();
        $c = $this->cursor();
        while ($c['stage'] !== 'complete') {
            $c = $s->step($c, $this->oneUnit());
        }
        $s->step($c, $this->oneUnit());
        $this->assertFalse($this->remoteStore->info(self::ID)['verified']);
        $this->fake->markVerified(self::ID);
        $again = $s->step($c, $this->oneUnit());
        $this->assertTrue($again['done']);
        $this->assertSame(1800000000, $again['result']['locked_until']);
        // complete answered verified: remote.json says so, which is what "Verified restorable" reads
        $this->assertTrue($this->remoteStore->info(self::ID)['verified']);
    }

    public function testAbortOpenOnlyTouchesAnUnfinishedSession(): void
    {
        $api = new DosyaClient($this->fake, 'https://api.test', 'k');
        $this->assertNull(RemoteStage::openSession($this->cursor()));
        RemoteStage::abortOpen($api, ['up' => ['session' => ['id' => 'upl_x'], 'file_id' => 'file_1']]);
        $this->assertSame([], $this->fake->requestLines());
        $this->fake->failNext('DELETE', '#^/api/upload/#', 500);
        RemoteStage::abortOpen($api, ['up' => ['session' => ['id' => 'upl_x'], 'file_id' => null]]);
        $this->assertSame(['DELETE /api/upload/upl_x'], $this->fake->requestLines());
    }

    public function testRejectsABadBackupId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RemoteStage::start('ABC', 'full', 1, [], [], []);
    }
}
