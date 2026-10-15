<?php
namespace Dosya\Backup\Tests\unit\Db;

use Dosya\Backup\Db\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaExtraTest extends TestCase
{
    /** @dataProvider extras */
    public function testIsGeneratedExtra(string $extra, bool $expected): void
    {
        $this->assertSame($expected, Schema::isGeneratedExtra($extra));
    }

    public function extras(): array
    {
        return [
            'empty' => ['', false],
            'auto_increment' => ['auto_increment', false],
            'default generated' => ['DEFAULT_GENERATED', false],
            'default generated on update' => ['DEFAULT_GENERATED on update CURRENT_TIMESTAMP', false],
            'on update' => ['on update CURRENT_TIMESTAMP', false],
            'virtual generated' => ['VIRTUAL GENERATED', true],
            'stored generated' => ['STORED GENERATED', true],
            'persistent' => ['PERSISTENT', true],
            'virtual' => ['VIRTUAL', true],
        ];
    }
}
