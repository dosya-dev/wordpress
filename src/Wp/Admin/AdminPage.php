<?php
namespace Dosya\Backup\Wp\Admin;

use Dosya\Backup\Scan\Excludes;
use Dosya\Backup\Wp\Admin\ViewModel\ConnectCard;
use Dosya\Backup\Wp\Admin\ViewModel\RestoreDialog;
use Dosya\Backup\Wp\Admin\ViewModel\RunningCard;
use Dosya\Backup\Wp\Admin\ViewModel\StatusCard;
use Dosya\Backup\Wp\Plugin;
use Dosya\Backup\Wp\Scheduler;
use Dosya\Backup\Wp\Settings;
use Dosya\Backup\Wp\SiteKeyStore;

/** The admin page shell: menu, assets, tab routing. Each tab's markup lives in templates/. */
final class AdminPage
{
    public const SLUG = 'dosya-backup';

    public static function register(): void
    {
        add_action(is_multisite() ? 'network_admin_menu' : 'admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    public static function menu(): void
    {
        add_menu_page(__('dosya Backup', 'dosya-backup-restore-migration'), __('dosya Backup', 'dosya-backup-restore-migration'), Plugin::capability(), self::SLUG,
            [self::class, 'render'], 'dashicons-backup', 80);
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::SLUG || Plugin::initError() !== null) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only tab choice
        $tab = Tabs::resolve(isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : null);
        // phpcs:enable
        wp_enqueue_style('dosya-backup', plugins_url('assets/admin.css', DOSYA_BACKUP_FILE), [], DOSYA_BACKUP_VERSION);
        wp_style_add_data('dosya-backup', 'rtl', 'replace');
        // api.js carries the localized object; each tab adds its own script.
        // restore.js owns the restore dialog, which the Backups list opens too.
        wp_enqueue_script('dosya-backup-api', plugins_url('assets/js/api.js', DOSYA_BACKUP_FILE), [], DOSYA_BACKUP_VERSION, true);
        if ($tab === 'backups') {
            wp_enqueue_script('dosya-backup-backups', plugins_url('assets/js/backups.js', DOSYA_BACKUP_FILE), ['dosya-backup-api'], DOSYA_BACKUP_VERSION, true);
        }
        if ($tab === 'backups' || $tab === 'restore') {
            wp_enqueue_script('dosya-backup-restore', plugins_url('assets/js/restore.js', DOSYA_BACKUP_FILE), ['dosya-backup-api'], DOSYA_BACKUP_VERSION, true);
        }
        if ($tab === 'schedule') {
            wp_enqueue_script('dosya-backup-schedule', plugins_url('assets/js/schedule.js', DOSYA_BACKUP_FILE), ['dosya-backup-api'], DOSYA_BACKUP_VERSION, true);
        }
        if ($tab === 'dosya') {
            wp_enqueue_script('dosya-backup-dosya', plugins_url('assets/js/dosya.js', DOSYA_BACKUP_FILE), ['dosya-backup-api'], DOSYA_BACKUP_VERSION, true);
        }
        $settings = Settings::get();
        wp_localize_script('dosya-backup-api', 'dosyaBackup', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(Ajax::NONCE),
            'downloadBase' => Download::url('__ID__', '__FILE__'),
            'chunkSize' => (int) max(262144, min(1048576, wp_max_upload_size() - 65536)),
            'homeUrl' => home_url(),
            'tab' => $tab,
            'domain' => (string) wp_parse_url(home_url(), PHP_URL_HOST),
            'tz' => wp_timezone_string(),
            'now' => time(),
            'safetyCopy' => !empty($settings['safety_copy']),
            'perPage' => ListAssembler::PER_PAGE,
            'i18n' => self::i18nStrings(),
        ]);
    }

