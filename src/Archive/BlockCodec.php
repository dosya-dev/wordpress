<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI).
namespace Dosya\Backup\Archive;

/** Block record: stored_len u32 | codec u8 | plain_len u32 | payload | sha256(plain) 32 B */
final class BlockCodec
{
    public const OVERHEAD = 41;

    public static function encode(string $plain, bool $compress): string
    {
        if ($plain === '') {
            throw new \InvalidArgumentException('empty blocks are never written');
        }
        if (strlen($plain) > Format::BLOCK_MAX) {
            throw new \InvalidArgumentException('block larger than ' . Format::BLOCK_MAX . ' bytes');
        }
        $codec = Format::CODEC_RAW;
        $payload = $plain;
        if ($compress) {
            $z = gzdeflate($plain, 6);
            if ($z !== false && strlen($z) < strlen($plain)) {
                $codec = Format::CODEC_DEFLATE;
                $payload = $z;
            }
        }
        return Bin::u32(strlen($payload)) . Bin::u8($codec) . Bin::u32(strlen($plain)) . $payload . hash('sha256', $plain, true);
    }

    public static function decode(string $record): string
    {
        $storedLen = Bin::readU32($record, 0);
        $codec = Bin::readU8($record, 4);
        $plainLen = Bin::readU32($record, 5);
        if (strlen($record) !== self::OVERHEAD + $storedLen) {
            throw new FormatException('block length mismatch');
        }
        if ($plainLen > Format::BLOCK_MAX) {
            // checked before inflating: a forged plain_len must not make gzinflate allocate gigabytes
            throw new FormatException('block too large');
        }
        $payload = substr($record, 9, $storedLen);
        $sha = substr($record, 9 + $storedLen, 32);
        if ($codec === Format::CODEC_DEFLATE) {
            $plain = @gzinflate($payload, max(1, $plainLen)); // a corrupt stream returns false; the warning adds nothing
            if ($plain === false) {
                throw new FormatException('block does not inflate');
            }
        } elseif ($codec === Format::CODEC_RAW) {
            $plain = $payload;
        } else {
            throw new FormatException('unknown block codec ' . $codec);
        }
        if (strlen($plain) !== $plainLen || !hash_equals($sha, hash('sha256', $plain, true))) {
            throw new FormatException('block checksum mismatch');
        }
        return $plain;
    }
}
