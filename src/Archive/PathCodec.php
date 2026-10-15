<?php
namespace Dosya\Backup\Archive;

final class PathCodec
{
    /** @return array{p:string}|array{pb:string} */
    public static function pack(string $path): array
    {
        return preg_match('//u', $path) === 1 ? ['p' => $path] : ['pb' => base64_encode($path)];
    }

    public static function unpack(array $row): string
    {
        if (isset($row['pb'])) {
            $d = base64_decode((string) $row['pb'], true);
            if ($d === false) {
                throw new FormatException('bad base64 path');
            }
            return $d;
        }
        if (!isset($row['p']) || !is_string($row['p'])) {
            throw new FormatException('entry has no path');
        }
        return $row['p'];
    }
}