    /**
     * Every string the tab scripts show, in English, by key. Messages rendered in PHP (status card, result banner,
     * restore dialog) are not listed; the scripts receive those already translated.
     *
     * @return array<string,string>
     */
    public static function i18nKeys(): array
    {
        return RunningCard::PHASE_TEXT + [
            'delete_confirm' => 'Delete this backup from this server? Copies in dosya are kept.',
            'disconnect_confirm' => 'Disconnect this site from dosya? Backups already stored in dosya are kept.',
            'incremental_download' => 'This is an incremental backup. It depends on earlier backups and can only be imported where those exist. To migrate a site, download a full backup.',
            'session_expired' => 'Your session expired. Reload the page to continue.',
            'login_refreshed' => 'Your login was refreshed by the restore. Reloading...',
            'saved' => 'Saved',
            'saved_detail' => 'changes apply from the next run',
            'uploading' => '%1$s of %2$s · chunk %3$d of %4$d · if the upload stops, choose the same files again to resume',
            'backup_received' => 'Backup received. Choose how to restore it.',
            'no_backups' => 'No backups on this server yet. The first one appears here as soon as it starts.',
            'more_actions' => 'More actions',
            'failed_prefix' => 'Failed: %s',
            'skipped_prefix' => 'Skipped: %s',
            'unknown_error' => 'unknown error',
            'done' => 'Done.',
            'done_warnings' => 'Done, with %1$d warning(s): %2$s',
            'request_failed' => 'The request failed. Try again.',
            'restore' => 'Restore',
            'download_manifest' => 'Download manifest',
            'view_log' => 'View log',
            'download_volume' => 'Download %s',
            'delete' => 'Delete',
            'detail_tables' => '%1$d of %2$d tables',
            'detail_file' => '%1$d file, %2$s',
            'detail_files' => '%1$d files, %2$s',
            'detail_bytes' => '%1$s of %2$s',
            'detail_items' => '%1$d of %2$d',
            'heading_backup' => 'Backing up this site',
            'heading_upload' => 'Uploading to dosya',
            'heading_restore' => 'Restoring the backup',
            'maintenance' => 'the site shows a maintenance page until this finishes',
            'show_log' => 'Show log',
            'tabs_busy' => 'A backup or restore is running',
            'no_log' => 'No log is kept for completed backups yet.',
            'start_backup' => 'Start backup',
            'backup_started' => 'Backup started.',
            'page_label' => 'Page %1$d of %2$d',
            'newer_backups' => 'Newer backups',
            'older_backups' => 'Older backups',
            'popover_label' => 'Backup options',
            'scope_legend' => 'What to back up',
            'scope_full' => 'Database and files',
            'scope_db' => 'Database only',
            'scope_files' => 'Files only',
            'note_label' => 'Note (optional)',
            'note_placeholder' => 'Note, e.g. before theme update',
            'dismiss' => 'Dismiss',
            'type_domain_mismatch' => 'The domain you typed does not match %s. Type it exactly to continue.',
            'upload_failed' => 'Upload stopped: %s Choose the files again to resume where it stopped.',
            'upload_file' => 'Uploading %s',
            'upload_label' => 'Upload progress',
            'upload_busy' => 'An upload is already running. Wait for it to finish.',
            'upload_none' => 'No files were chosen. Choose the backup files to upload.',
            'network_error' => 'The connection to this site dropped. Check the connection, then reload the page.',
            'restore_started' => 'Restore started. The site shows a maintenance page until it finishes.',
        ];
    }

    /** @return array<string,string> */
    public static function i18nStrings(): array
    {
        $out = [];
        foreach (self::i18nKeys() as $key => $english) {
            $out[$key] = I18nCatalog::translate($english);
        }
        return $out;
    }

