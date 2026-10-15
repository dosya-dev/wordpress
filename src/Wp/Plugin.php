<?php
namespace Dosya\Backup\Wp;

use Dosya\Backup\Archive\Format;
use Dosya\Backup\Connect\Connection;
use Dosya\Backup\Connect\DosyaClient;
use Dosya\Backup\Db\Db;
use Dosya\Backup\Db\MysqliConnection;
use Dosya\Backup\Jobs\BackupJob;
use Dosya\Backup\Jobs\JobStore;
use Dosya\Backup\Jobs\LocalStore;
use Dosya\Backup\Jobs\RestoreJob;
use Dosya\Backup\Jobs\RestorePlan;
use Dosya\Backup\Jobs\SiteInfo;
use Dosya\Backup\Jobs\Steppable;
use Dosya\Backup\Jobs\StepRunner;
use Dosya\Backup\Remote\RemoteStage;
use Dosya\Backup\Remote\RemoteStore;
use Dosya\Backup\Remote\RemoteUploader;
use Dosya\Backup\Restore\MaintenanceGuard;
use Dosya\Backup\Scan\Excludes;

final class Plugin
{
    public const BASENAME = 'dosya-backup-restore-migration/dosya-backup.php';
    private static ?Db $db = null;
    private static ?string $initError = null;

    public static function boot(): void
    {
        register_activation_hook(DOSYA_BACKUP_FILE, [self::class, 'activate']);
        register_deactivation_hook(DOSYA_BACKUP_FILE, [self::class, 'deactivate']);
        add_action('plugins_loaded', [self::class, 'init']);
    }

    /** Why the plugin could not start on this request, or null when it started. */
    public static function initError(): ?string
    {
        return self::$initError;
    }

    public static function supported(): bool
    {
        return isset($GLOBALS['wpdb']) && $GLOBALS['wpdb']->dbh instanceof \mysqli;
    }

    /** Why this site cannot run the plugin at all (shown instead of failing), or null when it can. */
    public static function unsupportedReason(): ?string
    {
        if (PHP_INT_SIZE < 8) {
            return __('dosya Backup needs 64-bit PHP. This server runs 32-bit PHP, so the plugin stays idle.', 'dosya-backup-restore-migration');
        }
        if (!self::supported()) {
            return __('dosya Backup needs a MySQL or MariaDB database. This site uses another database, so the plugin stays idle.', 'dosya-backup-restore-migration');
        }
        return null;
    }

    /**
     * Activation prints nothing and never dies: on a site the plugin cannot serve, init() explains why on the Plugins screen
     * and on the plugin's own page; a failed table install is retried and reported by init() the same way.
     */
    public static function activate(): void
    {
        if (self::unsupportedReason() !== null) {
            return;
        }
        try {
            self::jobs()->install();
            if (Settings::read(Settings::OPTION, null) === null) {
                // a new install really has the daily schedule the settings page shows
                Settings::write(Settings::OPTION, SettingsSchema::DEFAULTS);
            }
            Scheduler::sync(Settings::get());
            Settings::write('dosya_backup_db_version', DOSYA_BACKUP_VERSION);
        } catch (\Throwable $e) {
            // the version option stays behind, so init() installs again on the next request and shows the error if it persists
            return;
        }
    }

