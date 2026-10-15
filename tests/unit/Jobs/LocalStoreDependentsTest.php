<?php
namespace Dosya\Backup\Tests\unit\Jobs;

use Dosya\Backup\Jobs\LocalStore;
use Dosya\Backup\Support\Fs;
use PHPUnit\Framework\TestCase;

final class LocalStoreDependentsTest extends TestCase
{
    private string $dir;
    private LocalStore $s;
    /** @var array<string,string> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dosya-lsd-' . bin2hex(random_bytes(4));
        $this->s = new LocalStore($this->dir);
        $this->s->ensure();
        // A full; B reads A; C reads B (and A through the rows it copied from B); D an unrelated full
        foreach (['A', 'B', 'C', 'D'] as $k) {
            $this->ids[$k] = bin2hex(random_bytes(16));
        }
        $this->pub('A', 100, 'full', []);
        $this->pub('B', 200, 'incremental', [['b' => $this->ids['A'], 'v' => 1]]);
        $this->pub('C', 300, 'incremental', [['b' => $this->ids['B'], 'v' => 1]]);
        $this->pub('D', 400, 'full', []);
    }

    protected function tearDown(): void
    {
        Fs::rrmdir($this->dir);
    }

    private function pub(string $k, int $created, string $kind, array $refs): void
    {
        $id = $this->ids[$k];
        mkdir($this->s->backupDir($id) . '/volumes', 0777, true);
        file_put_contents($this->s->publicManifestPath($id), json_encode(['format' => 1, 'backup_id' => $id, 'created' => $created,
            'kind' => $kind, 'scope' => 'full', 'label' => '', 'volumes' => [['v' => 1, 'size' => $created, 'sha256' => 'x']], 'refs' => $refs]));
    }

    public function testDependentCountsBuildTheGraphOnce(): void
    {
        $counts = $this->s->dependentCounts($this->s->list());
        $this->assertEquals([$this->ids['A'] => 2, $this->ids['B'] => 1, $this->ids['C'] => 0, $this->ids['D'] => 0], $counts);
    }

    public function testDependentsFollowTheChainNewestFirst(): void
    {
        $this->assertSame([$this->ids['C'], $this->ids['B']], $this->s->dependents($this->ids['A']));
        $this->assertSame([$this->ids['C']], $this->s->dependents($this->ids['B']));
        $this->assertSame([], $this->s->dependents($this->ids['C']));
        $this->assertSame([], $this->s->dependents($this->ids['D']));
    }

    public function testDeleteIsRefusedForABase(): void
    {
        $this->assertSame(sprintf('This backup is the base of 2 newer backup(s): %s, %s. Delete those first.', $this->ids['C'], $this->ids['B']),
            $this->s->deleteRefusal($this->ids['A']));
        try {
            $this->s->deleteUnlessBase($this->ids['A']);
            $this->fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('This backup is the base of 2 newer backup(s)', $e->getMessage());
        }
        $this->assertDirectoryExists($this->s->backupDir($this->ids['A']));
        $this->assertNull($this->s->deleteRefusal($this->ids['C']));
        $this->s->deleteUnlessBase($this->ids['C']);
        $this->s->deleteUnlessBase($this->ids['B']);
        $this->s->deleteUnlessBase($this->ids['A']);
        $this->assertSame([$this->ids['D']], array_column($this->s->list(), 'id'));
    }

    public function testListAddsReferencedVolumesToBytesTotal(): void
    {
        $byId = array_column($this->s->list(), null, 'id');
        $this->assertSame(200, $byId[$this->ids['B']]['bytes_new']);
        $this->assertSame(300, $byId[$this->ids['B']]['bytes_total']);
        $this->assertSame(300, $byId[$this->ids['C']]['bytes_new']);
        $this->assertSame(500, $byId[$this->ids['C']]['bytes_total']);
        $this->assertSame(400, $byId[$this->ids['D']]['bytes_total']);
    }
}
