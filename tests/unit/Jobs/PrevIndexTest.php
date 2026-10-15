<?php
namespace Dosya\Backup\Tests\unit\Jobs;

use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\Manifest;
use Dosya\Backup\Archive\PathCodec;
use Dosya\Backup\Jobs\PrevIndex;
use Dosya\Backup\Support\Fs;
use PHPUnit\Framework\TestCase;

final class PrevIndexTest extends TestCase
{
    private const B = '0123456789abcdef0123456789abcdef';
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dosya-pi-' . bin2hex(random_bytes(4));
        Fs::ensureDir($this->dir);
    }

    protected function tearDown(): void
    {
        Fs::rrmdir($this->dir);
    }

    private function row(string $path, int $t, int $s, int $mt, int $ho): array
    {
        return PathCodec::pack($path) + ['t' => $t, 's' => $s, 'h' => str_repeat('a', 64), 'm' => 0644, 'mt' => $mt,
            'loc' => ['b' => self::B, 'v' => 1, 'ho' => $ho]];
    }

    public function testFindsUnchangedFilesOnly(): void
    {
        $binary = "uploads/caf\xE9.txt";
        $rows = [];
        for ($i = 0; $i < 3000; $i++) {
            $rows[] = $this->row('wp-content/f' . $i . '.txt', Format::TYPE_FILE, $i, 1700000000 + $i, $i * 10);
        }
        $rows[] = $this->row($binary, Format::TYPE_FILE, 7, 1700000007, 99999);
        $rows[] = $this->row('wp-content', Format::TYPE_DIR, 0, 1700000000, 100000);
        $m = Manifest::fromEntries(['backup_id' => self::B, 'created' => 1, 'kind' => 'full', 'volumes' => []], $rows);
        $this->assertSame(3001, PrevIndex::build($m, $this->dir));
        $this->assertSame([], $m->files);

        $idx = PrevIndex::open($this->dir);
        $this->assertNotNull($idx);
        $this->assertSame($rows[1234], $idx->find('wp-content/f1234.txt', 1234, 1700001234));
        $this->assertSame($rows[3000], $idx->find($binary, 7, 1700000007));
        $this->assertNull($idx->find('wp-content/f1234.txt', 1235, 1700001234), 'size changed');
        $this->assertNull($idx->find('wp-content/f1234.txt', 1234, 1700001235), 'mtime changed');
        $this->assertNull($idx->find('wp-content/new.txt', 0, 1700000000), 'new file');
        $this->assertNull($idx->find('wp-content', 0, 1700000000), 'directories are never reused');
    }

    public function testMissingSidecarOpensAsNull(): void
    {
        $this->assertNull(PrevIndex::open($this->dir));
        $m = Manifest::fromEntries(['backup_id' => self::B, 'created' => 1, 'kind' => 'full', 'volumes' => []], []);
        $this->assertSame(0, PrevIndex::build($m, $this->dir));
        $idx = PrevIndex::open($this->dir);
        $this->assertNotNull($idx);
        $this->assertNull($idx->find('a.txt', 1, 1));
    }
}