    /** Fixed strings per error code; plan_cap and exchange may carry the server's sentence, read once from a short transient. */
    private static function connectErrorText(string $code): string
    {
        if ($code === '') {
            return '';
        }
        $fixed = [
            'cancelled' => __('Connecting was cancelled. Nothing was changed.', 'dosya-backup-restore-migration'),
            'expired' => __('This connect link expired or was already used. Start again.', 'dosya-backup-restore-migration'),
            'folder_gone' => __("The site's folder in dosya no longer exists. Connect the site again.", 'dosya-backup-restore-migration'),
            'state' => __('This connect link does not match the request this site started. Start again.', 'dosya-backup-restore-migration'),
            'plan_cap' => __('Your dosya plan does not allow another connected site.', 'dosya-backup-restore-migration'),
            'exchange' => __('dosya could not connect this site. Please try again.', 'dosya-backup-restore-migration'),
        ];
        $text = $fixed[$code] ?? $fixed['exchange'];
        if ($code === 'plan_cap' || $code === 'exchange') {
            $key = Connect::MESSAGE_TRANSIENT . get_current_user_id();
            $msg = get_transient($key);
            if (is_string($msg) && $msg !== '') {
                delete_transient($key);
                $text = function_exists('mb_substr') ? mb_substr($msg, 0, 300) : $msg;
            }
        }
        return $text;
    }

    /**
     * When the next scheduled backup runs, for the Schedule tab: the timestamp and the sentence ("Next backup tomorrow at 03:00."),
     * both empty when automatic backups are off or no run is queued.
     *
     * @return array{next_run:?int,next_run_text:string}
     */
    public static function nextRun(array $settings): array
    {
        $now = time();
        $next = ($settings['schedule'] ?? 'off') !== 'off' ? Scheduler::nextRun() : null;
        if ($next === null || $next <= $now) {
            return ['next_run' => null, 'next_run_text' => ''];
        }
        $text = StatusCard::nextRunText($next, $now, wp_timezone_string(), self::translator(), static function (int $ts, string $pattern): string { return wp_date($pattern, $ts); });
        return ['next_run' => $next, 'next_run_text' => $text];
    }

    private static function translator(): callable
    {
        return I18nCatalog::translator();
    }

    public static function tabUrl(string $tab, array $args = []): string
    {
        return Connect::pageUrl(['tab' => $tab] + $args);
    }

