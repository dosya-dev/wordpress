<?php
namespace Dosya\Backup\Tests\unit\Support;

use Dosya\Backup\Support\Budget;
use PHPUnit\Framework\TestCase;

final class BudgetTest extends TestCase
{
    public function testExhaustsAtTheDeadline(): void
    {
        $t = 100.0;
        $b = new Budget(2.0, 0, function () use (&$t): float { return $t; });
        $this->assertFalse($b->exhausted());
        $t = 101.9;
        $this->assertFalse($b->exhausted());
        $t = 102.0;
        $this->assertTrue($b->exhausted());
    }

    public function testParsesMemoryLimits(): void
    {
        $this->assertSame(128 * 1024 * 1024, Budget::memoryLimitBytes('128M'));
        $this->assertSame(1024 * 1024 * 1024, Budget::memoryLimitBytes('1G'));
        $this->assertSame(0, Budget::memoryLimitBytes('-1'));
    }
}
