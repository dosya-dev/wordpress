<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI).
namespace Dosya\Backup\Db;

/** Rewrites strings inside PHP-serialized data by parsing it, never by unserialize(). */
final class SerializedReplace
{
    private const LOOKS_SERIALIZED = '/^(?:N;|b:[01];|i:-?\d+;|d:(?:-?\d+(?:\.\d+)?(?:E[+-]?\d+)?|INF|-INF|NAN);|s:\d+:"|a:\d+:\{|O:\d+:"|C:\d+:"|E:\d+:")/';

    public static function apply(string $value, array $pairs): string
    {
        if ($pairs === []) {
            return $value;
        }
        // WordPress trims before judging; unserialize() accepts trailing whitespace.
        $end = strlen(rtrim($value));
        $body = substr($value, 0, $end);
        $tail = substr($value, $end);
        if (preg_match(self::LOOKS_SERIALIZED, $body) !== 1) {
            return strtr($value, $pairs);
        }
        try {
            $pos = 0;
            $out = self::walk($body, $pos, $pairs);
            if ($pos === $end) {
                return $out . $tail;
            }
        } catch (\UnexpectedValueException $e) {
            // Fall through: not a whole serialized value.
        }
        // A bare scalar token followed by text (e.g. "N; http://a.com") is plain text.
        return preg_match('/^(?:N;|[bid]:)/', $body) === 1 ? strtr($value, $pairs) : $value;
    }

    private static function walk(string $s, int &$p, array $pairs): string
    {
        $t = $s[$p] ?? '';
        switch ($t) {
            case 'N':
                self::expect($s, $p, 'N;');
                $p += 2;
                return 'N;';
            case 'b': case 'i': case 'd': case 'r': case 'R':
                $end = strpos($s, ';', $p);
                if ($end === false || ($s[$p + 1] ?? '') !== ':') {
                    throw new \UnexpectedValueException('scalar');
                }
                $tok = substr($s, $p, $end - $p + 1);
                $p = $end + 1;
                return $tok;
            case 's':
                $str = self::lengthString($s, $p, 's');
                $new = self::apply($str, $pairs);
                return 's:' . strlen($new) . ':"' . $new . '";';
            case 'E':
                return 'E:' . strlen($e = self::lengthString($s, $p, 'E')) . ':"' . $e . '";';
            case 'a':
                $n = self::count($s, $p, 'a');
                $out = 'a:' . $n . ':{';
                for ($i = 0; $i < $n * 2; $i++) {
                    $out .= self::walk($s, $p, $pairs);
                }
                self::expect($s, $p, '}');
                $p++;
                return $out . '}';
            case 'O':
            case 'C':
                $class = self::lengthName($s, $p, $t);
                $n = self::count($s, $p, '');
                if ($t === 'C') {
                    $data = substr($s, $p, $n);
                    if (strlen($data) !== $n || ($s[$p + $n] ?? '') !== '}') {
                        throw new \UnexpectedValueException('custom');
                    }
                    $p += $n + 1;
                    return 'C:' . strlen($class) . ':"' . $class . '":' . $n . ':{' . $data . '}';
                }
                $out = 'O:' . strlen($class) . ':"' . $class . '":' . $n . ':{';
                for ($i = 0; $i < $n * 2; $i++) {
                    $out .= self::walk($s, $p, $pairs);
                }
                self::expect($s, $p, '}');
                $p++;
                return $out . '}';
        }
        throw new \UnexpectedValueException('token');
    }

    /** Reads `<t>:<len>:"<bytes>";` and returns the bytes. */
    private static function lengthString(string $s, int &$p, string $t): string
    {
        self::expect($s, $p, $t . ':');
        $colon = strpos($s, ':', $p + 2);
        $len = $colon === false ? '' : substr($s, $p + 2, $colon - $p - 2);
        if (!ctype_digit($len) || ($s[$colon + 1] ?? '') !== '"') {
            throw new \UnexpectedValueException('length');
        }
        $start = $colon + 2;
        $str = substr($s, $start, (int) $len);
        if (strlen($str) !== (int) $len || substr($s, $start + (int) $len, 2) !== '";') {
            throw new \UnexpectedValueException('string bounds');
        }
        $p = $start + (int) $len + 2;
        return $str;
    }

    /** Reads `<t>:<len>:"<name>":` and returns the name (object/custom class). */
    private static function lengthName(string $s, int &$p, string $t): string
    {
        self::expect($s, $p, $t . ':');
        $colon = strpos($s, ':', $p + 2);
        $len = $colon === false ? '' : substr($s, $p + 2, $colon - $p - 2);
        if (!ctype_digit($len) || ($s[$colon + 1] ?? '') !== '"') {
            throw new \UnexpectedValueException('class length');
        }
        $name = substr($s, $colon + 2, (int) $len);
        $after = $colon + 2 + (int) $len;
        if (strlen($name) !== (int) $len || substr($s, $after, 2) !== '":') {
            throw new \UnexpectedValueException('class bounds');
        }
        $p = $after + 1;
        return $name;
    }

    /** Reads `[<t>]:<n>:{` and returns n, leaving $p after the brace. */
    private static function count(string $s, int &$p, string $t): int
    {
        if ($t !== '') {
            self::expect($s, $p, $t);
            $p++;
        }
        self::expect($s, $p, ':');
        $colon = strpos($s, ':', $p + 1);
        $n = $colon === false ? '' : substr($s, $p + 1, $colon - $p - 1);
        if (!ctype_digit($n) || ($s[$colon + 1] ?? '') !== '{') {
            throw new \UnexpectedValueException('count');
        }
        $p = $colon + 2;
        return (int) $n;
    }

    private static function expect(string $s, int $p, string $lit): void
    {
        if (substr($s, $p, strlen($lit)) !== $lit) {
            throw new \UnexpectedValueException('expected ' . $lit);
        }
    }
}
