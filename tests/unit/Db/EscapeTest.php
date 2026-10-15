<?php
namespace Dosya\Backup\Tests\unit\Db;

use Dosya\Backup\Db\Escape;
use PHPUnit\Framework\TestCase;

final class EscapeTest extends TestCase
{
    public function testEscapesEveryMysqlSpecialAndNoRawNewlinesSurvive(): void
    {
        $out = Escape::mysql("a'b\"c\\d\ne\rf\0g\x1Ah");
        $this->assertSame("a\\'b\\\"c\\\\d\\ne\\rf\\0g\\Zh", $out);
        $this->assertStringNotContainsString("\n", $out);
    }

    public function testLikeEscapesWildcards(): void
    {
        $this->assertSame('100\\%\\_x\\\\', Escape::like('100%_x\\'));
    }
}
