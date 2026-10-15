<?php
namespace Dosya\Backup\Tests\unit\Jobs;

use Dosya\Backup\Jobs\LocalStore;
use Dosya\Backup\Support\Fs;
use PHPUnit\Framework\TestCase;

final class LocalStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ls-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        Fs::rrmdir($this->dir);
    }

    public function testPrefersAFolderOutsideTheWebRoot(): void
    {
        $yes = function (): bool { return true; };
        $no = function (): bool { return false; };
        $this->assertSame('/var/www/dosya-backups/abc', LocalStore::chooseDir('/var/www/html/', '/var/www/html/wp-content', '/var/www/html', 'abc', $yes));
        $this->assertSame('/var/www/html/wp-content/dosya-backup-local/abc', LocalStore::chooseDir('/var/www/html/', '/var/www/html/wp-content', '/var/www/html', 'abc', $no));
        $this->assertSame('/home/u/public_html/wp/wp-content/dosya-backup-local/abc', LocalStore::chooseDir('/home/u/public_html/wp/', '/home/u/public_html/wp/wp-content', '/home/u/public_html', 'abc', $yes));
        $this->assertSame('/x/wp-content/dosya-backup-local/abc', LocalStore::chooseDir('/x/', '/x/wp-content', '', 'abc', $yes));
    }

    public function testEnsureWritesDenyFiles(): void
    {
        $s = new LocalStore($this->dir);
        $s->ensure();
        foreach (['index.php', '.htaccess', 'web.config'] as $f) {
            $this->assertFileExists($this->dir . '/' . $f);
        }
    }

    public function testListAndPruneKeepNewestAndDropStaleIncomplete(): void
    {
        $s = new LocalStore($this->dir);
        $s->ensure();
        $ids = [];
        foreach ([[100, ''], [200, ''], [300, 'pre-restore'], [400, ''], [500, 'pre-restore'], [600, 'pre-restore']] as [$created, $label]) {
            $id = bin2hex(random_bytes(16));
            mkdir($s->backupDir($id) . '/volumes', 0777, true);
            file_put_contents($s->publicManifestPath($id), json_encode(['format' => 1, 'backup_id' => $id, 'created' => $created,
                'kind' => 'full', 'scope' => 'full', 'label' => $label, 'volumes' => [['v' => 1, 'size' => 10, 'sha256' => 'x']], 'refs' => []]));
            $ids[$created] = $id;
        }
        $stale = bin2hex(random_bytes(16));
        mkdir($s->backupDir($stale), 0777, true);
        touch($s->backupDir($stale), 1000);

        $this->assertSame([600, 500, 400, 300, 200, 100], array_column($s->list(), 'created'));
        $deleted = $s->prune(2, 2, 1000 + 2 * 86400);
        sort($deleted);
        $expected = [$ids[100], $ids[300], $stale];
        sort($expected);
        $this->assertSame($expected, $deleted);
        $this->assertSame([600, 500, 400, 200], array_column($s->list(), 'created'));
    }

    public function testListSkipsMalformedPublicManifests(): void
    {
        $s = new LocalStore($this->dir);
        $s->ensure();
        $bad = bin2hex(random_bytes(16));
        mkdir($s->backupDir($bad), 0777, true);
        file_put_contents($s->publicManifestPath($bad), json_encode(['format' => 1, 'backup_id' => $bad, 'created' => 1]));
        $good = bin2hex(random_bytes(16));
        mkdir($s->backupDir($good), 0777, true);
        file_put_contents($s->publicManifestPath($good), json_encode(['format' => 1, 'backup_id' => $good, 'created' => 5,
            'kind' => 'full', 'volumes' => [['v' => 1], ['v' => 2, 'size' => 7]], 'refs' => []]));
        $list = $s->list();
        $this->assertSame([$good], array_column($list, 'id'));
        $this->assertSame(7, $list[0]['size']);
    }

    public function testEnsureWritesDenyFilesIntoTheDosyaBackupsParent(): void
    {
        $s = new LocalStore($this->dir . '/dosya-backups/site');
        $s->ensure();
        $this->assertFileExists($this->dir . '/dosya-backups/index.php');
        $this->assertFileExists($this->dir . '/dosya-backups/.htaccess');
    }

    public function testRejectsNonHexIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LocalStore($this->dir))->backupDir('../../etc');
    }
}
