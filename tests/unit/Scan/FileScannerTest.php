<?php
namespace Dosya\Backup\Tests\unit\Scan;

use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\PathCodec;
use Dosya\Backup\Scan\Excludes;
use Dosya\Backup\Scan\FileScanner;
use Dosya\Backup\Support\Budget;
use Dosya\Backup\Support\Fs;
use PHPUnit\Framework\TestCase;

final class FileScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/fs-' . bin2hex(random_bytes(4));
        foreach (['wp-content/uploads/2026', 'wp-content/cache/x', 'wp-includes'] as $d) {
            mkdir($this->root . '/' . $d, 0777, true);
        }
        file_put_contents($this->root . '/index.php', '<?php');
        file_put_contents($this->root . '/wp-includes/version.php', '<?php $v=1;');
        file_put_contents($this->root . "/wp-content/uploads/2026/caf\xE9.jpg", 'jpg');
        file_put_contents($this->root . '/wp-content/cache/x/page.html', 'cached');
        file_put_contents($this->root . '/wp-content/debug.log', str_repeat('L', 2048));
        symlink('uploads', $this->root . '/wp-content/link');
    }

    protected function tearDown(): void
    {
        Fs::rrmdir($this->root);
    }

    private function collect(Budget $budgetPerRun): array
    {
        $scanner = new FileScanner($this->root, new Excludes(Excludes::defaults()), 1024);
        $items = [];
        $cursor = FileScanner::start();
        $runs = 0;
        do {
            $cursor = $scanner->run($cursor, $budgetPerRun, function (array $i) use (&$items): void {
                $items[PathCodec::unpack($i)] = $i;
            }, function (string $w): void {});
            $cursor = json_decode(json_encode($cursor, JSON_THROW_ON_ERROR), true);
            $runs++;
        } while (empty($cursor['done']) && $runs < 1000);
        ksort($items, SORT_STRING);
        return $items;
    }

    public function testListsTreeHonouringExcludesAndLogRule(): void
    {
        $items = $this->collect(Budget::unlimited());
        $this->assertSame([
            'index.php', 'wp-content', 'wp-content/link', 'wp-content/uploads', 'wp-content/uploads/2026',
            "wp-content/uploads/2026/caf\xE9.jpg", 'wp-includes', 'wp-includes/version.php',
        ], array_keys($items));
        $this->assertSame('uploads', PathCodec::unpack($items['wp-content/link']['l']));
        $this->assertSame(3, $items["wp-content/uploads/2026/caf\xE9.jpg"]['s']);
    }

    public function testTinyBudgetsProduceTheSameList(): void
    {
        $tick = 0.0;
        $clock = function () use (&$tick): float { $tick += 1.0; return $tick; };
        $expected = array_keys($this->collect(Budget::unlimited()));
        $tiny = array_keys($this->collect(new Budget(1.5, 0, $clock)));
        $this->assertSame($expected, $tiny);
    }

    public function testNonUtf8DirectoryRoundTripsThroughTinyBudgetCursor(): void
    {
        $dir = "wp-content/uploads/d\xE9";
        mkdir($this->root . '/' . $dir, 0777, true);
        file_put_contents($this->root . '/' . $dir . '/f.txt', 'x');
        $tick = 0.0;
        $clock = function () use (&$tick): float { $tick += 1.0; return $tick; };
        $items = $this->collect(new Budget(1.5, 0, $clock));
        $this->assertArrayHasKey($dir . '/f.txt', $items);
        $this->assertSame(Format::TYPE_FILE, $items[$dir . '/f.txt']['t']);
    }

    public function testDanglingSymlinkIsEmittedWithItsTarget(): void
    {
        symlink('nowhere', $this->root . '/wp-content/dead');
        $items = $this->collect(Budget::unlimited());
        $this->assertArrayHasKey('wp-content/dead', $items);
        $this->assertSame(Format::TYPE_SYMLINK, $items['wp-content/dead']['t']);
        $this->assertSame('nowhere', PathCodec::unpack($items['wp-content/dead']['l']));
    }

    /** One name per run, so a deletion can be made between two runs. */
    private function scanOneNamePerRun(callable $afterRun): array
    {
        $scanner = new FileScanner($this->root, new Excludes(Excludes::defaults()), 1024);
        $budget = new Budget(0.0, 0, static function (): float { return 0.0; });
        $list = [];
        $cursor = FileScanner::start();
        $runs = 0;
        do {
            $cursor = $scanner->run($cursor, $budget, function (array $i) use (&$list): void {
                $list[] = PathCodec::unpack($i);
            }, function (string $w): void {});
            $cursor = json_decode(json_encode($cursor, JSON_THROW_ON_ERROR), true);
            $afterRun($list);
            $runs++;
        } while (empty($cursor['done']) && $runs < 1000);
        return $list;
    }

    public function testSiblingDeletedMidScanNeitherSkipsNorDuplicates(): void
    {
        foreach (['a.php', 'b.php', 'c.php'] as $f) {
            file_put_contents($this->root . '/wp-includes/' . $f, '<?php');
        }
        $deleted = false;
        $list = $this->scanOneNamePerRun(function (array $seen) use (&$deleted): void {
            if (!$deleted && in_array('wp-includes/a.php', $seen, true)) {
                unlink($this->root . '/wp-includes/b.php');
                $deleted = true;
            }
        });
        $this->assertTrue($deleted);
        $counts = array_count_values($list);
        $this->assertSame(1, $counts['wp-includes/c.php']);
        $this->assertSame(1, $counts['wp-includes/a.php']);
        $this->assertCount(count($counts), $list);
    }

    public function testEmittedSiblingDeletedMidScanKeepsTheNextOneListed(): void
    {
        foreach (['a.php', 'b.php', 'c.php'] as $f) {
            file_put_contents($this->root . '/wp-includes/' . $f, '<?php');
        }
        $deleted = false;
        $list = $this->scanOneNamePerRun(function (array $seen) use (&$deleted): void {
            if (!$deleted && in_array('wp-includes/a.php', $seen, true)) {
                unlink($this->root . '/wp-includes/a.php');
                $deleted = true;
            }
        });
        $counts = array_count_values($list);
        $this->assertSame(1, $counts['wp-includes/b.php']);
        $this->assertSame(1, $counts['wp-includes/c.php']);
        $this->assertCount(count($counts), $list);
    }
}
