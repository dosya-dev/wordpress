<?php
namespace Dosya\Backup\Tests\unit\Db;

use Dosya\Backup\Db\SerializedReplace;
use Dosya\Backup\Db\UrlPairs;
use PHPUnit\Framework\TestCase;

final class SerializedReplaceTest extends TestCase
{
    private const PAIRS = ['http://a.com' => 'https://b.org/site'];

    public function testUrlPairsCoverVariants(): void
    {
        $p = UrlPairs::build('https://a.com/', 'https://b.org', '/var/www/a/', '/srv/b/');
        $this->assertSame('https://b.org', $p['https://a.com']);
        $this->assertSame('https://b.org', $p['http://a.com']);
        $this->assertSame('//b.org', $p['//a.com']);
        $this->assertSame('https:\/\/b.org', $p['https:\/\/a.com']);
        $this->assertSame(rawurlencode('https://b.org'), $p[rawurlencode('https://a.com')]);
        $this->assertSame('/srv/b', $p['/var/www/a']);
        $this->assertSame([], UrlPairs::build('https://a.com', 'https://a.com/'));
    }

    public function testNewUrlContainingOldUrlIsNotReplacedTwice(): void
    {
        $pairs = UrlPairs::build('http://a.com', 'http://a.com/blog');
        $this->assertSame('see http://a.com/blog/x', SerializedReplace::apply('see http://a.com/x', $pairs));
    }

    public function testFixesSerializedLengths(): void
    {
        $in = serialize(['u' => 'http://a.com/x', 'n' => [1, 'http://a.com'], 'k' => true, 'f' => 1.5, 'z' => null]);
        $out = SerializedReplace::apply($in, self::PAIRS);
        $this->assertSame(['u' => 'https://b.org/site/x', 'n' => [1, 'https://b.org/site'], 'k' => true, 'f' => 1.5, 'z' => null], unserialize($out));
    }

    public function testNestedSerializedInsideSerialized(): void
    {
        $inner = serialize(['link' => 'http://a.com/p']);
        $out = SerializedReplace::apply(serialize(['blob' => $inner]), self::PAIRS);
        $this->assertSame(['link' => 'https://b.org/site/p'], unserialize(unserialize($out)['blob']));
    }

    public function testObjectsAreRewrittenWithoutInstantiation(): void
    {
        $in = 'O:11:"Evil\Gadget":1:{s:3:"url";s:14:"http://a.com/x";}';
        $out = SerializedReplace::apply($in, self::PAIRS);
        $this->assertSame('O:11:"Evil\Gadget":1:{s:3:"url";s:20:"https://b.org/site/x";}', $out);
    }

    public function testMalformedSerializedIsLeftUntouched(): void
    {
        $bad = 's:99:"http://a.com";';
        $this->assertSame($bad, SerializedReplace::apply($bad, self::PAIRS));
    }

    public function testJsonEscapedUrls(): void
    {
        $pairs = UrlPairs::build('http://a.com', 'http://b.com');
        $this->assertSame('{"u":"http:\/\/b.com\/x"}', SerializedReplace::apply('{"u":"http:\/\/a.com\/x"}', $pairs));
    }

    public function testPlainTextStartingWithScalarTokenIsReplaced(): void
    {
        $this->assertSame('b: see https://b.org/site; done', SerializedReplace::apply('b: see http://a.com; done', self::PAIRS));
        $this->assertSame('N; https://b.org/site', SerializedReplace::apply('N; http://a.com', self::PAIRS));
    }

    public function testTrailingWhitespaceIsTolerated(): void
    {
        $out = SerializedReplace::apply(serialize(['u' => 'http://a.com']) . "\n", self::PAIRS);
        $this->assertSame(serialize(['u' => 'https://b.org/site']) . "\n", $out);
        $this->assertSame(['u' => 'https://b.org/site'], unserialize(rtrim($out)));
    }

    public function testNoEmptyReplacementsAreEmitted(): void
    {
        $p = UrlPairs::build('https://a.com', 'https://b.org', '/var/www/a');
        $this->assertArrayNotHasKey('/var/www/a', $p);
        foreach ($p as $to) {
            $this->assertNotSame('', $to);
        }
        $this->assertSame([], UrlPairs::build('https://a.com', ''));
    }

    public function testRandomStructuresRoundTrip(): void
    {
        mt_srand(42);
        for ($i = 0; $i < 300; $i++) {
            $v = $this->randomValue(0);
            $expected = $this->replaceDeep($v);
            $out = SerializedReplace::apply(serialize($v), self::PAIRS);
            $this->assertEquals($expected, unserialize($out), 'case ' . $i);
        }
    }

    private function randomValue(int $depth)
    {
        $r = mt_rand(0, $depth > 3 ? 4 : 6);
        switch ($r) {
            case 0: return mt_rand();
            case 1: return null;
            case 2: return (bool) mt_rand(0, 1);
            case 3: return 'x' . str_repeat('http://a.com/é', mt_rand(0, 3)) . ';"}';
            case 4: return mt_rand() / 7;
            default:
                $a = [];
                for ($k = mt_rand(0, 4); $k > 0; $k--) {
                    $a[mt_rand(0, 1) ? 'k' . mt_rand(0, 9) . 'http://a.com' : mt_rand(0, 9)] = $this->randomValue($depth + 1);
                }
                return $a;
        }
    }

    private function replaceDeep($v)
    {
        if (is_string($v)) { return strtr($v, self::PAIRS); }
        if (!is_array($v)) { return $v; }
        $out = [];
        foreach ($v as $k => $x) { $out[is_string($k) ? strtr($k, self::PAIRS) : $k] = $this->replaceDeep($x); }
        return $out;
    }
}
