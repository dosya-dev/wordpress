<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.AlternativeFunctions.unlink_unlink -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI); unlink removes the guard files, and this engine code must not call WordPress functions.
namespace Dosya\Backup\Restore;

use Dosya\Backup\Jobs\RestoreJob;
use Dosya\Backup\Support\Fs;

/**
 * Keeps visitors and other plugins out while a restore runs.
 *
 * The guard is a must-use plugin that ships with this plugin as a reviewable file (mu-plugin/dosya-restore-guard.php).
 * install() copies that file as it is and writes the lock file the guard reads: its deadline and the basename of the
 * plugin that may keep loading. The guard removes itself when the deadline passes; remove() removes it when the restore ends.
 */
final class MaintenanceGuard
{
    public const FILE = 'dosya-restore-guard.php';

    private string $muDir;
    private string $contentDir;
    private string $source;
    private string $keep;

    /**
     * @param string|null $source the shipped guard file; null means the one beside this plugin's source
     * @param string $keep basename of the plugin that keeps loading while the guard is up
     */
    public function __construct(string $muPluginsDir, string $contentDir, ?string $source = null, string $keep = RestoreJob::PLUGIN)
    {
        $this->muDir = rtrim($muPluginsDir, '/');
        $this->contentDir = rtrim($contentDir, '/');
        $this->source = $source ?? self::shippedSource();
        $this->keep = $keep;
    }

    /** The guard file as shipped inside the plugin. */
    public static function shippedSource(): string
    {
        return dirname(__DIR__, 2) . '/mu-plugin/' . self::FILE;
    }

    public function muPluginPath(): string { return $this->muDir . '/' . self::FILE; }
    public function lockPath(): string { return $this->contentDir . '/dosya-restore.lock'; }

    public function install(int $until): void
    {
        if (!is_file($this->source)) {
            throw new \RuntimeException('The restore guard is missing from the plugin: ' . $this->source);
        }
        Fs::ensureDir($this->muDir);
        if (file_put_contents($this->lockPath(), $until . "\n" . $this->keep . "\n") === false
            || (!is_file($this->muPluginPath()) && !copy($this->source, $this->muPluginPath()))) {
            throw new \RuntimeException('Cannot install the restore guard (is wp-content writable?)');
        }
    }

    public function remove(): void
    {
        foreach ([$this->muPluginPath(), $this->lockPath()] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }
}
