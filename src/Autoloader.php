<?php
namespace Dosya\Backup;

final class Autoloader
{
    public static function register(string $srcDir): void
    {
        $srcDir = rtrim($srcDir, '/');
        spl_autoload_register(static function (string $class) use ($srcDir): void {
            $prefix = 'Dosya\\Backup\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $file = $srcDir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
