<?php
namespace Dosya\Backup\Db;

final class UrlPairs
{
    /** @return array<string,string> */
    public static function build(string $oldUrl, string $newUrl, string $oldPath = '', string $newPath = ''): array
    {
        $pairs = [];
        $o = rtrim($oldUrl, '/');
        $n = rtrim($newUrl, '/');
        if ($o !== '' && $o !== $n) {
            $oBare = (string) preg_replace('#^https?:#i', '', $o);
            $nBare = (string) preg_replace('#^https?:#i', '', $n);
            $base = ['http:' . $oBare => $n, 'https:' . $oBare => $n, $oBare => $nBare];
            foreach ($base as $from => $to) {
                $pairs[$from] = $to;
                $pairs[str_replace('/', '\\/', $from)] = str_replace('/', '\\/', $to);
                $pairs[rawurlencode($from)] = rawurlencode($to);
            }
        }
        $op = rtrim($oldPath, '/');
        $np = rtrim($newPath, '/');
        if ($op !== '' && $op !== $np) {
            $pairs[$op] = $np;
        }
        foreach ($pairs as $from => $to) {
            if ($from === $to || $from === '' || $to === '') {
                unset($pairs[$from]);
            }
        }
        return $pairs;
    }
}
