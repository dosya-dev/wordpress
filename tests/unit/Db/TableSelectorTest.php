<?php
namespace Dosya\Backup\Tests\unit\Db;

use Dosya\Backup\Db\TableSelector;
use PHPUnit\Framework\TestCase;

final class TableSelectorTest extends TestCase
{
    private const ALL = [
        'wp_options', 'wp_posts', 'wp_postmeta', 'wp_users', 'wp_wc_admin_options', 'wp_dosya_jobs',
        'wp_blog2_options', 'wp_blog2_posts', 'wp_blog2_postmeta',
        'wp_2_options', 'wp_2_posts', 'wp_2_postmeta', 'other_options',
    ];

    public function testForeignPrefixTablesAreExcluded(): void
    {
        $this->assertSame(
            ['wp_options', 'wp_posts', 'wp_postmeta', 'wp_users', 'wp_wc_admin_options', 'wp_2_options', 'wp_2_posts', 'wp_2_postmeta'],
            TableSelector::select(self::ALL, 'wp_', true, ['wp_dosya_jobs'])
        );
    }

    public function testNumericSubsitePrefixIsForeignOnSingleSite(): void
    {
        $this->assertNotContains('wp_2_posts', TableSelector::select(self::ALL, 'wp_', false));
    }
}
