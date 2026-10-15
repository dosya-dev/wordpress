<?php
namespace Dosya\Backup\Tests\unit\Support;

use Dosya\Backup\Support\PathGuard;
use PHPUnit\Framework\TestCase;

final class PathGuardTest extends TestCase
{
    public function testResolvesNormalPaths(): void
    {
        $this->assertSame('/r/a/b', PathGuard::resolve('/r/', 'a/./b'));
        $this->assertSame('/r/a/b', PathGuard::resolve('/r', 'a//b/'));
    }

    /** @dataProvider badPaths */
    public function testRejectsEscapes(string $rel): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PathGuard::resolve('/r', $rel);
    }

    public function badPaths(): array
    {
        return [['../etc/passwd'], ['a/../../x'], ['/etc/passwd'], ['C:/x'], ["a\0b"], [''], ['.']];
    }

    public function testNormalizeAndInside(): void
    {
        $this->assertSame('/a/c', PathGuard::normalize('/a/b/../c/.'));
        $this->assertTrue(PathGuard::inside('/r/x', '/r'));
        $this->assertFalse(PathGuard::inside('/rx', '/r'));
    }

    public function testResolvesInsidePhysically(): void
    {
        $tmp = (string) realpath(sys_get_temp_dir()) . '/pg-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/site', 0777, true);
        $root = $tmp . '/site';
        try {
            symlink('.', $root . '/x');
            $this->assertFalse(PathGuard::resolvesInside($root . '/y', 'x/x/../../escape.txt', $root));
            $this->assertFalse(PathGuard::resolvesInside($root . '/y', '../outside', $root));
            $this->assertFalse(PathGuard::resolvesInside($root . '/y', $tmp, $root));
            $this->assertTrue(PathGuard::resolvesInside($root . '/y', 'nowhere.txt', $root));
            $this->assertTrue(PathGuard::resolvesInside($root . '/y', 'x/sub/nowhere.txt', $root));
            $this->assertTrue(PathGuard::resolvesInside($root . '/y', $root . '/a', $root));
            $this->assertFalse(PathGuard::resolvesInside($root . '/y', 'missing/../x', $root));
            $this->assertTrue(PathGuard::resolvesInside($root . '/y', 'missing/x', $root));
        } finally {
            unlink($root . '/x');
            rmdir($root);
            rmdir($tmp);
        }
    }
}
