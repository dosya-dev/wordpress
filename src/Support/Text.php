<?php
namespace Dosya\Backup\Support;

final class Text
{
    /** Returns $s unchanged when it is valid UTF-8, otherwise with every byte >= 0x80 replaced by "?" so it is safe to JSON-encode. */
    public static function utf8(string $s): string
    {
        if (preg_match('//u', $s) === 1) {
            return $s;
        }
        return (string) preg_replace('/[\x80-\xFF]/', '?', $s);
    }
}
