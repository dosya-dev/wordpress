<?php
namespace Dosya\Backup\Jobs;

use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\Manifest;
use Dosya\Backup\Db\UrlPairs;
use Dosya\Backup\Scan\Excludes;

final class RestorePlan
{
    public const ALWAYS_PROTECTED = [
        'wp-config.php', 'wp-content/plugins/dosya-backup-restore-migration/**', 'wp-content/mu-plugins/dosya-restore-guard.php',
        'wp-content/dosya-restore.lock', 'wp-content/dosya-backup-local/**', 'wp-content/dosya-restore-staging/**',
    ];
    public const MIGRATION_PROTECTED = ['.htaccess', '.user.ini', 'php.ini', 'web.config'];

    public static function build(Manifest $m, SiteInfo $target, array $opts): array
    {
        $scope = $opts['scope'] ?? 'full';
        if (!in_array($scope, ['full', 'db', 'files'], true)) {
            throw new \InvalidArgumentException('scope must be full, db or files');
        }
        if ($scope === 'db' && !$m->tables) {
            throw new \InvalidArgumentException('This backup has no database.');
        }
        if ($scope === 'files' && !$m->files) {
            throw new \InvalidArgumentException('This backup has no files.');
        }
        $site = $m->meta['site'];
        $oldHome = rtrim((string) $site['home'], '/');
        $newHome = rtrim((string) ($opts['new_url'] ?? $target->homeUrl()), '/');
        $oldAbs = rtrim((string) $site['abspath'], '/');
        $newAbs = rtrim($target->absPath(), '/');
        $migration = strcasecmp($oldHome, $newHome) !== 0 || $oldAbs !== $newAbs;
        $pairs = [];
        if ($migration) {
            $pairs = UrlPairs::build($oldHome, $newHome, $oldAbs, $newAbs);
            $oldSite = rtrim((string) ($site['siteurl'] ?? $oldHome), '/');
            $newSite = rtrim($target->siteUrl() ?: $newHome, '/');
            if ($oldSite !== $oldHome) {
                $pairs = UrlPairs::build($oldSite, $newSite) + $pairs;
            }
        }
        return [
            'backup_id' => (string) $m->meta['backup_id'],
            'scope' => $scope,
            'tables' => isset($opts['tables']) ? array_values($opts['tables']) : null,
            'paths' => isset($opts['paths']) ? array_values($opts['paths']) : null,
            'migration' => $migration,
            'old' => ['home' => $oldHome, 'abspath' => $oldAbs, 'prefix' => (string) $site['prefix']],
            'new' => ['home' => $newHome, 'abspath' => $newAbs, 'prefix' => $target->tablePrefix()],
            'pairs' => $pairs,
            'protected' => $migration ? array_merge(self::ALWAYS_PROTECTED, self::MIGRATION_PROTECTED) : self::ALWAYS_PROTECTED,
            'snapshot' => ($opts['snapshot'] ?? true) && $scope !== 'files',
            'health' => (bool) ($opts['health'] ?? true),
        ];
    }

    public static function selector(array $plan): callable
    {
        $protected = new Excludes($plan['protected']);
        $only = $plan['paths'] ? new Excludes($plan['paths']) : null;
        return function (string $path, int $type) use ($plan, $protected, $only): bool {
            if ($plan['scope'] === 'db' || $protected->matches($path)) {
                return false;
            }
            return $only === null || $only->matches($path) || $type === Format::TYPE_DIR;
        };
    }
}
