<?php
namespace Dosya\Backup\Tests\unit\Wp;

use Dosya\Backup\Wp\WpHttpClient;
use PHPUnit\Framework\TestCase;

final class WpHttpClientSliceTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'dosya');
        file_put_contents($this->path, 'ABCDEFGHIJklmnoPQRSTUVWXY');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testExactSlice(): void
    {
        $this->assertSame('klmno', WpHttpClient::readSlice($this->path, 10, 5));
        $this->assertSame('', WpHttpClient::readSlice($this->path, 0, 0));
    }

    public function testShortReadThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        WpHttpClient::readSlice($this->path, 20, 10);
    }

    public function testOversizeThrowsBeforeReading(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WpHttpClient::readSlice('/nonexistent/file', 0, 10485761);
    }
}
