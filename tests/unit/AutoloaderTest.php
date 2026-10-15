<?php
namespace Dosya\Backup\Tests\unit;

use Dosya\Backup\Autoloader;
use PHPUnit\Framework\TestCase;

final class AutoloaderTest extends TestCase
{
    public function testResolvesClassesUnderTheSrcDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/dosya-autoload-' . bin2hex(random_bytes(4));
        mkdir($dir . '/Probe', 0777, true);
        file_put_contents($dir . '/Probe/Thing.php', "<?php namespace Dosya\\Backup\\Probe; final class Thing {}");
        require_once __DIR__ . '/../../src/Autoloader.php';
        Autoloader::register($dir);
        $this->assertTrue(class_exists('Dosya\\Backup\\Probe\\Thing'));
        $this->assertFalse(class_exists('Other\\Probe\\Thing'));
    }
}
