<?php
namespace Dosya\Backup\Tests\unit\Restore;

use Dosya\Backup\Restore\MaintenanceGuard;
use Dosya\Backup\Support\Fs;
use PHPUnit\Framework\TestCase;

final class MaintenanceGuardTest extends TestCase
{
    public function testInstallCopiesTheShippedGuardAndRemoveClearsIt(): void
    {
        $content = sys_get_temp_dir() . '/mg-' . bin2hex(random_bytes(4));
        mkdir($content);
        $g = new MaintenanceGuard($content . '/mu-plugins', $content, null, 'dosya-backup-restore-migration/dosya-backup.php');
        $until = time() + 600;
        $g->install($until);
        $this->assertFileExists($g->muPluginPath());
        $this->assertFileEquals(MaintenanceGuard::shippedSource(), $g->muPluginPath(), 'the guard is copied, never generated');
        $this->assertSame([(string) $until, 'dosya-backup-restore-migration/dosya-backup.php'], file($g->lockPath(), FILE_IGNORE_NEW_LINES));
        exec('php -l ' . escapeshellarg($g->muPluginPath()), $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $g->remove();
        $this->assertFileDoesNotExist($g->muPluginPath());
        $this->assertFileDoesNotExist($g->lockPath());
        Fs::rrmdir($content);
    }

    public function testShippedGuardIsAMustUsePluginThatNeverRunsPhpFromAString(): void
    {
        $src = (string) file_get_contents(MaintenanceGuard::shippedSource());
        $this->assertStringContainsString('Plugin Name: dosya restore guard', $src);
        $this->assertStringContainsString("defined('ABSPATH')", $src);
        $this->assertStringContainsString('unlink(__FILE__)', $src, 'the guard removes itself once its deadline passes');
        $this->assertStringNotContainsString('eval(', $src);
    }

    public function testInstallFailsLoudlyWhenTheShippedGuardIsMissing(): void
    {
        $content = sys_get_temp_dir() . '/mg-' . bin2hex(random_bytes(4));
        mkdir($content);
        $g = new MaintenanceGuard($content . '/mu-plugins', $content, $content . '/missing.php');
        $this->expectException(\RuntimeException::class);
        try {
            $g->install(time() + 600);
        } finally {
            Fs::rrmdir($content);
        }
    }
}
