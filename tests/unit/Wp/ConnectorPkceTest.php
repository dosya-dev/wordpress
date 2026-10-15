<?php
namespace Dosya\Backup\Tests\unit\Wp;

use Dosya\Backup\Connect\Pkce;
use Dosya\Backup\Wp\Connector;
use PHPUnit\Framework\TestCase;

final class ConnectorPkceTest extends TestCase
{
    public function testRfc7636Vector(): void
    {
        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    }

    public function testVerifierShapeAndUniqueness(): void
    {
        $a = Pkce::verifier();
        $this->assertSame(1, preg_match('/^[A-Za-z0-9_-]{64}$/', $a));
        $this->assertNotSame($a, Pkce::verifier());
        $this->assertSame(43, strlen(Pkce::challenge($a)));
    }

    public function testStateIs32Hex(): void
    {
        $this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', Pkce::state()));
    }

    public function testBuildRedirectEncodesEveryValue(): void
    {
        $url = Connector::buildRedirect('https://dosya.dev/', [
            'site_url' => 'https://a.test/blog',
            'name' => 'My Site & Co "x"',
            'return' => 'https://a.test/wp-admin/admin-post.php?action=dosya_connect_callback',
        ]);
        $this->assertSame(
            'https://dosya.dev/connect/wordpress?site_url=https%3A%2F%2Fa.test%2Fblog&name=My%20Site%20%26%20Co%20%22x%22'
            . '&return=https%3A%2F%2Fa.test%2Fwp-admin%2Fadmin-post.php%3Faction%3Ddosya_connect_callback',
            $url
        );
    }

    public function testBuildRedirectUsesGivenWebBase(): void
    {
        $this->assertStringStartsWith('http://localhost:4321/connect/wordpress?', Connector::buildRedirect('http://localhost:4321', ['state' => 'x']));
    }

    public function testCleanNameDecodesEntities(): void
    {
        $this->assertSame('Tom & Jerry\'s "Shop"', Connector::cleanName('Tom &amp; Jerry&#039;s &quot;Shop&quot;'));
        $this->assertSame('host.test', Connector::cleanName('  ', 'host.test'));
    }

    public function testCleanNameTruncatesToEightyUtf16Units(): void
    {
        $n = Connector::cleanName(str_repeat("\u{1F600}", 80));
        $this->assertSame(40, count(preg_split('//u', $n, -1, PREG_SPLIT_NO_EMPTY)));
        $this->assertSame(80, strlen($n) / 2);
        $this->assertSame(80, strlen(Connector::cleanName(str_repeat('a', 200))));
    }
}
