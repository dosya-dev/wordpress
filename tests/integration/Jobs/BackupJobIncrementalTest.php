<?php
namespace Dosya\Backup\Tests\integration\Jobs;

use Dosya\Backup\Archive\BackupReader;
use Dosya\Backup\Archive\FileSource;
use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\Manifest;
use Dosya\Backup\Archive\PathCodec;
use Dosya\Backup\Archive\Source;
use Dosya\Backup\Connect\DosyaClient;
use Dosya\Backup\Jobs\BackupJob;
use Dosya\Backup\Jobs\FixedSiteInfo;
use Dosya\Backup\Jobs\LocalStore;
use Dosya\Backup\Jobs\RestoreJob;
use Dosya\Backup\Jobs\RestorePlan;
use Dosya\Backup\Jobs\Steppable;
use Dosya\Backup\Remote\RemoteStage;
use Dosya\Backup\Remote\RemoteStore;
use Dosya\Backup\Remote\RemoteUploader;
use Dosya\Backup\Restore\MaintenanceGuard;
use Dosya\Backup\Scan\Excludes;
use Dosya\Backup\Support\Budget;
use Dosya\Backup\Support\Fs;
use Dosya\Backup\Tests\integration\DbTestCase;
use Dosya\Backup\Tests\unit\Remote\FakeDosyaApi;

