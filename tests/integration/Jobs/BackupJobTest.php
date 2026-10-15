<?php
namespace Dosya\Backup\Tests\integration\Jobs;

use Dosya\Backup\Archive\BackupReader;
use Dosya\Backup\Archive\FileSource;
use Dosya\Backup\Archive\PathCodec;
use Dosya\Backup\Archive\Source;
use Dosya\Backup\Archive\VolumeReader;
use Dosya\Backup\Jobs\BackupJob;
use Dosya\Backup\Jobs\FixedSiteInfo;
use Dosya\Backup\Jobs\LocalStore;
use Dosya\Backup\Scan\Excludes;
use Dosya\Backup\Support\Budget;
use Dosya\Backup\Support\Fs;
use Dosya\Backup\Tests\integration\DbTestCase;

final class BackupJobTest extends DbTestCase
{
    private string $root;
    private LocalStore $store;
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/bj-' . bin2hex(random_bytes(4));
        $this->files = [
            'index.php' => '<?php require "wp-blog-header.php";',
            'wp-content/uploads/2026/10/big.bin' => random_bytes(3 * 1024 * 1024 + 17),
            "wp-content/uploads/2026/10/caf\xE9.txt" => 'latin1 name',
            'wp-content/themes/t/style.css' => str_repeat('body{}', 5000),
        ];
        foreach ($this->files as $p => $data) {
            Fs::ensureDir(dirname($this->root . '/' . $p));
            file_put_contents($this->root . '/' . $p, $data);
        }
        $this->store = new LocalStore($this->root . '/wp-content/dosya-backup-local/k3y');
        $t = $this->db->ident($this->prefix . 'posts');
        $this->db->exec("CREATE TABLE $t (ID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_title TEXT)");
        for ($i = 0; $i < 50; $i++) { $this->db->exec("INSERT INTO $t (post_title) VALUES ('post $i')"); }
    }

    protected function tearDown(): void
    {
        Fs::rrmdir($this->root);
        parent::tearDown();
    }

    private function site(): FixedSiteInfo
    {
        return new FixedSiteInfo(['home' => 'http://a.test', 'abspath' => $this->root, 'content_dir' => $this->root . '/wp-content', 'prefix' => $this->prefix]);
    }

    /** @param int[] $replayAt 1-based step numbers; 0 means the first step run in state finalize */
    private function runJob(BackupJob $job, array $cursor, array $replayAt = [], ?callable $beforeStep = null): array
    {
        $state = 'queued';
        $tick = 0.0;
        $replayedFinalize = false;
        for ($i = 0; $i < 5000; $i++) {
            if ($beforeStep !== null) {
                $beforeStep($state);
            }
            $clock = function () use (&$tick): float { $tick += 1.0; return $tick; };
            $replay = in_array($i + 1, $replayAt, true);
            if (in_array(0, $replayAt, true) && $state === 'finalize' && !$replayedFinalize) {
                $replayedFinalize = true;
                $replay = true;
            }
            if ($replay) {
                // side effects reach disk, but the cursor is lost: the next run starts from the old one
                $job->step($state, $cursor, new Budget(3.5, 0, $clock));
            }
            $r = $job->step($state, $cursor, new Budget(3.5, 0, $clock));
            $cursor = json_decode(json_encode($r['cursor'], JSON_THROW_ON_ERROR), true);
            $state = $r['state'];
            if (in_array($state, ['done', 'failed', 'skipped'], true)) {
                return $r;
            }
        }
        $this->fail('job did not finish');
    }

    public function testNoteRoundTripsIntoManifestAndList(): void
    {
        $job = new BackupJob($this->db, $this->store, $this->site(), new Excludes(Excludes::defaults()), 3, 1024 * 1024, 64 * 1024);
        $r = $this->runJob($job, BackupJob::initialCursor('full', 'manual', 'before theme update'));
        $this->assertSame('done', $r['state'], (string) ($r['error'] ?? ''));
        $this->assertArrayNotHasKey('note', $this->store->load($r['result']['backup_id'])->meta, 'the note stays on this site');
        $row = $this->store->list()[0];
        $this->assertSame('before theme update', $row['note']);
        $this->assertSame('manual', $row['label']);
        $this->assertFalse($row['uploaded']);
        $this->assertSame($this->site()->homeUrl(), $row['origin']);
    }

    public function testFullBackupIsCompleteAndVerifiable(): void
    {
        $job = new BackupJob($this->db, $this->store, $this->site(), new Excludes(Excludes::defaults()), 3, 1024 * 1024, 64 * 1024);
        $r = $this->runJob($job, BackupJob::initialCursor('full'));
        $this->assertSame('done', $r['state'], (string) ($r['error'] ?? ''));
        $id = $r['result']['backup_id'];
        $m = $this->store->load($id);

        $this->assertGreaterThan(1, count($m->meta['volumes']));
        foreach ($m->meta['volumes'] as $v) {
            (new VolumeReader(new FileSource($this->store->volumePath($id, $v['v']))))->verify();
            $this->assertSame(hash_file('sha256', $this->store->volumePath($id, $v['v'])), $v['sha256']);
        }
        $reader = new BackupReader(function (string $b, int $v) use ($id): Source { return new FileSource($this->store->volumePath($id, $v)); });
        $byPath = [];
        foreach ($m->files as $f) { $byPath[PathCodec::unpack($f)] = $f; }
        foreach ($this->files as $p => $data) {
            $this->assertArrayHasKey($p, $byPath);
            $this->assertSame($data, $reader->readAll($byPath[$p]['loc']), $p);
        }
        foreach (array_keys($byPath) as $p) {
            $this->assertStringStartsNotWith('wp-content/dosya-backup-local', $p);
        }
        $this->assertSame(50, $m->meta['rows'][$this->prefix . 'posts']);
        $this->assertArrayNotHasKey($this->prefix . 'dosya_jobs', $m->tables);
        $this->assertSame([$id], array_column($this->store->list(), 'id'));
    }

    private function assertCompleteBackup(array $r): void
    {
        $this->assertSame('done', $r['state'], (string) ($r['error'] ?? ''));
        $id = $r['result']['backup_id'];
        $m = $this->store->load($id);

        $this->assertGreaterThan(1, count($m->meta['volumes']));
        foreach ($m->meta['volumes'] as $v) {
            (new VolumeReader(new FileSource($this->store->volumePath($id, $v['v']))))->verify();
            $this->assertSame(hash_file('sha256', $this->store->volumePath($id, $v['v'])), $v['sha256']);
        }
        $reader = new BackupReader(function (string $b, int $v) use ($id): Source { return new FileSource($this->store->volumePath($id, $v)); });
        $byPath = [];
        foreach ($m->files as $f) { $byPath[PathCodec::unpack($f)] = $f; }
        foreach ($this->files as $p => $data) {
            $this->assertArrayHasKey($p, $byPath);
            $this->assertSame($data, $reader->readAll($byPath[$p]['loc']), $p);
        }
        $this->assertSame(50, $m->meta['rows'][$this->prefix . 'posts']);
        $this->assertSame([$id], array_column($this->store->list(), 'id'));
    }

    public function testReplayingAnOlderCursorProducesAnIdenticalBackup(): void
    {
        $job = new BackupJob($this->db, $this->store, $this->site(), new Excludes(Excludes::defaults()), 3, 1024 * 1024, 64 * 1024);
        $r = $this->runJob($job, BackupJob::initialCursor('full'), [3, 7, 0]);
        $this->assertCompleteBackup($r);
    }

    public function testStoreUnderAbspathIsNeverArchived(): void
    {
        $this->store = new LocalStore($this->root . '/wp-content/uploads/odd-store');
        $job = new BackupJob($this->db, $this->store, $this->site(), new Excludes([]), 3, 1024 * 1024, 64 * 1024);
        $r = $this->runJob($job, BackupJob::initialCursor('full'));
        $this->assertSame('done', $r['state'], (string) ($r['error'] ?? ''));
        $m = $this->store->load($r['result']['backup_id']);
        $this->assertNotEmpty($m->files);
        foreach ($m->files as $f) {
            $this->assertStringStartsNotWith('wp-content/uploads/odd-store', PathCodec::unpack($f));
        }
    }

    public function testDbOnlyScopeHasNoFiles(): void
    {
        $job = new BackupJob($this->db, $this->store, $this->site(), new Excludes([]));
        $r = $this->runJob($job, BackupJob::initialCursor('db', 'pre-restore'));
        $this->assertSame('done', $r['state']);
        $m = $this->store->load($r['result']['backup_id']);
        $this->assertSame([], $m->files);
        $this->assertSame('pre-restore', $m->meta['label']);
        $this->assertArrayHasKey($this->prefix . 'posts', $m->tables);
    }

    public function testUnreadableNonUtf8FileWarnsAndCompletes(): void
    {
        $locked = $this->root . "/wp-content/uploads/caf\xE9-locked.txt";
        file_put_contents($locked, 'secret');
        $asRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $removed = false;
        if (!$asRoot) {
            // the scanner's is_readable() check fails and warns "Could not read ..."
            chmod($locked, 0000);
        }
        $job = new BackupJob($this->db, $this->store, $this->site(), new Excludes(Excludes::defaults()), 3, 1024 * 1024, 64 * 1024);
        // root reads any file, so make the "File disappeared" path fire instead: the file is listed, then gone before archiving
        $r = $this->runJob($job, BackupJob::initialCursor('full'), [], function (string $state) use ($asRoot, $locked, &$removed): void {
            if ($asRoot && $state === 'archive' && !$removed) {
                unlink($locked);
                $removed = true;
            }
        });
        if (!$asRoot) {
            chmod($locked, 0644);
        }
        $this->assertSame('done', $r['state'], (string) ($r['error'] ?? ''));
        $all = implode("\n", $r['result']['warnings']);
        $this->assertStringContainsString('caf?-locked.txt', $all);
        $this->assertTrue(preg_match('//u', $all) === 1);
        json_encode($r['result'], JSON_THROW_ON_ERROR);
    }

    public function testContentDirOutsideAbspathIsWarned(): void
    {
        $content = $this->root . '-content';
        Fs::ensureDir($content);
        try {
            $site = new FixedSiteInfo(['home' => 'http://a.test', 'abspath' => $this->root, 'content_dir' => $content, 'prefix' => $this->prefix]);
            $job = new BackupJob($this->db, $this->store, $site, new Excludes(Excludes::defaults()), 3, 1024 * 1024, 64 * 1024);
            $r = $this->runJob($job, BackupJob::initialCursor('db'));
            $this->assertSame('done', $r['state'], (string) ($r['error'] ?? ''));
            $this->assertContains('wp-content is outside the WordPress folder and is not included in this backup: ' . $content, $r['result']['warnings']);
            $this->assertContains('wp-content is outside the WordPress folder and is not included in this backup: ' . $content,
                $this->store->load($r['result']['backup_id'])->warnings);
        } finally {
            Fs::rrmdir($content);
        }
    }
}
