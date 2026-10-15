<?php
namespace Dosya\Backup\Wp;

use Dosya\Backup\Wp\Admin\Connect;
use Dosya\Backup\Wp\Admin\ListPayload;
use Dosya\Backup\Wp\Admin\ViewModel\Format;
use Dosya\Backup\Wp\Admin\ViewModel\Mail;
use Dosya\Backup\Wp\Admin\ViewModel\StatusCard;

/** Sends the one email per ended backup job. Never lets a mail problem touch the job. */
final class Mailer
{
    /** Call after a step returned a terminal state; sends only for the first caller per job. */
    public static function afterStep(array $r): void
    {
        try {
            $id = (string) ($r['id'] ?? '');
            $job = Plugin::jobs()->get($id);
            if ($job === null || $job['notified_at'] !== null || !Plugin::jobs()->markNotified($id, time())) {
                return;
            }
            self::notifyTerminal(['kind' => $job['kind'], 'state' => $job['state'], 'error' => $job['error'], 'result' => $job['result']]);
        } catch (\Throwable $e) {
            self::log($e->getMessage());
        }
    }

    public static function notifyTerminal(array $status): void
    {
        $settings = Settings::get();
        $now = time();
        $tz = wp_timezone_string();
        $t = Admin\I18nCatalog::translator();
        $mail = Mail::build($status, [
            'site_name' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            'admin_url' => Connect::pageUrl(),
            'last_good' => self::lastGood($now, $tz, $t),
            'email_fail' => !empty($settings['email_fail']),
            'email_ok' => !empty($settings['email_ok']),
            'now' => $now,
            'tz' => $tz,
        ], $t);
        if ($mail === null) {
            return;
        }
        try {
            // on a network the plugin is managed by the network admin, so the mail goes to the network's address
            $to = is_multisite() ? (string) get_site_option('admin_email') : (string) get_option('admin_email');
            $sent = wp_mail($to, $mail['subject'], $mail['body']);
            if (!$sent) {
                self::log('wp_mail returned false');
            }
        } catch (\Throwable $e) {
            self::log($e->getMessage());
        }
    }

    private static function lastGood(int $now, string $tz, callable $t): ?array
    {
        try {
            return self::lastGoodLookup($now, $tz, $t);
        } catch (\Throwable $e) {
            self::log($e->getMessage());
            return null;
        }
    }

    private static function lastGoodLookup(int $now, string $tz, callable $t): ?array
    {
        // the same backup the status card names: this site's own, not a safety copy or an uploaded one
        $rows = Plugin::store()->list();
        $info = [];
        foreach ($rows as $b) {
            $info[$b['id']] = Plugin::remoteStore()->info($b['id']);
        }
        $best = ListPayload::newestBackup($rows, $info, null);
        if ($best === null) {
            return null;
        }
        return [
            'created_text' => Format::when($best['created'], $now, $tz, $t, false),
            'stored_phrase' => StatusCard::storedPhrase($best['remote_state'], $t),
        ];
    }

    private static function log(string $msg): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('dosya-backup mail: ' . $msg); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- only when WP_DEBUG is on
        }
    }
}