final class BackupJobIncrementalTest extends DbTestCase
{
    private string $tmp;
    private string $root;
    private int $t0;
    private LocalStore $store;
    private FakeDosyaApi $fake;
    private RemoteStore $remoteStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/bji-' . bin2hex(random_bytes(4));
        $this->root = $this->tmp . '/site';
        $this->t0 = time() - 1000;
        $files = [
            'index.php' => '<?php require "wp-blog-header.php";',
            // two incompressible files above the 1 MiB volume target: backup 1 has two volumes
            'wp-content/uploads/a.bin' => random_bytes(1100 * 1024),
            'wp-content/uploads/b.bin' => random_bytes(1100 * 1024),
            'wp-content/uploads/c.txt' => 'first version',
        ];
        foreach ($files as $p => $data) {
            $this->put($p, $data, $this->t0);
        }
        $this->store = new LocalStore($this->root . '/wp-content/dosya-backup-local/k3y');
        $t = $this->db->ident($this->prefix . 'posts');
        $this->db->exec("CREATE TABLE $t (ID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_title TEXT)");
        $this->db->exec("INSERT INTO $t (post_title) VALUES ('hello')");
        $this->fake = new FakeDosyaApi(131072);
        $this->remoteStore = new RemoteStore($this->store->dir());
    }

    protected function tearDown(): void
    {
        Fs::rrmdir($this->tmp);
        // PHPUnit keeps finished test objects: drop the fake, which holds every uploaded volume in memory
        unset($this->fake, $this->store, $this->remoteStore);
        parent::tearDown();
    }

    private function put(string $rel, string $data, int $mtime): void
    {
        Fs::ensureDir(dirname($this->root . '/' . $rel));
        file_put_contents($this->root . '/' . $rel, $data);
        touch($this->root . '/' . $rel, $mtime);
    }

    private function site(): FixedSiteInfo
    {
        return new FixedSiteInfo(['home' => 'http://a.test', 'abspath' => $this->root, 'content_dir' => $this->root . '/wp-content', 'prefix' => $this->prefix]);
    }

    private function job(int $keep = 3, bool $remote = true): BackupJob
    {
        $stage = null;
        if ($remote) {
            $api = new DosyaClient($this->fake, 'https://api.test', 'dos_site_secret');
            $stage = new RemoteStage($api, new RemoteUploader($api, 'ws1', 65536), $this->remoteStore, 'site1');
        }
        return new BackupJob($this->db, $this->store, $this->site(), new Excludes(Excludes::defaults()), $keep, 1024 * 1024, 64 * 1024, [], $stage);
    }

    private function drive(Steppable $job, array $cursor): array
    {
        $state = 'queued';
        $tick = 0.0;
        for ($i = 0; $i < 5000; $i++) {
            $clock = function () use (&$tick): float { $tick += 1.0; return $tick; };
            $r = $job->step($state, $cursor, new Budget(3.5, 0, $clock));
            $cursor = json_decode(json_encode($r['cursor'], JSON_THROW_ON_ERROR), true);
            $state = $r['state'];
            if (in_array($state, ['done', 'failed', 'skipped'], true)) {
                return $r;
            }
        }
        $this->fail('job did not finish');
    }

    private function backup(int $keep = 3, bool $remote = true): string
    {
        $r = $this->drive($this->job($keep, $remote), BackupJob::initialCursor('full'));
        $this->assertSame('done', $r['state'], (string) ($r['error'] ?? ''));
        return $r['result']['backup_id'];
    }

    /** @return array<string,array<string,mixed>> path => manifest row, files only */
    private function fileRows(Manifest $m): array
    {
        $out = [];
        foreach ($m->files as $row) {
            if ((int) $row['t'] === Format::TYPE_FILE) {
                $out[PathCodec::unpack($row)] = $row;
            }
        }
        return $out;
    }

    private function lastComplete(): array
    {
        return $this->fake->completeBodies[count($this->fake->completeBodies) - 1];
    }

    /** Changes c.txt (new size and mtime) and adds new.txt. */
    private function changeTwoFiles(): void
    {
        $this->put('wp-content/uploads/c.txt', 'second version, longer', $this->t0 + 10);
        $this->put('wp-content/uploads/new.txt', 'brand new', $this->t0 + 10);
    }

    public function testSecondRemoteBackupIsIncrementalAndRestoresByteExact(): void
    {
        $id1 = $this->backup();
        $m1 = $this->store->load($id1);
        $this->assertSame('full', $m1->meta['kind']);
        $this->assertSame($m1->meta['created'], $m1->meta['full_since']);
        $this->changeTwoFiles();

        $id2 = $this->backup();
        $m2 = $this->store->load($id2);
        $this->assertSame('incremental', $m2->meta['kind']);
        $this->assertSame('incremental', $this->fake->backups[$id2]['kind']);
        $this->assertSame('uploaded', $this->fake->backups[$id2]['state']);
        $this->assertSame($m1->meta['full_since'], $m2->meta['full_since']);

        $rows1 = $this->fileRows($m1);
        $rows2 = $this->fileRows($m2);
        $own = [];
        foreach ($rows2 as $path => $row) {
            if ($row['loc']['b'] === $id2) {
                $own[] = $path;
            } else {
                // an unchanged file keeps backup 1's row as it was
                $this->assertSame($id1, $row['loc']['b'], $path);
                $this->assertSame($rows1[$path], $row, $path);
            }
        }
        sort($own);
        $this->assertSame(['wp-content/uploads/c.txt', 'wp-content/uploads/new.txt'], $own);

        // only this backup's volumes are listed; the earlier ones are refs
        foreach ($m2->meta['volumes'] as $v) {
            $this->assertFileExists($this->store->volumePath($id2, (int) $v['v']));
        }
        $this->assertCount(1, $m2->meta['volumes']);
        $refs = $m2->publicView()['refs'];
        $this->assertNotEmpty($refs);
        $sizes1 = array_column($m1->meta['volumes'], 'size', 'v');
        $refBytes = 0;
        $expectRefs = [];
        foreach ($refs as $r) {
            $this->assertSame($id1, $r['b']);
            $refBytes += (int) $sizes1[$r['v']];
            $expectRefs[] = ['backup_id' => $id1, 'volume_no' => (int) $r['v']];
        }
        $body = $this->lastComplete();
        $this->assertSame($expectRefs, $body['refs']);
        $ownBytes = (int) array_sum(array_column($m2->meta['volumes'], 'size'));
        $this->assertSame($ownBytes, $body['bytes_new']);
        $this->assertSame($ownBytes + $refBytes, $body['bytes_total']);
        $this->assertLessThan($body['bytes_total'], $body['bytes_new']);
        $this->assertSame($ownBytes, $m2->meta['bytes_new']);
        $this->assertSame($ownBytes + $refBytes, $m2->meta['bytes_total']);
        $this->assertSame(json_decode((string) file_get_contents($this->store->publicManifestPath($id2)), true)['refs'], $refs);
        // the sidecar index is gone once the backup is done
        $this->assertFileDoesNotExist($this->store->backupDir($id2) . '/prev_index.bin');
        $this->assertFileDoesNotExist($this->store->backupDir($id2) . '/prev_rows.jsonl');

        // every file reads back byte-exact across both backups' volumes
        $reader = new BackupReader(function (string $b, int $v): Source { return new FileSource($this->store->volumePath($b, $v)); });
        foreach ($rows2 as $path => $row) {
            $this->assertSame(file_get_contents($this->root . '/' . $path), $reader->readAll($row['loc']), $path);
        }

        // and a real files restore onto an empty site reproduces them
        $target = $this->tmp . '/target';
        Fs::ensureDir($target . '/wp-content');
        $siteB = new FixedSiteInfo(['home' => 'http://a.test', 'abspath' => $target, 'content_dir' => $target . '/wp-content', 'prefix' => $this->prefix]);
        $plan = RestorePlan::build($m2, $siteB, ['scope' => 'files', 'health' => false]);
        $restore = new RestoreJob($this->db, $this->store, $siteB, new MaintenanceGuard($target . '/wp-content/mu-plugins', $target . '/wp-content'),
            function (string $url): array { return ['status' => 200, 'body' => 'ok']; });
        $r = $this->drive($restore, RestoreJob::initialCursor($plan));
        $this->assertSame('done', $r['state'], (string) ($r['error'] ?? ''));
        foreach (array_keys($rows2) as $path) {
            $this->assertSame(file_get_contents($this->root . '/' . $path), file_get_contents($target . '/' . $path), $path);
        }
    }

    public function testThirdBackupChainsThroughTheSecond(): void
    {
        $id1 = $this->backup();
        $this->changeTwoFiles();
        $id2 = $this->backup();
        $this->put('wp-content/uploads/new.txt', 'brand new, edited', $this->t0 + 20);
        $id3 = $this->backup();
        $m3 = $this->store->load($id3);
        $this->assertSame('incremental', $m3->meta['kind']);
        $rows3 = $this->fileRows($m3);
        $this->assertSame($id1, $rows3['wp-content/uploads/a.bin']['loc']['b']);
        $this->assertSame($id2, $rows3['wp-content/uploads/c.txt']['loc']['b']);
        $this->assertSame($id3, $rows3['wp-content/uploads/new.txt']['loc']['b']);
        $refIds = array_values(array_unique(array_column($m3->publicView()['refs'], 'b')));
        sort($refIds);
        $expect = [$id1, $id2];
        sort($expect);
        $this->assertSame($expect, $refIds);
        $body = $this->lastComplete();
        $sizes = [];
        foreach ([$id1, $id2] as $id) {
            foreach ($this->store->load($id)->meta['volumes'] as $v) {
                $sizes[$id . ':' . $v['v']] = (int) $v['size'];
            }
        }
        $refBytes = 0;
        foreach ($body['refs'] as $r) {
            $refBytes += $sizes[$r['backup_id'] . ':' . $r['volume_no']];
        }
        $this->assertSame($body['bytes_new'] + $refBytes, $body['bytes_total']);
        $this->assertSame('uploaded', $this->fake->backups[$id3]['state']);
    }

    public function testIncrementalFallsBackToFullWhenPreviousIsGone(): void
    {
        $id1 = $this->backup();
        unset($this->fake->backups[$id1]);
        $this->changeTwoFiles();
        $id2 = $this->backup();
        $m2 = $this->store->load($id2);
        $this->assertSame('full', $m2->meta['kind']);
        $this->assertSame('full', $this->fake->backups[$id2]['kind']);
        $this->assertSame([], $this->lastComplete()['refs']);
        $this->assertSame([], $m2->publicView()['refs']);
        $this->assertSame($m2->meta['created'], $m2->meta['full_since']);
    }

    public function testFallsBackToFullWhenPreviousIsNotUploaded(): void
    {
        $id1 = $this->backup();
        $this->fake->backups[$id1]['state'] = 'running';
        $id2 = $this->backup();
        $this->assertSame('full', $this->store->load($id2)->meta['kind']);
    }

    public function testFullSinceOlderThanThirtyDaysStartsANewFull(): void
    {
        $id1 = $this->backup();
        $path = $this->remoteStore->manifestPath($id1);
        $m = Manifest::loadGz($path);
        $m->meta['full_since'] = time() - 31 * 86400;
        $m->saveGz($path);
        $this->changeTwoFiles();
        $id2 = $this->backup();
        $m2 = $this->store->load($id2);
        $this->assertSame('full', $m2->meta['kind']);
        $this->assertSame([], $this->lastComplete()['refs']);
        $this->assertSame($m2->meta['created'], $m2->meta['full_since']);
    }

    public function testFullSinceExactlyThirtyDaysOldStartsANewFull(): void
    {
        $id1 = $this->backup();
        $path = $this->remoteStore->manifestPath($id1);
        $m = Manifest::loadGz($path);
        $m->meta['full_since'] = time() - RemoteStore::CHAIN_MAX_AGE;
        $m->saveGz($path);
        $id2 = $this->backup();
        $this->assertSame('full', $this->store->load($id2)->meta['kind']);
        $this->assertSame([], $this->lastComplete()['refs']);
    }

    public function testAChainReadingMoreThanTheServerRefCapTakesAFull(): void
    {
        $id1 = $this->backup();
        $path = $this->remoteStore->manifestPath($id1);
        $m = Manifest::loadGz($path);
        // a synthetic previous backup whose rows span 2001 volumes
        $vols = [];
        for ($v = 1; $v <= RemoteStage::MAX_REFS + 1; $v++) {
            $vols[] = ['v' => $v, 'size' => 100, 'sha256' => str_repeat('0', 64)];
            $m->files[] = ['p' => 'wp-content/spread/f' . $v . '.txt', 't' => Format::TYPE_FILE, 's' => 1, 'h' => str_repeat('a', 64),
                'm' => 0644, 'mt' => $this->t0, 'loc' => ['b' => $id1, 'v' => $v, 'ho' => 0]];
        }
        $m->meta['volumes'] = $vols;
        $m->saveGz($path);
        $this->changeTwoFiles();
        $r = $this->drive($this->job(), BackupJob::initialCursor('full'));
        $this->assertSame('done', $r['state'], (string) ($r['error'] ?? ''));
        $this->assertSame('full', $this->store->load($r['result']['backup_id'])->meta['kind']);
        $this->assertSame([], $this->lastComplete()['refs']);
        $this->assertContains(BackupJob::CHAIN_TOO_LONG, $r['result']['warnings']);
        $this->assertFileDoesNotExist($this->store->backupDir($r['result']['backup_id']) . '/prev_index.bin');
    }

    public function testListPayloadCarriesKindAndBytes(): void
    {
        $id1 = $this->backup();
        $this->changeTwoFiles();
        $id2 = $this->backup();
        $byId = [];
        foreach ($this->store->list() as $b) {
            $byId[$b['id']] = $b;
        }
        $m2 = $this->store->load($id2);
        $this->assertSame('incremental', $byId[$id2]['kind']);
        $this->assertSame($m2->meta['bytes_new'], $byId[$id2]['bytes_new']);
        $this->assertSame($m2->meta['bytes_total'], $byId[$id2]['bytes_total']);
        $this->assertSame('full', $byId[$id1]['kind']);
        $this->assertSame($byId[$id1]['size'], $byId[$id1]['bytes_total']);
        $this->assertSame([$id2], $this->store->dependents($id1));
        $this->assertSame('This backup is the base of 1 newer backup(s): ' . $id2 . '. Delete those first.', $this->store->deleteRefusal($id1));
    }

    public function testAnMtimeOnlyChangeReArchivesTheFile(): void
    {
        $id1 = $this->backup();
        touch($this->root . '/wp-content/uploads/a.bin', $this->t0 + 30);
        $id2 = $this->backup();
        $m2 = $this->store->load($id2);
        $this->assertSame('incremental', $m2->meta['kind']);
        $rows = $this->fileRows($m2);
        $this->assertSame($id2, $rows['wp-content/uploads/a.bin']['loc']['b']);
        $this->assertSame($this->t0 + 30, (int) $rows['wp-content/uploads/a.bin']['mt']);
        $this->assertSame($id1, $rows['wp-content/uploads/b.bin']['loc']['b']);
    }

    public function testListUnreachableFallsBackToFullWithAWarning(): void
    {
        $this->backup();
        $this->fake->failNext('GET', '#^/api/wp/sites/[^/]+/backups$#', 503);
        $this->changeTwoFiles();
        $r = $this->drive($this->job(), BackupJob::initialCursor('full'));
        $this->assertSame('done', $r['state'], (string) ($r['error'] ?? ''));
        $m2 = $this->store->load($r['result']['backup_id']);
        $this->assertSame('full', $m2->meta['kind']);
        $this->assertSame([], $this->lastComplete()['refs']);
        $this->assertContains(BackupJob::LIST_UNREACHABLE, $r['result']['warnings']);
        $this->assertSame('uploaded', $this->fake->backups[$r['result']['backup_id']]['state']);
    }

    public function testLocalOnlyBackupsStayFull(): void
    {
        $this->backup(3, false);
        $this->changeTwoFiles();
        $id2 = $this->backup(3, false);
        $m2 = $this->store->load($id2);
        $this->assertSame('full', $m2->meta['kind']);
        $this->assertSame([], $m2->publicView()['refs']);
        $this->assertSame([], $this->fake->requests);
    }

    public function testPruneKeepsBackupsANewerKeptBackupReferences(): void
    {
        $id1 = $this->backup(1);
        // make the order unambiguous: backup 1 is older
        $pub = json_decode((string) file_get_contents($this->store->publicManifestPath($id1)), true);
        $pub['created'] -= 60;
        file_put_contents($this->store->publicManifestPath($id1), json_encode($pub));
        $this->changeTwoFiles();
        $id2 = $this->backup(1);
        $this->assertSame('incremental', $this->store->load($id2)->meta['kind']);
        // keep is 1, but backup 2 reads backup 1's volumes, so both stay
        $ids = array_column($this->store->list(), 'id');
        sort($ids);
        $expect = [$id1, $id2];
        sort($expect);
        $this->assertSame($expect, $ids);
        $this->assertSame([], $this->store->prune(1, 2, time()));
    }

    public function testPruneDropsAnOldFullOnceNoKeptBackupReadsIt(): void
    {
        $id1 = $this->backup(1);
        $pub = json_decode((string) file_get_contents($this->store->publicManifestPath($id1)), true);
        $pub['created'] -= 60;
        file_put_contents($this->store->publicManifestPath($id1), json_encode($pub));
        // a new full (the previous one is gone from dosya) references nothing
        unset($this->fake->backups[$id1]);
        $id2 = $this->backup(1);
        $this->assertSame('full', $this->store->load($id2)->meta['kind']);
        $this->assertSame([$id2], array_column($this->store->list(), 'id'));
    }

    public function testRestoreFailsClearlyWhenAReferencedBackupIsMissing(): void
    {
        $id1 = $this->backup();
        $this->changeTwoFiles();
        $id2 = $this->backup();
        $m2 = $this->store->load($id2);
        $this->assertSame('incremental', $m2->meta['kind']);
        Fs::rrmdir($this->store->backupDir($id1) . '/volumes');

        $target = $this->tmp . '/target';
        Fs::ensureDir($target . '/wp-content');
        $siteB = new FixedSiteInfo(['home' => 'http://a.test', 'abspath' => $target, 'content_dir' => $target . '/wp-content', 'prefix' => $this->prefix]);
        $plan = RestorePlan::build($m2, $siteB, ['scope' => 'files', 'health' => false]);
        $restore = new RestoreJob($this->db, $this->store, $siteB, new MaintenanceGuard($target . '/wp-content/mu-plugins', $target . '/wp-content'),
            function (string $url): array { return ['status' => 200, 'body' => 'ok']; });
        try {
            $this->drive($restore, RestoreJob::initialCursor($plan));
            $this->fail('expected the restore to fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($id1, $e->getMessage());
            $this->assertStringContainsString('no longer on this site', $e->getMessage());
        }
        $this->assertFileDoesNotExist($target . '/wp-content/uploads/a.bin');
        $this->assertFileDoesNotExist($target . '/wp-content/mu-plugins/dosya-restore-guard.php');
    }
}
