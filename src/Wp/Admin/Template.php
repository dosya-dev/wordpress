<?php
namespace Dosya\Backup\Wp\Admin;

/** Loads one admin template by name; the template sees the view model as $vm and only escapes and loops. */
final class Template
{
    public const NAMES = [
        'backups', 'restore', 'schedule', 'dosya',
        'partials/tabs', 'partials/status-card', 'partials/running-card', 'partials/backup-row',
        'partials/restore-dialog', 'partials/connect-card',
    ];

    public static function render(string $name, array $vm): void
    {
        if (!in_array($name, self::NAMES, true)) {
            throw new \InvalidArgumentException('Unknown template');
        }
        (static function (string $file, array $vm): void {
            include $file;
        })(__DIR__ . '/templates/' . $name . '.php', $vm);
    }
}
