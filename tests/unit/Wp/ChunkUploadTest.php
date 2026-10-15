<?php
namespace Dosya\Backup\Tests\unit\Wp;

use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\Manifest;
use Dosya\Backup\Jobs\LocalStore;
use Dosya\Backup\Support\Fs;
use Dosya\Backup\Tests\unit\Support\ArchiveFixture;
use Dosya\Backup\Wp\Admin\ChunkUpload;
use PHPUnit\Framework\TestCase;

final class ChunkUploadTest extends TestCase
{
    private string $tmp;
    private LocalStore $store;
    private string $src;
    private string $backupId;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/cu-' . bin2hex(random_bytes(4));
        $this->store = new LocalStore($this->tmp . '/store');
        $this->src = $this->tmp . '/src';
        [$m] = ArchiveFixture::build($this->src, ['a.txt' => ['t' => Format::TYPE_FILE, 'data' => random_bytes(5000)]]);
        foreach ($m->meta['volumes'] as $i => $v) {
            $m->meta['volumes'][$i]['sha256'] = hash_file('sha256', sprintf('%s/v%04d.dosya', $this->src, $v['v']));
        }
        $m->meta += ['scope' => 'full', 'label' => '', 'site' => ['home' => 'http://x', 'abspath' => '/x/', 'prefix' => 'wp_']];
        $m->saveGz($this->src . '/manifest.json.gz');
        $this->backupId = $m->meta['backup_id'];
    }

    protected function tearDown(): void
    {
        Fs::rrmdir($this->tmp);
    }

    private function send(ChunkUpload $up, string $uploadId, string $name, int $chunk = 700): void
    {
        $bytes = file_get_contents($this->src . '/' . $name);
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $part = $this->tmp . '/part';
            file_put_contents($part, substr($bytes, $offset, $chunk));
            $offset = $up->receive($uploadId, $name, $offset, strlen($bytes), $part)['next'];
        }
    }

    public function testUploadsResumeAndImportIntoTheStore(): void
    {
        $up = new ChunkUpload($this->store);
        $uid = bin2hex(random_bytes(16));
        $this->send($up, $uid, 'manifest.json.gz');
        $part = $this->tmp . '/p0';
        file_put_contents($part, 'xx');
        $this->assertSame(['next' => 0], $up->receive($uid, 'v0001.dosya', 50, 9999, $part));
        $this->send($up, $uid, 'v0001.dosya');
        $this->assertSame($this->backupId, $up->finish($uid));
        $this->assertSame([$this->backupId], array_column($this->store->list(), 'id'));
        $this->assertDirectoryDoesNotExist($this->store->incomingDir($uid));
    }

    public function testRejectsDamagedVolumesAndOddNames(): void
    {
        $up = new ChunkUpload($this->store);
        $uid = bin2hex(random_bytes(16));
        $this->send($up, $uid, 'manifest.json.gz');
        $vol = $this->src . '/v0001.dosya';
        $b = file_get_contents($vol);
        $b[100] = chr(ord($b[100]) ^ 1);
        file_put_contents($vol, $b);
        $this->send($up, $uid, 'v0001.dosya');
        try {
            $up->finish($uid);
            $this->fail('expected damaged volume to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('damaged', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $up->receive($uid, '../../evil.php', 0, 1, $this->tmp . '/part');
    }

    public function testFailedFinishRemovesTheIncomingDirectory(): void
    {
        $up = new ChunkUpload($this->store);
        $uid = bin2hex(random_bytes(16));
        $this->send($up, $uid, 'manifest.json.gz');
        try {
            $up->finish($uid);
            $this->fail('expected a missing volume to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('missing', $e->getMessage());
        }
        $this->assertFalse(is_dir($this->store->incomingDir($uid)));
    }

    public function testManifestWithExternalRefsIsRefused(): void
    {
        $m = Manifest::loadGz($this->src . '/manifest.json.gz');
        $m->files[0]['loc']['b'] = str_repeat('a', 32);
        $m->saveGz($this->src . '/manifest.json.gz');
        $up = new ChunkUpload($this->store);
        $uid = bin2hex(random_bytes(16));
        $this->send($up, $uid, 'manifest.json.gz');
        $this->send($up, $uid, 'v0001.dosya');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('depends on earlier backups');
        $up->finish($uid);
    }
}
