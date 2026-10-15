<?php
namespace Dosya\Backup\Tests\integration\Jobs;

use Dosya\Backup\Archive\Format;
use Dosya\Backup\Connect\DosyaClient;
use Dosya\Backup\Jobs\BackupJob;
use Dosya\Backup\Jobs\FixedSiteInfo;
use Dosya\Backup\Jobs\JobStore;
use Dosya\Backup\Jobs\LocalStore;
use Dosya\Backup\Jobs\Steppable;
use Dosya\Backup\Jobs\StepRunner;
use Dosya\Backup\Remote\RemoteStage;
use Dosya\Backup\Remote\RemoteStore;
use Dosya\Backup\Remote\RemoteUploader;
use Dosya\Backup\Scan\Excludes;
use Dosya\Backup\Support\Budget;
use Dosya\Backup\Support\Fs;
use Dosya\Backup\Tests\integration\DbTestCase;
use Dosya\Backup\Tests\unit\Remote\FakeDosyaApi;

final class BackupJobRemoteTest extends DbTestCase
{
    private string $root;
    private LocalStore $store;
    private FakeDosyaApi $fake;
    private RemoteStore $remoteStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/bjr-' . bin2hex(random_bytes(4));
        $files = [
            'index.php' => '<?php require "wp-blog-header.php";',
            // volumes roll over between entries: two incompressible files above the 1 MiB target make two volumes
            'wp-content/uploads/a.bin' => random_bytes(1100 * 1024),
            'wp-content/uploads/b.bin' => random_bytes(1100 * 1024),
        ];
        foreach ($files as $p => $data) {
            Fs::ensureDir(dirname($this->root . '/' . $p));
            file_put_contents($this->root . '/' . $p, $data);
        }
        $this->store = new LocalStore($this->root . '/wp-content/dosya-backup-local/k3y');
        $t = $this->db->ident($this->prefix . 'posts');
        $this->db->exec("CREATE TABLE $t (ID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_title TEXT)");
        for ($i = 0; $i < 20; $i++) { $this->db->exec("INSERT INTO $t (post_title) VALUES ('post $i')"); }
        // parts of 128 KiB and a 64 KiB one-shot limit: both volumes go through multipart sessions
        $this->fake = new FakeDosyaApi(131072);
        $this->remoteStore = new RemoteStore($this->store->dir());
    }

    protected function tearDown(): void
    {
        Fs::rrmdir($this->root);
        // PHPUnit keeps finished test objects: drop the fake, which holds every uploaded volume in memory
        unset($this->fake, $this->store, $this->remoteStore);
        parent::tearDown();
    }

    private function job(): BackupJob
    {
        $api = new DosyaClient($this->fake, 'https://api.test', 'dos_site_secret');
        $remote = new RemoteStage($api, new RemoteUploader($api, 'ws1', 65536), $this->remoteStore, 'site1');
        $site = new FixedSiteInfo(['home' => 'http://a.test', 'abspath' => $this->root, 'content_dir' => $this->root . '/wp-content', 'prefix' => $this->prefix]);
        return new BackupJob($this->db, $this->store, $site, new Excludes(Excludes::defaults()), 3, 1024 * 1024, 64 * 1024, [], $remote);
    }

    /**
     * Drives the job step by step. $replay(state, cursor) true: the step runs once with its side effects but the
     * returned cursor is lost, so the next run starts from the old one (a crash before the cursor write).
     * @param list<array<string,mixed>> $progress filled with every progress seen
     */
    private function runJob(BackupJob $job, ?callable $replay = null, array &$progress = []): array
    {
        $state = 'queued';
        $cursor = BackupJob::initialCursor('full');
        $tick = 0.0;
        for ($i = 0; $i < 5000; $i++) {
            $clock = function () use (&$tick): float { $tick += 1.0; return $tick; };
            if ($replay !== null && $replay($state, $cursor)) {
                $job->step($state, $cursor, new Budget(3.5, 0, $clock));
            }
            $r = $job->step($state, $cursor, new Budget(3.5, 0, $clock));
            $cursor = json_decode(json_encode($r['cursor'], JSON_THROW_ON_ERROR), true);
            $state = $r['state'];
            $progress[] = $cursor['progress'] ?? [];
            if (in_array($state, ['done', 'failed', 'skipped'], true)) {
                return $r;
            }
        }
        $this->fail('job did not finish');
    }

    /** Every (session, part) pair the fake received, with how many times. @return array<string,int> */
    private function partCounts(): array
    {
        $n = [];
        foreach ($this->fake->requestLines() as $line) {
            if (preg_match('#^PUT /api/upload/([^/]+)/part/(\d+)$#', $line, $m) === 1) {
                $k = $m[1] . '#' . $m[2];
                $n[$k] = ($n[$k] ?? 0) + 1;
            }
        }
        return $n;
    }

    private function assertUploaded(string $id): void
    {
        $m = $this->store->load($id);
        $vols = $m->meta['volumes'];
        $this->assertCount(2, $vols);
        $srv = $this->fake->backups[$id];
        $this->assertSame('uploaded', $srv['state']);
        $this->assertSame('full', $srv['kind']);
        $body = $this->fake->completeBodies[0];
        $this->assertSame([], $body['refs']);
        $total = 0;
        foreach ($vols as $i => $v) {
            $local = $this->store->volumePath($id, (int) $v['v']);
            $sent = $body['volumes'][$i];
            $this->assertSame((int) $v['v'], $sent['volume_no']);
            $this->assertSame((int) $v['size'], $sent['size']);
            $this->assertSame($v['sha256'], $sent['sha256']);
            $this->assertSame(file_get_contents($local), $this->fake->fileBytes($sent['file_id']));
            $this->assertSame(['folder' => $srv['volumes_folder_id'], 'name' => basename($local)], $this->fake->fileMeta[$sent['file_id']]);
            $total += (int) $v['size'];
            // ruling: local volumes stay after the upload (restore from dosya is a later phase)
            $this->assertFileExists($local);
        }
        $this->assertCount(2, $body['volumes']);
        $this->assertSame($total, $body['bytes_new']);
        $this->assertSame($total, $body['bytes_total']);
        $this->assertSame(file_get_contents($this->store->manifestPath($id)), $this->fake->fileBytes($body['manifest_file_id']));
        $this->assertSame(['folder' => $srv['folder_id'], 'name' => 'manifest.json.gz'], $this->fake->fileMeta[$body['manifest_file_id']]);
        $this->assertSame(file_get_contents($this->store->publicManifestPath($id)), $this->fake->fileBytes($body['public_manifest_file_id']));
        $this->assertSame(['folder' => $srv['folder_id'], 'name' => 'manifest.public.json'], $this->fake->fileMeta[$body['public_manifest_file_id']]);

        $this->assertTrue($this->remoteStore->has($id));
        $this->assertSame(file_get_contents($this->store->manifestPath($id)), file_get_contents($this->remoteStore->manifestPath($id)));
        $this->assertSame(['backup_id' => $id, 'created' => (int) $m->meta['created']], $this->remoteStore->last());
        foreach ($this->partCounts() as $k => $count) {
            $this->assertSame(1, $count, 'part ' . $k . ' sent more than once');
        }
        $this->assertSame(1, $this->fake->completeCalls);
        $this->assertSame(1, count($this->fake->backups));
    }

    public function testUploadsVolumesAndManifestsThenCompletes(): void
    {
        $progress = [];
        $r = $this->runJob($this->job(), null, $progress);
        $this->assertSame('done', $r['state'], (string) ($r['error'] ?? ''));
        $id = $r['result']['backup_id'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        $this->assertUploaded($id);
        $this->assertSame(1, $this->fake->registerCalls);
        $this->assertSame(1800000000, $r['result']['remote']['locked_until']);
        // the remote stage runs after the manifests are written and before the cleanup
        $this->assertFileDoesNotExist($this->store->backupDir($id) . '/entries.jsonl');
        $this->assertSame([$id], array_column($this->store->list(), 'id'));

        $remote = array_values(array_filter($progress, function (array $p): bool { return ($p['phase'] ?? '') === 'remote'; }));
        $this->assertNotEmpty($remote);
        $this->assertContains(['phase' => 'remote', 'step' => 'volumes', 'volume' => 2, 'volumes' => 2], $remote);
        $this->assertContains(['phase' => 'remote', 'step' => 'manifests', 'volume' => 2, 'volumes' => 2], $remote);
        // the site key never lands in a cursor
        $this->assertStringNotContainsString('dos_site_secret', json_encode($progress, JSON_THROW_ON_ERROR));
    }

    public function testReplayingOlderCursorsMidUploadSendsEveryPartOnce(): void
    {
        $replays = 0;
        $r = $this->runJob($this->job(), function (string $state, array $c) use (&$replays): bool {
            $hit = $state === 'finalize' && ($c['fin']['stage'] ?? '') === 'remote' && ($c['remote']['stage'] ?? '') === 'volumes';
            $replays += $hit ? 1 : 0;
            return $hit;
        });
        $this->assertSame('done', $r['state'], (string) ($r['error'] ?? ''));
        $this->assertGreaterThan(4, $replays);
        $this->assertUploaded($r['result']['backup_id']);
        $this->assertGreaterThan(8, count($this->partCounts()));
    }

    public function testA503OnCompleteFailsTheJobAndKeepsTheLocalBackup(): void
    {
        $this->fake->failNext('POST', '#^/api/wp/backups/[0-9a-f]{32}/complete$#', 503);
        $jobs = new JobStore($this->db);
        $jobs->install();
        $jobId = $jobs->create('backup', BackupJob::initialCursor('full'));
        $job = $this->job();
        $runner = new StepRunner($this->db, $jobs, function () use ($job): Steppable { return $job; });
        $tick = 0.0;
        for ($i = 0; $i < 5000; $i++) {
            $s = $runner->step($jobId, new Budget(3.5, 0, function () use (&$tick): float { $tick += 1.0; return $tick; }));
            if (StepRunner::isTerminal($s['state'])) {
                break;
            }
        }
        $this->assertSame('failed', $s['state']);
        $this->assertSame('dosya upload failed (503). The backup is kept locally; run it again later.', $s['error']);
        $this->assertSame(['phase' => 'remote', 'step' => 'complete', 'volume' => 2, 'volumes' => 2], $s['progress']);

        $ids = array_keys($this->fake->backups);
        $this->assertCount(1, $ids);
        $id = $ids[0];
        $this->assertSame('running', $this->fake->backups[$id]['state']);
        $this->assertFalse($this->remoteStore->has($id));
        $this->assertNull($this->remoteStore->last());
        $m = $this->store->load($id);
        foreach ($m->meta['volumes'] as $v) {
            $this->assertSame($v['sha256'], hash_file('sha256', $this->store->volumePath($id, (int) $v['v'])));
        }
        $this->assertFileExists($this->store->publicManifestPath($id));
    }

    public function testDisconnectingMidUploadKeepsTheBackupLocalWithAWarning(): void
    {
        $site = new FixedSiteInfo(['home' => 'http://a.test', 'abspath' => $this->root, 'content_dir' => $this->root . '/wp-content', 'prefix' => $this->prefix]);
        $local = new BackupJob($this->db, $this->store, $site, new Excludes(Excludes::defaults()), 3, 1024 * 1024, 64 * 1024);
        $remote = $this->job();
        $sid = null;
        $requestsAtSwitch = null;
        $remoteAtSwitch = null;
        $state = 'queued';
        $cursor = BackupJob::initialCursor('full');
        $tick = 0.0;
        for ($i = 0; $i < 5000 && !in_array($state, ['done', 'failed', 'skipped'], true); $i++) {
            // the job is built per step (Plugin::makeJob): once the connection is gone there is no RemoteStage
            $sid = $sid ?? (isset($cursor['remote']) ? RemoteStage::openSession($cursor['remote']) : null);
            if ($sid !== null && $requestsAtSwitch === null) {
                $requestsAtSwitch = count($this->fake->requests);
                $remoteAtSwitch = $cursor['remote'];
            }
            $job = $sid === null ? $remote : $local;
            $r = $job->step($state, $cursor, new Budget(3.5, 0, function () use (&$tick): float { $tick += 1.0; return $tick; }));
            $cursor = json_decode(json_encode($r['cursor'], JSON_THROW_ON_ERROR), true);
            $state = $r['state'];
        }
        $this->assertNotNull($sid);
        $this->assertSame('done', $state, (string) ($r['error'] ?? ''));
        $this->assertSame(BackupJob::REMOTE_GONE, $r['result']['warnings'][0]);
        $this->assertArrayNotHasKey('remote', $r['result']);
        $this->assertSame($requestsAtSwitch, count($this->fake->requests));
        $id = $r['result']['backup_id'];
        $this->assertFalse($this->remoteStore->has($id));
        $this->assertSame([$id], array_column($this->store->list(), 'id'));
        foreach ($this->store->load($id)->meta['volumes'] as $v) {
            $this->assertFileExists($this->store->volumePath($id, (int) $v['v']));
        }
        // Connector::disconnect aborts the open session from the stored cursor while the key still works
        RemoteStage::abortOpen(new DosyaClient($this->fake, 'https://api.test', 'k'), $remoteAtSwitch);
        $this->assertSame(['DELETE /api/upload/' . $sid], array_slice($this->fake->requestLines(), -1));
    }

    public function testWithoutARemoteStageTheJobIsLocalOnly(): void
    {
        $site = new FixedSiteInfo(['home' => 'http://a.test', 'abspath' => $this->root, 'content_dir' => $this->root . '/wp-content', 'prefix' => $this->prefix]);
        $job = new BackupJob($this->db, $this->store, $site, new Excludes(Excludes::defaults()), 3, 1024 * 1024, Format::BLOCK_MAX);
        $r = $this->runJob($job);
        $this->assertSame('done', $r['state'], (string) ($r['error'] ?? ''));
        $this->assertSame([], $this->fake->requests);
        $this->assertArrayNotHasKey('remote', $r['result']);
        $this->assertFalse($this->remoteStore->has($r['result']['backup_id']));
    }
}
