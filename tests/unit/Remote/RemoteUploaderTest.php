<?php
namespace Dosya\Backup\Tests\unit\Remote;

use Dosya\Backup\Connect\DosyaClient;
use Dosya\Backup\Connect\HttpException;
use Dosya\Backup\Remote\RemoteUploader;
use Dosya\Backup\Support\Budget;
use PHPUnit\Framework\TestCase;

final class RemoteUploaderTest extends TestCase
{
    private string $dir;
    private const MIB = 1048576;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dosya-ru-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function file(int $size): string
    {
        $p = $this->dir . '/vol-' . $size . '.bin';
        $fh = fopen($p, 'wb');
        for ($i = 0, $left = $size; $left > 0; $i++) {
            $n = min(65536, $left);
            fwrite($fh, str_repeat(chr(65 + $i % 26), $n));
            $left -= $n;
        }
        fclose($fh);
        return $p;
    }

    private function up(FakeDosyaUploads $fake): RemoteUploader
    {
        return new RemoteUploader(new DosyaClient($fake, 'https://api.test', 'key'), 'ws1');
    }

    /** A budget that allows exactly one unit of work per step. */
    private function oneUnit(): Budget
    {
        $t = 0.0;
        return new Budget(0.5, 0, static function () use (&$t): float { return $t += 1.0; });
    }