    /** Leaves no scheduled backups or queued steps behind. */
    public static function deactivate(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(Scheduler::SCHEDULED, [], Scheduler::GROUP);
            as_unschedule_all_actions(Scheduler::STEP, [], Scheduler::GROUP);
        }
    }

    public static function init(): void
    {
        if (is_admin()) {
            // registered first so the plugin page can show why the plugin could not start
            Admin\AdminPage::register();
        }
        $why = self::unsupportedReason();
        if ($why !== null) {
            self::$initError = $why;
            if (is_admin()) {
                add_action('admin_notices', [self::class, 'renderInitError']);
                add_action('network_admin_notices', [self::class, 'renderInitError']);
            }
            return;
        }
        try {
            if (Settings::read('dosya_backup_db_version', '') !== DOSYA_BACKUP_VERSION) {
                self::jobs()->install();
                if (Settings::read(Settings::OPTION, null) === null) {
                    // a site that never saved settings must not gain daily backups from the new default
                    Settings::write(Settings::OPTION, array_merge(SettingsSchema::DEFAULTS, ['schedule' => 'off']));
                }
                Settings::write('dosya_backup_db_version', DOSYA_BACKUP_VERSION);
            }
        } catch (\Throwable $e) {
            // a failed table install must never take the site down; the plugin stays idle on this request
            self::$initError = $e->getMessage();
            return;
        }
        Scheduler::register();
        if (is_admin()) {
            Admin\Ajax::register();
            Admin\Download::register();
            Admin\Connect::register();
            Admin\Notices::register();
        }
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('dosya', Cli::class);
        }
    }

    /** On the Plugins screen only: why the plugin is idle on this site. The plugin's own page says the same. */
    public static function renderInitError(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (self::$initError === null || $screen === null || !in_array($screen->id, ['plugins', 'plugins-network'], true) || !current_user_can(self::capability())) {
            return;
        }
        wp_admin_notice(esc_html(self::$initError), ['type' => 'error', 'paragraph_wrap' => true]);
    }

    public static function capability(): string
    {
        return is_multisite() ? 'manage_network_options' : 'manage_options';
    }

    public static function db(): Db
    {
        if (self::$db === null) {
            global $wpdb;
            if (!($wpdb->dbh instanceof \mysqli)) {
                throw new \RuntimeException(esc_html__('dosya Backup needs a MySQL or MariaDB database connection.', 'dosya-backup-restore-migration'));
            }
            self::$db = new MysqliConnection($wpdb->dbh, (string) $wpdb->base_prefix, (string) ($wpdb->charset ?: 'utf8mb4'));
        }
        return self::$db;
    }

    public static function apiBase(): string
    {
        if (defined('DOSYA_BACKUP_API_BASE') && is_string(DOSYA_BACKUP_API_BASE) && DOSYA_BACKUP_API_BASE !== '') {
            return rtrim(DOSYA_BACKUP_API_BASE, '/');
        }
        // The `dosya_backup_api_base` option is a test/dev override (the e2e lane
        // points the plugin at its fake server; bin/e2e does it through the
        // DOSYA_BACKUP_API_BASE constant above, set in WORDPRESS_CONFIG_EXTRA).
        // It is honoured only under WP_DEBUG, so a stray or injected option row
        // cannot redirect a production site's key and backups to another host.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $v = Settings::read(Settings::API_BASE_OPTION, Connection::DEFAULT_BASE_URL);
            if (is_string($v) && $v !== '') return rtrim($v, '/');
        }
        return rtrim(Connection::DEFAULT_BASE_URL, '/');
    }

    public static function webBase(): string
    {
        return rtrim(defined('DOSYA_BACKUP_WEB_BASE') && is_string(DOSYA_BACKUP_WEB_BASE) && DOSYA_BACKUP_WEB_BASE !== '' ? DOSYA_BACKUP_WEB_BASE : 'https://dosya.dev', '/');
    }

    public static function userAgent(): string
    {
        global $wp_version;
        return 'dosya-backup/' . DOSYA_BACKUP_VERSION . ' (WordPress ' . (string) $wp_version . ')';
    }

    /** The saved connection, or null when not connected or the saved key cannot be opened (see SiteKeyStore::state). */
    public static function connection(): ?Connection
    {
        return (new SiteKeyStore())->load();
    }

    /** An authenticated client, or null when there is no readable connection. */
    public static function api(): ?DosyaClient
    {
        $c = self::connection();
        return $c === null ? null : new DosyaClient(new WpHttpClient(), $c->baseUrl, $c->siteKey, self::userAgent());
    }

    /** A client without a site key, for the connect exchange. */
    public static function anonymousApi(): DosyaClient
    {
        return new DosyaClient(new WpHttpClient(), self::apiBase(), null, self::userAgent());
    }

    public static function connector(): Connector { return new Connector(); }

    public static function jobs(): JobStore { return new JobStore(self::db()); }
    public static function store(): LocalStore { return new LocalStore((string) apply_filters('dosya_backup_local_dir', Settings::localDir())); }
    public static function site(): SiteInfo { return new WpSiteInfo(); }
    public static function guard(): MaintenanceGuard
    {
        return new MaintenanceGuard(WPMU_PLUGIN_DIR, WP_CONTENT_DIR, DOSYA_BACKUP_DIR . '/mu-plugin/' . MaintenanceGuard::FILE, plugin_basename(DOSYA_BACKUP_FILE));
    }

    public static function runner(): StepRunner
    {
        return new StepRunner(self::db(), self::jobs(), [self::class, 'makeJob']);
    }

    public static function makeJob(array $job): Steppable
    {
        $s = Settings::get();
        if ($job['kind'] === 'backup') {
            return new BackupJob(self::db(), self::store(), self::site(), new Excludes(array_merge(Excludes::defaults(), $s['excludes'])),
                (int) $s['keep'], (int) $s['volume_mb'] * 1048576, Format::BLOCK_MAX, [], self::remoteStage());
        }
        if ($job['kind'] === 'restore') {
            return new RestoreJob(self::db(), self::store(), self::site(), self::guard(), [self::class, 'httpGet'], [self::class, 'afterSwap'],
                (int) $s['keep']);
        }
        throw new \InvalidArgumentException('Unknown job kind');
    }

    /** The upload-to-dosya stage, or null when the site is not connected (backups then stay local only). */
    public static function remoteStage(): ?RemoteStage
    {
        $conn = self::connection();
        if ($conn === null) {
            return null;
        }
        $api = new DosyaClient(new WpHttpClient(), $conn->baseUrl, $conn->siteKey, self::userAgent());
        return new RemoteStage($api, new RemoteUploader($api, $conn->workspaceId), self::remoteStore(), $conn->siteId);
    }

    public static function remoteStore(): RemoteStore { return new RemoteStore(self::store()->dir()); }

    public static function startBackup(string $scope = 'full', bool $kick = true, string $note = '', string $label = 'manual'): string
    {
        self::assertIdle();
        $id = self::jobs()->create('backup', BackupJob::initialCursor($scope, $label, $note));
        if ($kick) {
            Scheduler::kick($id);
        }
        return $id;
    }

    public static function startRestore(string $backupId, array $opts, bool $kick = true): string
    {
        self::assertIdle();
        $plan = RestorePlan::build(self::store()->load($backupId), self::site(), $opts);
        $id = self::jobs()->create('restore', RestoreJob::initialCursor($plan));
        if ($kick) {
            Scheduler::kick($id);
        }
        return $id;
    }

    /** @return array{status:int,body:string} */
    public static function httpGet(string $url): array
    {
        $r = wp_remote_get($url, ['timeout' => 20, 'redirection' => 3, 'sslverify' => false]);
        if (is_wp_error($r)) {
            return ['status' => 0, 'body' => $r->get_error_message()];
        }
        return ['status' => (int) wp_remote_retrieve_response_code($r), 'body' => (string) wp_remote_retrieve_body($r)];
    }

    /** Keeps the admin who runs a browser restore logged in: the restored users table replaced their session. */
    public static function afterSwap(array $plan): void
    {
        wp_cache_flush();
        if (Settings::read(Settings::OPTION, null) === null) {
            // the restored database never saved settings: it must not gain daily backups from the new default (same rule as the upgrade branch)
            Settings::write(Settings::OPTION, array_merge(SettingsSchema::DEFAULTS, ['schedule' => 'off']));
        }
        // the restored database carries the source's Action Scheduler rows; this site's own settings decide the schedule
        Scheduler::sync(Settings::get());
        if ((defined('WP_CLI') && WP_CLI) || !is_user_logged_in()) {
            return;
        }
        $user = get_user_by('login', wp_get_current_user()->user_login);
        if ($user instanceof \WP_User) {
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID, false, is_ssl());
        }
    }

    private static function assertIdle(): void
    {
        if (self::jobs()->active() !== null) {
            throw new \RuntimeException(esc_html__('Another backup or restore is already running.', 'dosya-backup-restore-migration'));
        }
    }
}
