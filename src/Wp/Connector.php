<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text and are escaped where they are displayed (admin notice, WP-CLI).
namespace Dosya\Backup\Wp;

use Dosya\Backup\Connect\Connection;
use Dosya\Backup\Connect\DosyaClient;
use Dosya\Backup\Connect\HttpException;
use Dosya\Backup\Connect\Pkce;
use Dosya\Backup\Remote\RemoteStage;

/** Connects this site to a dosya workspace (PKCE through the web consent page). */
final class Connector
{
    public const TRANSIENT = 'dosya_backup_connect';
    public const FINGERPRINT_OPTION = 'dosya_backup_fingerprint';
    private const TTL = 900;

    /** Pure and WordPress-free: every value is rawurlencoded. @param array<string,string> $params */
    public static function buildRedirect(string $webBase, array $params): string
    {
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        return rtrim($webBase, '/') . '/connect/wordpress?' . implode('&', $parts);
    }

    /** Stores the PKCE pair for 15 minutes and returns the consent page URL. */
    public function begin(): string
    {
        $verifier = Pkce::verifier();
        $state = Pkce::state();
        self::setTransient(['verifier' => $verifier, 'state' => $state, 'started' => time()]);

        $siteUrl = untrailingslashit(site_url());
        $scheme = (string) wp_parse_url($siteUrl, PHP_URL_SCHEME);
        $return = admin_url('admin-post.php?action=dosya_connect_callback');
        if ($scheme !== '') {
            $return = set_url_scheme($return, $scheme);
        }
        $params = [
            'site_url' => $siteUrl,
            'home_url' => untrailingslashit(home_url()),
            'name' => self::siteName(),
            'fingerprint' => self::fingerprint(),
        ];
        if (is_multisite()) {
            $params['multisite'] = '1';
        }
        $params['code_challenge'] = Pkce::challenge($verifier);
        $params['state'] = $state;
        $params['return'] = $return;
        return self::buildRedirect(Plugin::webBase(), $params);
    }

    /** @throws ConnectException */
    public function complete(string $code, string $state): Connection
    {
        $pending = self::getTransient();
        if (!is_array($pending) || !isset($pending['verifier'], $pending['state'])) {
            throw new ConnectException(__('This connect link expired or was already used. Start again.', 'dosya-backup-restore-migration'), 'expired');
        }
        if ($state === '' || !hash_equals((string) $pending['state'], $state)) {
            throw new ConnectException(__('This connect link does not match the request this site started. Start again.', 'dosya-backup-restore-migration'), 'state');
        }
        // single use: a replayed callback finds nothing
        self::deleteTransient();
        return $this->exchange($code, (string) $pending['verifier']);
    }

    /** Drops the pending connect when `$state` is the one this site started (the user cancelled on dosya). */
    public function abort(string $state): void
    {
        $pending = self::getTransient();
        if (is_array($pending) && isset($pending['state']) && $state !== '' && hash_equals((string) $pending['state'], $state)) {
            self::deleteTransient();
        }
    }

    /** Used by `wp dosya connect`, which brings its own PKCE pair. @throws ConnectException */
    public function exchange(string $code, string $verifier): Connection
    {
        $client = Plugin::anonymousApi();
        try {
            $json = $client->exchange($code, $verifier);
        } catch (HttpException $e) {
            throw new ConnectException(self::messageFor($e), self::reasonFor($e));
        }
        $conn = Connection::fromExchange($json, Plugin::apiBase());
        if ($conn->siteKey === '' || $conn->siteId === '') {
            throw new ConnectException(__('dosya answered with an unexpected response. Please try again.', 'dosya-backup-restore-migration'), 'exchange');
        }
        (new SiteKeyStore())->save($conn);
        // a fresh connection ends any earlier loss, so a later loss shows its notice again
        Admin\Notices::clearConnectionLost();
        return $conn;
    }

