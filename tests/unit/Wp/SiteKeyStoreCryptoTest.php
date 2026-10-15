<?php
namespace Dosya\Backup\Tests\unit\Wp;

use Dosya\Backup\Wp\SiteKeyStore;
use PHPUnit\Framework\TestCase;

final class SiteKeyStoreCryptoTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium missing');
        }
    }

    public function testRoundTrip(): void
    {
        $sealed = SiteKeyStore::seal('dos_abc', 'auth-key');
        $this->assertStringNotContainsString('dos_abc', $sealed);
        $this->assertSame('dos_abc', SiteKeyStore::open($sealed, 'auth-key'));
        $this->assertNotSame($sealed, SiteKeyStore::seal('dos_abc', 'auth-key'));
    }

    public function testWrongKeyAndTamperAndGarbage(): void
    {
        $sealed = SiteKeyStore::seal('dos_abc', 'a');
        $this->assertNull(SiteKeyStore::open($sealed, 'b'));
        $raw = base64_decode($sealed, true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";
        $this->assertNull(SiteKeyStore::open(base64_encode($raw), 'a'));
        $this->assertNull(SiteKeyStore::open('not base64 !!', 'a'));
        $this->assertNull(SiteKeyStore::open('', 'a'));
    }
}
