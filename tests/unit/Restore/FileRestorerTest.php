<?php
namespace Dosya\Backup\Tests\unit\Restore;

use Dosya\Backup\Archive\Format;
use Dosya\Backup\Restore\FileRestorer;
use Dosya\Backup\Support\Budget;
use Dosya\Backup\Support\Fs;
use Dosya\Backup\Tests\unit\Support\ArchiveFixture;
use PHPUnit\Framework\TestCase;

final class FileRestorerTest extends TestCase
{
    private string $tmp;
    private string $root;
    private string $outside;
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/fr-' . bin2hex(random_bytes(4));
        $this->root = $this->tmp . '/site';
        $this->outside = $this->tmp . '/outside';
        mkdir($this->root, 0777, true);
        mkdir($this->outside, 0777, true);
    }

    protected function tearDown(): void
    {
        Fs::rrmdir($this->tmp);
    }

    private function restore(array $entries, ?callable $selects = null, ?Budget $budget = null): void
    {
        [$m, $reader] = ArchiveFixture::build($this->tmp . '/vol', $entries);
        $fr = new FileRestorer($this->root, $this->root . '/wp-content/dosya-restore-staging/j1', $m, $reader,
            $selects ?? function (): bool { return true; });
        $warn = function (string $w): void { $this->warnings[] = $w; };
        $c = FileRestorer::start();
        for ($i = 0; $i < 10000 && empty($c['done']); $i++) {
            $c = $fr->fetch($c, $budget ?? Budget::unlimited(), $warn);
            $c = json_decode(json_encode($c, JSON_THROW_ON_ERROR), true);
        }
        for ($i = 0; $i < 10000 && empty($c['moved']); $i++) {
            $c = $fr->move($c, $budget ?? Budget::unlimited(), $warn);
        }
        $fr->cleanup();
    }

    public function testRestoresFilesByteExactAcrossTinySteps(): void
    {
        $big = random_bytes(10 * 1024 + 3);
        $t = 0.0;
        $clock = function () use (&$t): float { $t += 1.0; return $t; };
        $this->restore([
            'wp-content' => ['t' => Format::TYPE_DIR],
            'wp-content/big.bin' => ['t' => Format::TYPE_FILE, 'data' => $big, 'mt' => 1600000000],
            "wp-content/caf\xE9.txt" => ['t' => Format::TYPE_FILE, 'data' => 'x'],
            'empty.txt' => ['t' => Format::TYPE_FILE, 'data' => ''],
        ], null, new Budget(1.5, 0, $clock));
        $this->assertSame($big, file_get_contents($this->root . '/wp-content/big.bin'));
        $this->assertSame(1600000000, filemtime($this->root . '/wp-content/big.bin'));
        $this->assertSame('x', file_get_contents($this->root . "/wp-content/caf\xE9.txt"));
        $this->assertSame('', file_get_contents($this->root . '/empty.txt'));
        $this->assertDirectoryDoesNotExist($this->root . '/wp-content/dosya-restore-staging/j1');
    }

    public function testSelectorIsHonoured(): void
    {
        file_put_contents($this->root . '/wp-config.php', 'TARGET CONFIG');
        $this->restore([
            'wp-config.php' => ['t' => Format::TYPE_FILE, 'data' => 'SOURCE CONFIG'],
            'index.php' => ['t' => Format::TYPE_FILE, 'data' => 'idx'],
        ], function (string $path): bool { return $path !== 'wp-config.php'; });
        $this->assertSame('TARGET CONFIG', file_get_contents($this->root . '/wp-config.php'));
        $this->assertSame('idx', file_get_contents($this->root . '/index.php'));
    }

    public function testArchivedSymlinkCannotRedirectLaterFiles(): void
    {
        $this->restore([
            'evil' => ['t' => Format::TYPE_SYMLINK, 'data' => $this->outside],
            'evil/pwned.txt' => ['t' => Format::TYPE_FILE, 'data' => 'gotcha'],
            'ok-link' => ['t' => Format::TYPE_SYMLINK, 'data' => 'index.php'],
            'index.php' => ['t' => Format::TYPE_FILE, 'data' => 'i'],
        ]);
        $this->assertFileDoesNotExist($this->outside . '/pwned.txt');
        $this->assertFalse(is_link($this->root . '/evil'));
        $this->assertSame('index.php', readlink($this->root . '/ok-link'));
        $this->assertNotEmpty($this->warnings);
    }

    public function testExistingSymlinkPointingOutsideIsNotWrittenThrough(): void
    {
        symlink($this->outside, $this->root . '/up');
        $this->restore(['up/x.txt' => ['t' => Format::TYPE_FILE, 'data' => 'nope']]);
        $this->assertFileDoesNotExist($this->outside . '/x.txt');
        $this->assertStringContainsString('up/x.txt', implode("\n", $this->warnings));
    }

    public function testLoneSymlinkToOutsideIsRefused(): void
    {
        $this->restore([
            'bad' => ['t' => Format::TYPE_SYMLINK, 'data' => $this->outside],
            'bad2' => ['t' => Format::TYPE_SYMLINK, 'data' => '../../outside'],
        ]);
        $this->assertFalse(is_link($this->root . '/bad'));
        $this->assertFalse(is_link($this->root . '/bad2'));
        $all = implode("\n", $this->warnings);
        $this->assertStringContainsString('bad', $all);
        $this->assertStringContainsString('bad2', $all);
        $this->assertCount(2, $this->warnings);
    }

    public function testChainedSymlinksCannotEscape(): void
    {
        $this->restore([
            'x' => ['t' => Format::TYPE_SYMLINK, 'data' => '.'],
            'y' => ['t' => Format::TYPE_SYMLINK, 'data' => 'x/x/../../escape.txt'],
        ]);
        $this->assertTrue(is_link($this->root . '/x'));
        $this->assertFalse(is_link($this->root . '/y'));
        $this->assertFileDoesNotExist($this->tmp . '/escape.txt');
        $this->assertNotEmpty($this->warnings);
    }

    public function testAbsoluteInsideAndDanglingInsideTargetsAreAccepted(): void
    {
        $abs = (string) realpath($this->root) . '/index.php';
        $this->restore([
            'abs' => ['t' => Format::TYPE_SYMLINK, 'data' => $abs],
            'dangling' => ['t' => Format::TYPE_SYMLINK, 'data' => 'nowhere.txt'],
            'index.php' => ['t' => Format::TYPE_FILE, 'data' => 'i'],
        ]);
        $this->assertSame($abs, readlink($this->root . '/abs'));
        $this->assertSame('nowhere.txt', readlink($this->root . '/dangling'));
        $this->assertSame([], $this->warnings);
    }

    public function testExistingDirectorySymlinkIsKept(): void
    {
        mkdir($this->root . '/real-uploads');
        mkdir($this->root . '/wp-content');
        symlink($this->root . '/real-uploads', $this->root . '/wp-content/uploads');
        $this->restore([
            'wp-content/uploads' => ['t' => Format::TYPE_DIR],
            'wp-content/uploads/a.txt' => ['t' => Format::TYPE_FILE, 'data' => 'AAA'],
        ]);
        $this->assertTrue(is_link($this->root . '/wp-content/uploads'));
        $this->assertSame('AAA', file_get_contents($this->root . '/real-uploads/a.txt'));
        $this->assertStringContainsString('Kept existing link', implode("\n", $this->warnings));
    }

    public function testUnsafePathIsSkippedNotFatal(): void
    {
        $this->restore([
            '../escape.txt' => ['t' => Format::TYPE_FILE, 'data' => 'bad'],
            'ok.txt' => ['t' => Format::TYPE_FILE, 'data' => 'ok'],
        ]);
        $this->assertSame('ok', file_get_contents($this->root . '/ok.txt'));
        $this->assertFileDoesNotExist($this->tmp . '/escape.txt');
        $this->assertStringContainsString('../escape.txt', implode("\n", $this->warnings));
    }

    public function testLinkOrderCannotEscape(): void
    {
        file_put_contents($this->tmp . '/escape.txt', 'secret');
        $this->restore([
            'a' => ['t' => Format::TYPE_SYMLINK, 'data' => 'b/../escape.txt'],
            'b' => ['t' => Format::TYPE_SYMLINK, 'data' => '.'],
        ]);
        $this->assertFalse(is_link($this->root . '/a'));
        $this->assertTrue(is_link($this->root . '/b'));
        $this->assertMatchesRegularExpression('/\ba\b/', implode("\n", $this->warnings));
    }
}