    public static function render(): void
    {
        if (Plugin::initError() !== null) {
            ?>
            <div class="wrap dosya-backup">
                <h1><?php esc_html_e('dosya Backup', 'dosya-backup-restore-migration'); ?></h1>
                <p><?php esc_html_e('dosya Backup could not start on this site, so backups and restores are paused:', 'dosya-backup-restore-migration'); ?>
                    <?php echo esc_html((string) Plugin::initError()); ?></p>
            </div>
            <?php
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only tab choice, paging and notices after the redirect back from dosya.dev
        $tab = Tabs::resolve(isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : null);
        $paged = isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
        $justConnected = isset($_GET['connected']) && $_GET['connected'] === '1';
        $errorCode = isset($_GET['connect_error']) ? sanitize_key(wp_unslash($_GET['connect_error'])) : '';
        // phpcs:enable
        $t = self::translator();
        $now = time();
        $tz = wp_timezone_string();
        $settings = Settings::get();
        $keyState = (new SiteKeyStore())->state();
        $conn = $keyState === 'ok' ? Plugin::connection() : null;
        $active = Plugin::jobs()->active();

        $tabs = [];
        $labels = [
            'backups' => __('Backups', 'dosya-backup-restore-migration'),
            'restore' => __('Restore & migrate', 'dosya-backup-restore-migration'),
            'schedule' => __('Schedule', 'dosya-backup-restore-migration'),
            'dosya' => __('dosya', 'dosya-backup-restore-migration'),
        ];
        foreach (Tabs::ALL as $name) {
            $tabs[] = ['name' => $name, 'label' => $labels[$name], 'url' => self::tabUrl($name), 'active' => $name === $tab];
        }
        ?>
        <div class="wrap dosya-backup" id="dosya-backup-app" data-tab="<?php echo esc_attr($tab); ?>" data-busy="<?php echo $active !== null ? '1' : '0'; ?>">
            <h1 class="wp-heading-inline"><span class="dosya-mark" aria-hidden="true"></span> <?php esc_html_e('dosya Backup', 'dosya-backup-restore-migration'); ?></h1>
            <?php
            Template::render('partials/tabs', ['tabs' => $tabs, 'busy' => $active !== null]);
            if ($tab === 'backups') {
                Template::render('backups', self::backupsVm($paged, $active, $now, $tz, $t));
            } elseif ($tab === 'restore') {
                Template::render('restore', []);
            } elseif ($tab === 'schedule') {
                Template::render('schedule', self::scheduleVm($settings, $conn, $now, $tz, $t));
            } else {
                Template::render('dosya', self::dosyaVm($keyState, $conn, $justConnected, self::connectErrorText($errorCode), $now, $tz, $t));
            }
            $home = home_url();
            $dialog = RestoreDialog::build(['created' => $now, 'scope' => 'full'], ['now' => $now, 'tz' => $tz, 'home_url' => $home,
                'domain' => (string) wp_parse_url($home, PHP_URL_HOST), 'safety_copy' => !empty($settings['safety_copy'])], $t);
            // the scripts fill the title and facts for the chosen backup; until then the dialog reads generically
            $dialog['title'] = __('Restore this backup?', 'dosya-backup-restore-migration');
            $dialog['facts'] = '';
            Template::render('partials/restore-dialog', $dialog);
            ?>
        </div>
        <?php
    }

    private static function backupsVm(int $paged, ?array $active, int $now, string $tz, callable $t): array
    {
        $list = ListAssembler::build($paged);
        $vm = [
            'status' => null,
            'running' => null,
            'rows' => [],
            'empty_text' => __('No backups on this server yet. The first one appears here as soon as it starts.', 'dosya-backup-restore-migration'),
            'foot' => $list['foot_text'],
            'pager' => null,
        ];

        if ($active !== null) {
            $status = ListAssembler::status($active['id']);
            $ctx = null;
            if ($active['kind'] === 'restore') {
                $restoring = (string) ($active['cursor']['plan']['backup_id'] ?? '');
                foreach (Plugin::store()->list() as $b) {
                    if ($b['id'] === $restoring) {
                        $ctx = ['backup_created' => (int) $b['created'], 'now' => $now, 'tz' => $tz];
                        break;
                    }
                }
            }
            $run = RunningCard::build($status, $t, $ctx);
            $line = [$run['phase_text']];
            if ($run['percent'] !== null) {
                $line[] = $run['percent'] . '%';
            }
            if ($run['detail'] !== '') {
                $line[] = $run['detail'];
            }
            if ($run['maintenance']) {
                $line[] = __('the site shows a maintenance page until this finishes', 'dosya-backup-restore-migration');
            }
            $run['line'] = implode(' · ', $line);
            $vm['running'] = $run;
        } else {
            $vm['status'] = $list['status_vm'];
        }

        foreach ($list['rows_vm'] as $i => $row) {
            $raw = $list['backups'][$i];
            $downloads = [];
            if (!empty($row['actions']['download'])) {
                $downloads[] = ['label' => __('Download manifest', 'dosya-backup-restore-migration'), 'url' => Download::url((string) $raw['id'], 'manifest.json.gz')];
                for ($v = 1; $v <= (int) $raw['volumes']; $v++) {
                    $name = sprintf('v%04d.dosya', $v);
                    /* translators: %s: volume file name, e.g. v0001.dosya. */
                    $downloads[] = ['label' => sprintf(__('Download %s', 'dosya-backup-restore-migration'), $name), 'url' => Download::url((string) $raw['id'], $name)];
                }
            }
            $row['downloads'] = $downloads;
            $row['incremental'] = ($raw['kind'] ?? '') === 'incremental';
            $row['failed'] = $raw['failed_reason'] !== null;
            $vm['rows'][] = $row;
        }

        $pages = (int) ceil($list['total'] / max(1, $list['per_page']));
        if ($pages > 1) {
            $page = (int) $list['page'];
            $vm['pager'] = [
                /* translators: 1: current page, 2: number of pages. */
                'text' => sprintf(__('Page %1$d of %2$d', 'dosya-backup-restore-migration'), $page, $pages),
                'page' => $page,
                'prev' => $page > 1 ? self::tabUrl('backups', ['paged' => $page - 1]) : null,
                'next' => $page < $pages ? self::tabUrl('backups', ['paged' => $page + 1]) : null,
            ];
        }
        return $vm;
    }

    private static function scheduleVm(array $settings, $conn, int $now, string $tz, callable $t): array
    {
        $keep = (int) $settings['keep'];
        $keepOptions = [3, 5, 10];
        if (!in_array($keep, $keepOptions, true)) {
            $keepOptions[] = $keep;
            sort($keepOptions);
        }
        $remote = null;
        if ($conn !== null) {
            if ($conn->retentionDays !== null && $conn->immutabilityHours !== null) {
                /* translators: 1: days backups stay in dosya, 2: hours each upload is protected from deletion. */
                $remote = sprintf(__('%1$d days on your plan, protected from deletion for %2$d hours after upload. Change the plan on dosya.dev.', 'dosya-backup-restore-migration'),
                    $conn->retentionDays, $conn->immutabilityHours);
            } elseif ($conn->retentionDays !== null) {
                /* translators: %d: days backups stay in dosya. */
                $remote = sprintf(__('%d days on your plan. Change the plan on dosya.dev.', 'dosya-backup-restore-migration'), $conn->retentionDays);
            } else {
                $remote = __('Set by your plan. Change the plan on dosya.dev.', 'dosya-backup-restore-migration');
            }
        }
        return [
            'schedule' => (string) $settings['schedule'],
            'time' => (string) $settings['time'],
            'tz' => $tz,
            'next_text' => self::nextRun($settings)['next_run_text'],
            'keep' => $keep,
            'keep_options' => $keepOptions,
            'remote_keep' => $remote,
            'safety_copy' => !empty($settings['safety_copy']),
            'email_fail' => !empty($settings['email_fail']),
            'email_ok' => !empty($settings['email_ok']),
            'email_to' => (string) (is_multisite() ? get_site_option('admin_email') : get_option('admin_email')),
            'excludes' => implode("\n", (array) $settings['excludes']),
            'default_excludes' => Excludes::defaults(),
        ];
    }

    private static function dosyaVm(string $keyState, $conn, bool $justConnected, string $connectError, int $now, string $tz, callable $t): array
    {
        $lastUpload = null;
        $avg = null;
        if ($conn !== null) {
            $remote = Plugin::remoteStore();
            $last = $remote->last();
            if ($last !== null) {
                $info = $remote->info($last['backup_id']);
                $lastUpload = ['created' => $last['created'], 'verified' => is_array($info) && ($info['verified'] ?? null) === true];
            }
            $sizes = array_map(static function (array $b): int { return (int) $b['size']; }, Plugin::store()->list());
            $avg = $sizes ? (int) round(array_sum($sizes) / count($sizes)) : null;
        }
        $card = ConnectCard::build([
            'key_state' => $keyState,
            'connection' => $conn === null ? null : ['retention_days' => $conn->retentionDays, 'immutability_hours' => $conn->immutabilityHours,
                'folder' => null, 'account' => null, 'workspace' => null],
            'storage' => null,
            'last_upload' => $lastUpload,
            'avg_backup' => $avg,
            'plan_line' => __('Free plan: 1 site, 5 GB, 7 days', 'dosya-backup-restore-migration'),
            'now' => $now,
            'tz' => $tz,
            'fmt' => static function (int $ts, string $pattern): string { return wp_date($pattern, $ts); },
        ], $t);
        return $card + [
            'just_connected' => $justConnected,
            'connect_error' => $connectError,
            'unencrypted' => $conn !== null && !$conn->encrypted,
            'connect_url' => Connect::startUrl(),
            'open_url' => Plugin::webBase(),
            'excludes_url' => self::tabUrl('schedule') . '#dosya-excludes',
        ];
    }
}
