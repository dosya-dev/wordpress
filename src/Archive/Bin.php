<?php
namespace Dosya\Backup\Archive;

final class Bin
{
    public static function u8(int $v): string { return chr($v & 0xFF); }
    public static function u16(int $v): string { return pack('v', $v); }
    public static function u32(int $v): string { return pack('V', $v); }
    public static function u64(int $v): string { return pack('P', $v); }

    public static function readU8(string $b, int $o): int { self::need($b, $o, 1); return ord($b[$o]); }
    public static function readU16(string $b, int $o): int { self::need($b, $o, 2); return unpack('v', $b, $o)[1]; }
    public static function readU32(string $b, int $o): int { self::need($b, $o, 4); return unpack('V', $b, $o)[1]; }
    public static function readU64(string $b, int $o): int { self::need($b, $o, 8); return unpack('P', $b, $o)[1]; }

    private static function need(string $b, int $o, int $len): void
    {
        if ($o < 0 || strlen($b) < $o + $len) {
            throw new FormatException('truncated data');
        }
    }
}
