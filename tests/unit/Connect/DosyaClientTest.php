<?php
namespace Dosya\Backup\Tests\unit\Connect;

use Dosya\Backup\Connect\Connection;
use Dosya\Backup\Connect\DosyaClient;
use Dosya\Backup\Connect\HttpException;
use PHPUnit\Framework\TestCase;

final class DosyaClientTest extends TestCase
{
    private function client(FakeHttpClient $f, ?string $key = 'dos_secretkey'): DosyaClient
    {
        return new DosyaClient($f, 'https://api.dosya.dev/', $key, 'dosya-backup/9.9 (WordPress)');
    }

    public function testRegisterBackupSendsBearerAndJson(): void
    {
        $f = (new FakeHttpClient())->on('POST', '/api/wp/backups', 201, ['ok' => true, 'folder_id' => 'f1']);
        $out = $this->client($f)->registerBackup(str_repeat('a', 32), 'full', 1700000000);
        $this->assertSame('f1', $out['folder_id']);
        $r = $f->requests[0];
        $this->assertSame('https://api.dosya.dev/api/wp/backups', $r['url']);
        $this->assertSame('Bearer dos_secretkey', $r['headers']['Authorization']);
        $this->assertSame('dosya-backup/9.9 (WordPress)', $r['headers']['User-Agent']);
        $this->assertSame('application/json', $r['headers']['Content-Type']);
        $this->assertSame(['backup_id' => str_repeat('a', 32), 'kind' => 'full', 'started_at' => 1700000000], json_decode($r['body'], true));
    }

    public function testErrorPrefersReasonThenError(): void
    {
        $f = (new FakeHttpClient())->on('POST', '/api/wp/backups', 409, ['error' => 'site_not_active']);
        try {
            $this->client($f)->registerBackup(str_repeat('a', 32), 'full', 1);
            $this->fail('expected');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('site_not_active', $e->code);
            $this->assertFalse($e->retryable);
            $this->assertStringNotContainsString('dos_secretkey', $e->getMessage());
        }
        $f = (new FakeHttpClient())->on('POST', '/api/wp/connect/exchange', 410, ['error' => 'gone', 'reason' => 'code_used']);
        try {
            $this->client($f, null)->exchange('c', 'v');
            $this->fail('expected');
        } catch (HttpException $e) {
            $this->assertSame('code_used', $e->code);
        }
    }

    public function testRetryableAndRetryAfter(): void
    {
        $f = (new FakeHttpClient())
            ->on('GET', '/api/wp/sites/s1/backups', 503, ['error' => 'busy'], null, ['retry-after' => '12'])
            ->on('POST', '/api/wp/sites/s1/disconnect', 429, '')
            ->on('POST', '/api/wp/sites/s1/heartbeat', 423, ['error' => 'backup_locked']);
        $c = $this->client($f);
        try {
            $c->listBackups('s1');
            $this->fail('expected');
        } catch (HttpException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame(12, $e->retryAfter);
        }
        try {
            $c->disconnect('s1');
            $this->fail('expected');
        } catch (HttpException $e) {
            $this->assertTrue($e->retryable);
            $this->assertNull($e->retryAfter);
            $this->assertSame('http_429', $e->code);
        }
        try {
            $c->heartbeat('s1', []);
            $this->fail('expected');
        } catch (HttpException $e) {
            $this->assertFalse($e->retryable);
            $this->assertSame(423, $e->status);
        }
    }

    public function testExchangeSendsNoAuthorization(): void
    {
        $f = (new FakeHttpClient())->on('POST', '/api/wp/connect/exchange', 201, ['ok' => true, 'site_key' => 'dos_k', 'site_id' => 's', 'workspace_id' => 'w', 'root_folder_id' => 'r', 'signing_keys' => [], 'caps' => ['x' => 1], 'retention_days' => 30, 'immutability_hours' => 24]);
        $out = $this->client($f, 'dos_ignored')->exchange('CODE', 'VERIFIER');
        $this->assertArrayNotHasKey('Authorization', $f->requests[0]['headers']);
        $this->assertSame(['code' => 'CODE', 'code_verifier' => 'VERIFIER'], json_decode($f->requests[0]['body'], true));
        $c = Connection::fromExchange($out, 'https://api.dosya.dev');
        $this->assertSame('dos_k', $c->siteKey);
        $this->assertSame(30, $c->retentionDays);
        $this->assertEquals($c, Connection::fromArray($c->toArray()));
        $this->assertStringNotContainsString("dos_k", json_encode($c->__debugInfo()));
    }

