<?php
namespace Dosya\Backup\Wp;

use Dosya\Backup\Connect\Connection;

final class SiteKeyStore
{
    public const OPTION = 'dosya_backup_connection';

    public function save(Connection $c): void
    {
        $a = $c->toArray();
        $plain = (string) $a['site_key'];
        $sealed = self::seal($plain, self::authKey());
        $a['site_key'] = $sealed;
        $a['encrypted'] = strncmp($sealed, 'plain:', 6) !== 0;
        if (is_multisite()) {
            update_site_option(self::OPTION, $a);
        } else {
            update_option(self::OPTION, $a, false);
        }
    }

    /** 'none' when nothing is stored, 'unreadable' when a key is stored but cannot be opened (AUTH_KEY changed), else 'ok'. */
    public function state(): string
    {
        $a = $this->stored();
        if ($a === null) {
            return 'none';
        }
        return self::open((string) $a['site_key'], self::authKey()) === null ? 'unreadable' : 'ok';
    }

    /** @return array<string,mixed>|null */
    private function stored(): ?array
    {
        $a = is_multisite() ? get_site_option(self::OPTION, null) : get_option(self::OPTION, null);
        return is_array($a) && isset($a['site_key']) ? $a : null;
    }

    public function load(): ?Connection
    {
        $a = $this->stored();
        if ($a === null) {
            return null;
        }
        $stored = (string) $a['site_key'];
        $key = self::open($stored, self::authKey());
        if ($key === null) {
            return null;
        }
        $a['site_key'] = $key;
        $c = Connection::fromArray($a);
        $c->encrypted = strncmp($stored, 'plain:', 6) !== 0;
        return $c;
    }

    public function clear(): void
    {
        if (is_multisite()) {
            delete_site_option(self::OPTION);
        } else {
            delete_option(self::OPTION);
        }
    }

    private static function authKey(): string
    {
        return defined('AUTH_KEY') ? (string) AUTH_KEY : '';
    }

    private static function key(string $authKey): string
    {
        return hash('sha256', $authKey . 'dosya', true);
    }

    /** base64(nonce . secretbox); without ext-sodium: "plain:<key>". */
    public static function seal(string $plain, string $authKey): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            return 'plain:' . $plain;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key($authKey)));
    }

    /** Null when the value is tampered with, sealed under another key, or sealed but sodium is gone. */
    public static function open(string $sealed, string $authKey): ?string
    {
        if (strncmp($sealed, 'plain:', 6) === 0) {
            return substr($sealed, 6);
        }
        if (!function_exists('sodium_crypto_secretbox_open')) {
            return null;
        }
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES - 1) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key($authKey));
        return $plain === false ? null : $plain;
    }
}