    public function disconnect(): void
    {
        $api = Plugin::api();
        $conn = Plugin::connection();
        if ($api !== null && $conn !== null) {
            self::abortActiveUpload($api);
            try {
                $api->disconnect($conn->siteId);
            } catch (\Throwable $e) {
                // best effort: the local key is removed either way; the message never carries the key
                if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                    error_log('dosya-backup: remote disconnect failed: ' . ($e instanceof HttpException ? 'HTTP ' . $e->status : get_class($e))); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }
            }
        }
        (new SiteKeyStore())->clear();
        self::deleteTransient();
    }

    /** Best effort, while the key still works: the running backup's open upload session is aborted. */
    private static function abortActiveUpload(DosyaClient $api): void
    {
        try {
            $job = Plugin::jobs()->active();
            if ($job !== null && $job['kind'] === 'backup' && isset($job['cursor']['remote']) && is_array($job['cursor']['remote'])) {
                RemoteStage::abortOpen($api, $job['cursor']['remote']);
            }
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    public static function messageFor(HttpException $e): string
    {
        if ($e->status === 410 && $e->code === 'folder_gone') {
            return __("The site's folder in dosya no longer exists. Connect the site again.", 'dosya-backup-restore-migration');
        }
        if ($e->status === 410) {
            return __('This connect link expired or was already used. Start again.', 'dosya-backup-restore-migration');
        }
        $m = trim($e->getMessage());
        // 402 carries the plan-cap sentence; other 4xx carry the server's own explanation
        if ($e->status >= 400 && $e->status < 500 && $m !== '' && stripos($m, 'failed:') === false) {
            return $m;
        }
        return __('Could not reach dosya to finish connecting. Please try again.', 'dosya-backup-restore-migration');
    }

    /** Fixed error code for the admin notice: expired, folder_gone, plan_cap or exchange. */
    public static function reasonFor(HttpException $e): string
    {
        if ($e->status === 410) {
            return $e->code === 'folder_gone' ? 'folder_gone' : 'expired';
        }
        return $e->status === 402 ? 'plan_cap' : 'exchange';
    }

    public static function fingerprint(): string
    {
        $fp = Settings::read(Settings::FINGERPRINT_OPTION, '');
        if (!is_string($fp) || preg_match('/^[0-9a-f]{32}$/', $fp) !== 1) {
            $fp = bin2hex(random_bytes(16));
            Settings::write(Settings::FINGERPRINT_OPTION, $fp);
        }
        return $fp;
    }

    private static function siteName(): string
    {
        return self::cleanName((string) get_bloginfo('name'), (string) wp_parse_url(site_url(), PHP_URL_HOST));
    }

    /** WordPress stores the blog name entity-escaped; the API limits the UTF-16 length (JS name.length) to 80. Pure. */
    public static function cleanName(string $raw, string $fallback = ''): string
    {
        $name = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES, 'UTF-8')); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- pure helper, unit-tested without WordPress
        if ($name === '') {
            $name = $fallback;
        }
        $chars = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            $chars = [];
        }
        $out = '';
        $units = 0;
        foreach ($chars as $ch) {
            $w = strlen($ch) >= 4 ? 2 : 1;
            if ($units + $w > 80) {
                break;
            }
            $units += $w;
            $out .= $ch;
        }
        $out = trim($out);
        return $out === '' ? 'WordPress site' : $out;
    }

    /** @param array<string,mixed> $v */
    private static function setTransient(array $v): void
    {
        is_multisite() ? set_site_transient(self::TRANSIENT, $v, self::TTL) : set_transient(self::TRANSIENT, $v, self::TTL);
    }

    /** @return mixed */
    private static function getTransient()
    {
        return is_multisite() ? get_site_transient(self::TRANSIENT) : get_transient(self::TRANSIENT);
    }

    private static function deleteTransient(): void
    {
        is_multisite() ? delete_site_transient(self::TRANSIENT) : delete_transient(self::TRANSIENT);
    }
}