    /** The cursor after the opening step (session known, no part sent). @return array<string,mixed> */
    private function opened(RemoteUploader $u, string $p, ?string $sha = null): array
    {
        return $u->step($u->start($p, 'fld', 'big.dosya', $sha), $this->oneUnit());
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function drive(RemoteUploader $u, array $c, int $cap = 20, ?int &$steps = null): array
    {
        $steps = 0;
        while (empty($c['done']) && $steps < $cap) {
            $c = json_decode((string) json_encode($u->step($c, $this->oneUnit())), true);
            $steps++;
        }
        return $c;
    }

    public function testOneShotSmallFile(): void
    {
        $fake = new FakeDosyaUploads();
        $p = $this->file(1024);
        $u = $this->up($fake);
        $c = $u->step($u->start($p, 'fld', 'a.dosya'), Budget::unlimited());
        $this->assertTrue($c['done']);
        $this->assertSame(hash_file('sha256', $p), $c['sha256']);
        $this->assertSame(hash_file('sha256', $p), $fake->fileSha256($c['file_id']));
        $this->assertSame(['PUT /api/upload/file'], $fake->requestLines());
        $this->assertSame(hash_file('sha256', $p), $fake->requests[0]['headers']['X-Dosya-Sha256']);
    }

    public function testOneShotReplayWithTheSameKeyReturnsTheFirstFile(): void
    {
        $fake = new FakeDosyaUploads();
        $p = $this->file(2048);
        $u = $this->up($fake);
        $key = '3fa85f64-5717-4562-b3fc-2c963f66afa6';
        $c0 = $u->start($p, 'fld', 'a.dosya', null, $key);
        $a = $u->step($c0, Budget::unlimited());
        // the cursor write was lost: the same step runs again from the old cursor
        $b = $u->step(json_decode((string) json_encode($c0), true), Budget::unlimited());
        $this->assertSame($a['file_id'], $b['file_id']);
        $this->assertSame(1, $fake->fileCount());
        $this->assertSame($key, $fake->requests[0]['headers']['X-Dosya-Idempotency-Key']);
        $this->assertSame($key, $fake->requests[1]['headers']['X-Dosya-Idempotency-Key']);
        // without a key the header is absent and a repeat stores a second copy (today's server behaviour)
        $c1 = $u->start($p, 'fld', 'b.dosya');
        $u->step($c1, Budget::unlimited());
        $this->assertArrayNotHasKey('X-Dosya-Idempotency-Key', $fake->requests[2]['headers']);
        $u->step($c1, Budget::unlimited());
        $this->assertSame(3, $fake->fileCount());
    }

    public function testRejectsAKeyThatIsNotAUuidV4(): void
    {
        $u = $this->up(new FakeDosyaUploads());
        $this->expectException(\InvalidArgumentException::class);
        $u->step($u->start($this->file(10), 'fld', 'a.dosya', null, str_repeat('a', 32)), Budget::unlimited());
    }

    public function testSessionUploadInPartsAcrossBudgets(): void
    {
        $fake = new FakeDosyaUploads();
        $p = $this->file(25 * self::MIB);
        $u = $this->up($fake);
        $c = $this->drive($u, $u->start($p, 'fld', 'big.dosya'));
        $this->assertTrue($c['done']);
        $this->assertSame(hash_file('sha256', $p), $c['sha256']);
        $sid = (string) $fake->firstSessionId();
        $this->assertSame([1 => 1, 2 => 1, 3 => 1], $fake->partsReceived($sid));
        $this->assertSame(1, count(array_keys($fake->requestLines(), 'POST /api/upload/' . $sid . '/complete', true)));
        $init = json_decode($fake->requests[0]['body'], true);
        $this->assertSame(25 * self::MIB, $init['file_size']);
        $this->assertTrue($init['force_multipart']);
        $this->assertSame(hash_file('sha256', $p), $fake->fileSha256($c['file_id']));
        $i = array_search('PUT /api/upload/' . $sid . '/part/1', $fake->requestLines(), true);
        $this->assertSame(hash('sha256', (string) file_get_contents($p, false, null, 0, 10 * self::MIB)), $fake->requests[$i]['body_sha256']);
    }

    public function testCursorAdvancesAfterEveryPart(): void
    {
        $fake = new FakeDosyaUploads();
        $p = $this->file(25 * self::MIB);
        $u = $this->up($fake);
        $c = $u->step($this->opened($u, $p), $this->oneUnit());
        $this->assertSame(2, $c['next_part']);
        $this->assertFalse($c['done']);
        $c = $u->step($c, $this->oneUnit());
        $this->assertSame(3, $c['next_part']);
    }

    public function testJustOverTheBodyCapUsesATwoPartSession(): void
    {
        $fake = new FakeDosyaUploads();
        $p = $this->file(10 * self::MIB + 1);
        $u = $this->up($fake);
        $c = $this->drive($u, $u->start($p, 'fld', 'edge.dosya'));
        $this->assertTrue($c['done']);
        $this->assertSame([1 => 1, 2 => 1], $fake->partsReceived((string) $fake->firstSessionId()));
        $this->assertStringNotContainsString('PUT /api/upload/file', implode("\n", $fake->requestLines()));
        $lines = $fake->requestLines();
        $i = array_search('PUT /api/upload/' . $fake->firstSessionId() . '/part/2', $lines, true);
        $this->assertSame(1, strlen($fake->requests[$i]['body']));
    }

    public function testResumeTrustsServerUploadedParts(): void
    {
        $fake = new FakeDosyaUploads();
        $p = $this->file(25 * self::MIB);
        $u = $this->up($fake);
        $c = $u->step($this->opened($u, $p), $this->oneUnit());
        $this->assertSame(1, array_sum($fake->partsReceived((string) $fake->firstSessionId())));
        $c['next_part'] = 1; // crash after the 200, before the cursor was saved
        $c = $this->drive($u, $c);
        $this->assertTrue($c['done']);
        $this->assertSame([1 => 1, 2 => 1, 3 => 1], $fake->partsReceived((string) $fake->firstSessionId()));
    }

    public function testResumeReuploadsAPartTheServerLost(): void
    {
        $fake = new FakeDosyaUploads();
        $p = $this->file(25 * self::MIB);
        $u = $this->up($fake);
        $c = $u->step($this->opened($u, $p), $this->oneUnit());
        $c = $u->step($c, $this->oneUnit()); // parts 1, 2 stored
        $sid = (string) $fake->firstSessionId();
        $fake->forgetPart($sid, 2);
        $c['next_part'] = 3;
        $c = $this->drive($u, $c);
        $this->assertTrue($c['done']);
        $this->assertSame([1 => 1, 2 => 2, 3 => 1], $fake->partsReceived($sid));
        $this->assertSame(hash_file('sha256', $p), $fake->fileSha256($c['file_id']));
    }

    public function testCompleteConflictMeansAlreadyDone(): void
    {
        $fake = new FakeDosyaUploads();
        $fake->failNext('POST', '#/complete$#', 409, true);
        $p = $this->file(25 * self::MIB);
        $u = $this->up($fake);
        $c = $this->drive($u, $u->start($p, 'fld', 'big.dosya'));
        $this->assertTrue($c['done']);
        $this->assertSame(hash_file('sha256', $p), $c['sha256']);
        $this->assertTrue($fake->hasFile($c['file_id']));
    }

    public function testRetryableErrorsDoNotAdvanceTheCursor(): void
    {
        $fake = new FakeDosyaUploads();
        $p = $this->file(25 * self::MIB);
        $u = $this->up($fake);
        $c = $u->step($this->opened($u, $p), $this->oneUnit());
        $this->assertSame(2, $c['next_part']);
        $fake->failNext('PUT', '#/part/2$#', 503);
        try {
            $u->step($c, $this->oneUnit());
            $this->fail('expected HttpException');
        } catch (HttpException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame(503, $e->status);
        }
        $this->assertSame(2, $c['next_part']);
        $c = $this->drive($u, $c);
        $this->assertTrue($c['done']);
        $this->assertSame([1 => 1, 2 => 1, 3 => 1], $fake->partsReceived((string) $fake->firstSessionId()));
    }

    public function testSizeMismatchAfterCompleteIsNotRetryable(): void
    {
        $fake = new FakeDosyaUploads();
        $p = $this->file(25 * self::MIB);
        $u = $this->up($fake);
        $c = $u->start($p, 'fld', 'big.dosya');
        $c['size'] = 25 * self::MIB + 5; // the cursor disagrees with what the server will store
        $c['mode'] = 'session';
        try {
            $this->drive($u, $c);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(HttpException::class, $e);
            $this->assertStringContainsString('corrupt', $e->getMessage());
        }
    }

    public function testFailedFirstPartDoesNotLeakASecondSession(): void
    {
        $fake = new FakeDosyaUploads();
        $p = $this->file(25 * self::MIB);
        $u = $this->up($fake);
        $c = $u->step($u->start($p, 'fld', 'big.dosya'), $this->oneUnit());
        $this->assertNotNull($c['session']);
        $this->assertSame(1, $c['next_part']);
        $fake->failNext('PUT', '#/part/1$#', 503);
        try {
            $u->step($c, $this->oneUnit());
            $this->fail('expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->status);
        }
        $c = $this->drive($u, $c);
        $this->assertTrue($c['done']);
        $this->assertSame(1, count(array_keys($fake->requestLines(), 'POST /api/upload/init', true)));
        $this->assertSame([1 => 1, 2 => 1, 3 => 1], $fake->partsReceived((string) $fake->firstSessionId()));
    }

    public function testExhaustedBudgetAfterCompleteLeavesTheHashForTheNextStep(): void
    {
        $fake = new FakeDosyaUploads();
        $p = $this->file(15 * self::MIB);
        $u = $this->up($fake);
        $c = $this->opened($u, $p);
        $c = $u->step($c, new Budget(100.0, 0)); // parts 1, 2 and complete, then hashes: not exhausted
        $this->assertTrue($c['done']);

        $fake = new FakeDosyaUploads();
        $u = $this->up($fake);
        $c = $this->opened($u, $p);
        $c = $u->step($c, $this->oneUnit()); // part 1
        $c = $u->step($c, $this->oneUnit()); // part 2
        $c = $u->step($c, $this->oneUnit()); // complete, budget exhausted
        $this->assertNotNull($c['file_id']);
        $this->assertNull($c['sha256']);
        $this->assertFalse($c['done']);
        $c = $u->step($c, $this->oneUnit());
        $this->assertTrue($c['done']);
        $this->assertSame(hash_file('sha256', $p), $c['sha256']);
    }

    public function testSuppliedDigestIsUsedWithoutRehashing(): void
    {
        $fake = new FakeDosyaUploads();
        $p = $this->file(25 * self::MIB);
        $u = $this->up($fake);
        $known = hash_file('sha256', $p);
        $c = $this->opened($u, $p, $known);
        $c = $u->step($c, new Budget(100.0, 0));
        $this->assertTrue($c['done']);
        $this->assertSame($known, $c['sha256']);

        // Proof it is not recomputed: a digest the file does not match is passed through untouched.
        $u2 = $this->up(new FakeDosyaUploads());
        $c = $u2->step($this->opened($u2, $p, str_repeat('ab', 32)), new Budget(100.0, 0));
        $this->assertSame(str_repeat('ab', 32), $c['sha256']);
    }

    public function testSizeMismatchDiscoveredViaStatusIsNotRetryable(): void
    {
        $fake = new FakeDosyaUploads();
        $p = $this->file(25 * self::MIB);
        $u = $this->up($fake);
        $c = $this->opened($u, $p);
        $c = $u->step($c, new Budget(100.0, 0)); // everything uploaded and complete
        $this->assertTrue($c['done']);
        $c['file_id'] = null;
        $c['sha256'] = null;
        $c['done'] = false;
        $c['size'] = 25 * self::MIB + 9;
        try {
            $u->step($c, $this->oneUnit());
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(HttpException::class, $e);
            $this->assertStringContainsString('corrupt', $e->getMessage());
        }
    }

    public function testOnlyTheMissingPartIsSentWhenTheServerHoldsLaterOnes(): void
    {
        $fake = new FakeDosyaUploads();
        $p = $this->file(25 * self::MIB);
        $u = $this->up($fake);
        $c = $this->opened($u, $p);
        $c = $u->step($c, $this->oneUnit());
        $c = $u->step($c, $this->oneUnit());
        $c = $u->step($c, $this->oneUnit()); // parts 1, 2, 3 stored
        $sid = (string) $fake->firstSessionId();
        $fake->forgetPart($sid, 2);
        $c['next_part'] = 1;
        $c = $u->step($c, new Budget(100.0, 0));
        $this->assertSame([1 => 1, 2 => 2, 3 => 1], $fake->partsReceived($sid));
        $this->assertSame(2, count(array_keys($fake->requestLines(), 'PUT /api/upload/' . $sid . '/part/2', true)));
        $this->assertSame(1, count(array_keys($fake->requestLines(), 'PUT /api/upload/' . $sid . '/part/3', true)));
        $this->assertTrue($c['done']);
    }

    public function testAPartSentTwiceStillCompletesWithTheRightSize(): void
    {
        $fake = new FakeDosyaUploads();
        $p = $this->file(25 * self::MIB);
        $u = $this->up($fake);
        $c = $this->opened($u, $p);
        $c = $u->step($c, $this->oneUnit());
        $c = $u->step($c, $this->oneUnit()); // parts 1, 2 stored
        $sid = (string) $fake->firstSessionId();
        $fake->forgetPart($sid, 2);          // status omits 2, so the client sends it again
        $c['next_part'] = 2;
        $c = $this->drive($u, $c);
        $this->assertTrue($c['done']);       // assertSize passed: size_bytes == local size
        $this->assertSame([1 => 1, 2 => 2, 3 => 1], $fake->partsReceived($sid));
        $this->assertSame(25 * self::MIB, $fake->fileSize($c['file_id']));
    }

    public function testConflictPathWithAWrongStoredSizeIsNotRetryable(): void
    {
        $fake = new FakeDosyaUploads();
        $fake->failNext('POST', '#/complete$#', 409, true);
        $p = $this->file(25 * self::MIB);
        $u = $this->up($fake);
        $c = $this->opened($u, $p);
        $fake->reportBytesUploaded((string) $fake->firstSessionId(), 25 * self::MIB + 10 * self::MIB);
        try {
            $this->drive($u, $c);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(HttpException::class, $e);
            $this->assertStringContainsString('corrupt', $e->getMessage());
        }
    }
}
