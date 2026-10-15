<?php
namespace Dosya\Backup\Connect;

/** PKCE (RFC 7636, S256) helpers. No WordPress calls. */
final class Pkce
{
    /** 64 characters of [A-Za-z0-9_-]. */
    public static function verifier(): string
    {
        return substr(self::b64url(random_bytes(48)), 0, 64);
    }

    public static function challenge(string $verifier): string
    {
        return self::b64url(hash('sha256', $verifier, true));
    }

    /** 32 random hex characters. */
    public static function state(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
