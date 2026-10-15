<?php
namespace Dosya\Backup\Tests\unit\Jobs;

use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\Manifest;
use Dosya\Backup\Jobs\FixedSiteInfo;
use Dosya\Backup\Jobs\RestorePlan;
use PHPUnit\Framework\TestCase;

final class RestorePlanTest extends TestCase
{
    private function manifest(): Manifest
    {
        return Manifest::fromArray(['format' => 1, 'meta' => ['backup_id' => str_repeat('a', 32), 'created' => 1, 'kind' => 'full',
            'site' => ['home' => 'http://old.test', 'siteurl' => 'http://old.test', 'abspath' => '/var/www/old/', 'prefix' => 'wp_'], 'volumes' => []],
            'files' => [['p' => 'index.php', 't' => 1]], 'tables' => ['wp_posts' => ['schema' => ['b' => 'x', 'v' => 1, 'ho' => 64], 'parts' => []]],
            'views' => [], 'triggers' => [], 'warnings' => []]);
    }

    private function site(string $home, string $abs = '/var/www/old'): FixedSiteInfo
    {
        return new FixedSiteInfo(['home' => $home, 'abspath' => $abs, 'content_dir' => $abs . '/wp-content', 'prefix' => 'wpx_']);
    }

    public function testSameSiteRestoreIsNotAMigration(): void
    {
        $plan = RestorePlan::build($this->manifest(), $this->site('http://old.test/'), []);
        $this->assertFalse($plan['migration']);
        $this->assertSame([], $plan['pairs']);
        $this->assertSame('wpx_', $plan['new']['prefix']);
    }

    public function testProtectedPathsAreNeverRestored(): void
    {
        $same = RestorePlan::selector(RestorePlan::build($this->manifest(), $this->site('http://old.test'), []));
        $moved = RestorePlan::selector(RestorePlan::build($this->manifest(), $this->site('https://new.test', '/srv/new'), []));
        foreach ([$same, $moved] as $sel) {
            $this->assertFalse($sel('wp-config.php', Format::TYPE_FILE));
            $this->assertFalse($sel('wp-content/plugins/dosya-backup-restore-migration/dosya-backup.php', Format::TYPE_FILE));
            $this->assertFalse($sel('wp-content/mu-plugins/dosya-restore-guard.php', Format::TYPE_FILE));
            $this->assertTrue($sel('wp-content/plugins/akismet/akismet.php', Format::TYPE_FILE));
        }
        $this->assertTrue($same('.htaccess', Format::TYPE_FILE));
        foreach (['.htaccess', '.user.ini', 'php.ini', 'web.config'] as $f) {
            $this->assertFalse($moved($f, Format::TYPE_FILE), $f);
        }
        $this->assertTrue($moved('wp-content/uploads/.htaccess', Format::TYPE_FILE));
    }

    public function testMigrationBuildsUrlAndPathPairs(): void
    {
        $plan = RestorePlan::build($this->manifest(), $this->site('https://new.test', '/srv/new'), []);
        $this->assertTrue($plan['migration']);
        $this->assertSame('https://new.test', $plan['pairs']['http://old.test']);
        $this->assertSame('/srv/new', $plan['pairs']['/var/www/old']);
    }

    public function testScopeAndPathSelection(): void
    {
        $plan = RestorePlan::build($this->manifest(), $this->site('http://old.test'), ['scope' => 'files', 'paths' => ['wp-content/uploads/**']]);
        $sel = RestorePlan::selector($plan);
        $this->assertTrue($sel('wp-content/uploads/a.jpg', Format::TYPE_FILE));
        $this->assertFalse($sel('index.php', Format::TYPE_FILE));
        $this->assertTrue($sel('wp-content', Format::TYPE_DIR));
        $this->assertFalse($plan['snapshot']);
        $db = RestorePlan::selector(RestorePlan::build($this->manifest(), $this->site('http://old.test'), ['scope' => 'db']));
        $this->assertFalse($db('index.php', Format::TYPE_FILE));
    }
}
