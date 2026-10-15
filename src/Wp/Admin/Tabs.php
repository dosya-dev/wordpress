<?php
namespace Dosya\Backup\Wp\Admin;

/** The four admin tabs. Pure: no WordPress calls. */
final class Tabs
{
    public const ALL = ['backups', 'restore', 'schedule', 'dosya'];

    /** The tab named by the query argument, or the Backups tab for anything unknown. */
    public static function resolve(?string $query): string
    {
        return $query !== null && in_array($query, self::ALL, true) ? $query : 'backups';
    }
}
