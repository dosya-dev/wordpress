<?php
namespace Dosya\Backup\Tests\unit\Wp\Admin;

use Dosya\Backup\Wp\Admin\Tabs;
use PHPUnit\Framework\TestCase;

final class TabsTest extends TestCase
{
    public function testKnownTabsResolveToThemselves(): void
    {
        foreach (['backups', 'restore', 'schedule', 'dosya'] as $tab) {
            self::assertSame($tab, Tabs::resolve($tab));
        }
    }

    public function testUnknownEmptyAndNullFallBackToBackups(): void
    {
        self::assertSame('backups', Tabs::resolve(null));
        self::assertSame('backups', Tabs::resolve(''));
        self::assertSame('backups', Tabs::resolve('settings'));
        self::assertSame('backups', Tabs::resolve('Backups'));
        self::assertSame('backups', Tabs::resolve('../dosya'));
    }

    public function testAllListsTheFourTabsInOrder(): void
    {
        self::assertSame(['backups', 'restore', 'schedule', 'dosya'], Tabs::ALL);
    }
}
