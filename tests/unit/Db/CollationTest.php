<?php
namespace Dosya\Backup\Tests\unit\Db;

use Dosya\Backup\Db\Collation;
use PHPUnit\Framework\TestCase;

final class CollationTest extends TestCase
{
    public function testDowngradesUnsupportedCollations(): void
    {
        $ddl = 'CREATE TABLE `x` (`a` varchar(10) COLLATE utf8mb4_0900_ai_ci) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
        [$out, $subs] = Collation::rewrite($ddl, ['utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8_general_ci']);
        $this->assertStringNotContainsString('0900', $out);
        $this->assertStringContainsString('utf8mb4_unicode_ci', $out);
        $this->assertSame(['utf8mb4_0900_ai_ci' => 'utf8mb4_unicode_ci'], $subs);
    }

    public function testMapsUtf8mb3ForOlderServers(): void
    {
        [$out] = Collation::rewrite('DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci', ['utf8_unicode_ci', 'utf8_general_ci']);
        $this->assertSame('DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci', $out);
    }

    public function testLeavesSupportedCollationsAlone(): void
    {
        [$out, $subs] = Collation::rewrite('COLLATE=utf8mb4_unicode_520_ci', ['utf8mb4_unicode_520_ci']);
        $this->assertSame('COLLATE=utf8mb4_unicode_520_ci', $out);
        $this->assertSame([], $subs);
    }
}
