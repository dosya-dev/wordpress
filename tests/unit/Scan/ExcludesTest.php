<?php
namespace Dosya\Backup\Tests\unit\Scan;

use Dosya\Backup\Scan\Excludes;
use PHPUnit\Framework\TestCase;

final class ExcludesTest extends TestCase
{
    public function testGlobSemantics(): void
    {
        $ex = new Excludes(['wp-content/cache/**', '**/.git/**', 'wp-content/wpvivid*/**', '*.tmp']);
        $this->assertTrue($ex->matches('wp-content/cache'));
        $this->assertTrue($ex->matches('wp-content/cache/a/b.html'));
        $this->assertFalse($ex->matches('wp-content/cache-other/x'));
        $this->assertTrue($ex->matches('wp-content/plugins/x/.git/HEAD'));
        $this->assertTrue($ex->matches('wp-content/wpvividbackups/f.zip'));
        $this->assertTrue($ex->matches('a.tmp'));
        $this->assertFalse($ex->matches('dir/a.tmp'));
    }

    public function testDefaultsDoNotExcludeCommonPluginFolders(): void
    {
        $ex = new Excludes(Excludes::defaults());
        $this->assertFalse($ex->matches('wp-content/plugins/woocommerce/src/Internal/core'));
        $this->assertTrue($ex->matches('wp-content/updraft/backup.zip'));
    }

    public function testBracketNegation(): void
    {
        $ex = new Excludes(['[!a]*.tmp']);
        $this->assertTrue($ex->matches('b.tmp'));
        $this->assertFalse($ex->matches('a.tmp'));
    }

    public function testEmptyClassIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Excludes(['[]']);
    }
}