    public function testUploadPartSendsExactlyTheSlice(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dosya');
        file_put_contents($path, 'ABCDEFGHIJ' . 'klmno' . 'PQRSTUVWXY');
        $this->assertSame(25, filesize($path));
        $f = (new FakeHttpClient())->on('PUT', '/api/upload/upl_1/part/3', 200, ['ok' => true, 'etag' => 'e']);
        $out = $this->client($f)->uploadPart('upl_1', 3, $path, 10, 5);
        unlink($path);
        $this->assertTrue($out['ok']);
        $r = $f->requests[0];
        $this->assertSame('klmno', $r['body']);
        $this->assertSame('application/octet-stream', $r['headers']['Content-Type']);
        $this->assertSame(['path' => $path, 'offset' => 10, 'length' => 5], $r['bodyFile']);
    }

    public function testOneShotSendsSha256AndQuery(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dosya');
        file_put_contents($path, 'hello');
        $f = (new FakeHttpClient())->on('PUT', '/api/upload/file', 201, ['ok' => true]);
        $this->client($f)->uploadFileOneShot('w1', 'f1', 'a b.txt', $path, strtoupper(hash('sha256', 'hello')));
        unlink($path);
        $r = $f->requests[0];
        $this->assertSame(hash('sha256', 'hello'), $r['headers']['X-Dosya-Sha256']);
        $this->assertSame('hello', $r['body']);
        parse_str((string) parse_url($r['url'], PHP_URL_QUERY), $q);
        $this->assertSame(['workspace_id' => 'w1', 'file_name' => 'a b.txt', 'folder_id' => 'f1'], $q);
    }

    public function testSessionLifecycleAndAbortIdempotent(): void
    {
        $f = (new FakeHttpClient())
            ->on('POST', '/api/upload/init', 201, ['session_id' => 'upl_1'])
            ->on('GET', '/api/upload/upl_1/status', 200, ['uploaded_parts' => [1]])
            ->on('POST', '/api/upload/upl_1/complete', 201, ['ok' => true, 'file' => ['id' => 'x']])
            ->on('DELETE', '/api/upload/upl_1', 404, ['error' => 'not_found']);
        $c = $this->client($f);
        $this->assertSame('upl_1', $c->uploadInit('w', 'f', 'n.dosya', 123)['session_id']);
        $this->assertSame(['workspace_id' => 'w', 'folder_id' => 'f', 'file_name' => 'n.dosya', 'file_size' => 123, 'force_multipart' => true], json_decode($f->requests[0]['body'], true));
        $this->assertSame([1], $c->uploadStatus('upl_1')['uploaded_parts']);
        $this->assertSame('x', $c->uploadComplete('upl_1')['file']['id']);
        $c->uploadAbort('upl_1');
        $this->assertTrue(true);
    }

    public function testAuthedCallWithoutKeyThrowsBeforeSending(): void
    {
        $f = new FakeHttpClient();
        $c = $this->client($f, null);
        try {
            $c->listBackups('s1');
            $this->fail('expected');
        } catch (\LogicException $e) {
            $this->assertCount(0, $f->requests);
        }
        $this->assertStringNotContainsString('dos_secretkey', print_r($this->client($f), true));
        $this->assertStringContainsString('set', json_encode($this->client($f)->__debugInfo()));
    }

    public function testSentenceErrorKeepsHttpCodeAndUsesSentenceAsMessage(): void
    {
        $f = (new FakeHttpClient())->on('POST', '/api/wp/backups', 400, ['error' => 'backup_id must be 32 lowercase hex characters']);
        try {
            $this->client($f)->registerBackup('x', 'full', 1);
            $this->fail('expected');
        } catch (HttpException $e) {
            $this->assertSame('http_400', $e->code);
            $this->assertSame('backup_id must be 32 lowercase hex characters', $e->getMessage());
        }
    }

    public function testOneShotEmptyFileSetsContentLengthZero(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dosya');
        $f = (new FakeHttpClient())->on('PUT', '/api/upload/file', 201, ['ok' => true]);
        $this->client($f)->uploadFileOneShot('w', 'f', 'e.txt', $path, hash('sha256', ''));
        unlink($path);
        $this->assertSame('0', $f->requests[0]['headers']['Content-Length']);
    }

    public function testNonJsonSuccessBodyAndEmptyBody(): void
    {
        $f = (new FakeHttpClient())
            ->on('GET', '/api/wp/sites/s1/backups', 200, '<html>')
            ->on('POST', '/api/wp/sites/s1/disconnect', 200, '');
        try {
            $this->client($f)->listBackups('s1');
            $this->fail('expected');
        } catch (HttpException $e) {
            $this->assertSame('bad_response', $e->code);
        }
        $this->assertSame([], $this->client($f)->disconnect('s1'));
    }

    public function testASentenceNextToAReasonIsTheMessage(): void
    {
        $f = (new FakeHttpClient())->on('POST', '/api/wp/backups', 409, ['error' => 'This site is disconnected', 'reason' => 'site_not_active']);
        try {
            $this->client($f)->registerBackup(str_repeat('a', 32), 'full', 1);
            $this->fail('expected');
        } catch (HttpException $e) {
            $this->assertSame('site_not_active', $e->code);
            $this->assertSame('This site is disconnected', $e->getMessage());
        }
    }
}
