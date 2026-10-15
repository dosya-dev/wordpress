<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI).
namespace Dosya\Backup\Archive;

final class Format
{
    public const VERSION = 1;
    public const MAGIC_VOLUME = 'DOSYAVOL';
    public const MAGIC_END = 'DOSYAEND';
    public const MAGIC_ENTRY = 'DE';
    public const HEADER_SIZE = 64;
    public const FOOTER_SIZE = 64;
    public const BLOCK_MAX = 4194304;
    public const FLAG_ENCRYPTED = 1;

    public const TYPE_FILE = 1;
    public const TYPE_DIR = 2;
    public const TYPE_SYMLINK = 3;
    public const TYPE_DB_PART = 4;
    public const TYPE_DB_SCHEMA = 5;
    public const TYPE_META = 6;

    public const CODEC_RAW = 0;
    public const CODEC_DEFLATE = 1;
    public const END_CODEC = 0xFF;

    private const NO_COMPRESS = [
        'jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'heic', 'mp4', 'mov', 'webm', 'm4v', 'mp3', 'm4a', 'ogg',
        'zip', 'gz', 'tgz', '7z', 'rar', 'bz2', 'xz', 'zst', 'woff', 'woff2', 'pdf', 'dosya',
    ];

    public static function volumeHeader(string $backupId, int $volumeNo, int $created, int $flags): string
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $backupId)) {
            throw new \InvalidArgumentException('backup id must be 32 lowercase hex chars');
        }
        $h = self::MAGIC_VOLUME . Bin::u16(self::VERSION) . Bin::u16($flags) . hex2bin($backupId)
            . Bin::u32($volumeNo) . Bin::u64($created);
        return str_pad($h, self::HEADER_SIZE, "\0");
    }

    /** @return array{flags:int,backup_id:string,volume:int,created:int} */
    public static function parseVolumeHeader(string $h): array
    {
        if (strlen($h) !== self::HEADER_SIZE || substr($h, 0, 8) !== self::MAGIC_VOLUME) {
            throw new FormatException('not a dosya volume');
        }
        $version = Bin::readU16($h, 8);
        if ($version !== self::VERSION) {
            throw new FormatException('unsupported format version ' . $version);
        }
        return [
            'flags' => Bin::readU16($h, 10),
            'backup_id' => bin2hex(substr($h, 12, 16)),
            'volume' => Bin::readU32($h, 28),
            'created' => Bin::readU64($h, 32),
        ];
    }

    public static function footer(int $indexOffset, int $indexLen, string $indexShaRaw): string
    {
        return str_pad(Bin::u64($indexOffset) . Bin::u32($indexLen) . $indexShaRaw . self::MAGIC_END, self::FOOTER_SIZE, "\0");
    }

    /** @return array{index_offset:int,index_len:int,index_sha:string} */
    public static function parseFooter(string $f): array
    {
        if (strlen($f) !== self::FOOTER_SIZE || substr($f, 44, 8) !== self::MAGIC_END) {
            throw new FormatException('volume footer missing (incomplete upload or truncated file)');
        }
        return [
            'index_offset' => Bin::readU64($f, 0),
            'index_len' => Bin::readU32($f, 8),
            'index_sha' => substr($f, 12, 32),
        ];
    }

    /** @param string[] $blockShaHex */
    public static function treeHash(array $blockShaHex): string
    {
        $raw = '';
        foreach ($blockShaHex as $h) {
            $raw .= hex2bin($h);
        }
        return hash('sha256', $raw);
    }

    public static function shouldCompress(string $path): bool
    {
        $dot = strrpos($path, '.');
        if ($dot === false) {
            return true;
        }
        return !in_array(strtolower(substr($path, $dot + 1)), self::NO_COMPRESS, true);
    }
}
