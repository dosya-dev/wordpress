<?php
namespace Dosya\Backup\Tests\unit\Wp\Admin\ViewModel;

use Dosya\Backup\Wp\Admin\ViewModel\Format;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    private $t;

    protected function setUp(): void { $this->t = static function (string $s): string { return $s; }; }

    public function testBytes(): void
    {
        $this->assertSame('1.2 GB', Format::bytes(1288490188));
        $this->assertSame('62 MB', Format::bytes(62 * 1048576));
        $this->assertSame('38 MB', Format::bytes(39845888));
        $this->assertSame('1.1 GB', Format::bytes(1181116006));
        $this->assertSame('2.4 GB', Format::bytes(2576980378));
        $this->assertSame('512 B', Format::bytes(512));
    }

    public function testRelative(): void
    {
        $now = 1791800000;
        $this->assertSame('just now', Format::relative($now + 100, $now, $this->t));
        $this->assertSame('just now', Format::relative($now - 30, $now, $this->t));
        $this->assertSame('1 minute ago', Format::relative($now - 60, $now, $this->t));
        $this->assertSame('5 minutes ago', Format::relative($now - 300, $now, $this->t));
        $this->assertSame('1 hour ago', Format::relative($now - 3600, $now, $this->t));
        $this->assertSame('2 hours ago', Format::relative($now - 7200, $now, $this->t));
        $this->assertSame('yesterday', Format::relative($now - 90000, $now, $this->t));
        $this->assertSame('3 days ago', Format::relative($now - 3 * 86400, $now, $this->t));
    }

    public function testWhen(): void
    {
        $now = (new \DateTimeImmutable('2026-10-12 09:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $at = static function (string $s): int { return (new \DateTimeImmutable($s, new \DateTimeZone('UTC')))->getTimestamp(); };
        $this->assertSame('Today 03:00', Format::when($at('2026-10-12 03:00:00'), $now, 'UTC', $this->t));
        $this->assertSame('Yesterday 03:00', Format::when($at('2026-10-11 03:00:00'), $now, 'UTC', $this->t));
        $this->assertSame('9 Oct 14:20', Format::when($at('2026-10-09 14:20:00'), $now, 'UTC', $this->t));
        $this->assertSame('11 Oct 14:20', Format::when($at('2026-10-11 14:20:00'), $at('2026-10-13 09:00:00'), 'UTC', $this->t));
        // Timezone is honoured: 23:30 UTC is already the next day in Tokyo.
        $this->assertSame('Today 08:30', Format::when($at('2026-10-11 23:30:00'), $now, 'Asia/Tokyo', $this->t));
    }

    public function testBytesBoundaries(): void
    {
        $this->assertSame('1023 B', Format::bytes(1023));
        $this->assertSame('1 KB', Format::bytes(1024));
        $this->assertSame('100 MB', Format::bytes(104805173));
        $this->assertSame('1 GB', Format::bytes(1073689395));
        $this->assertSame('0 B', Format::bytes(0));
    }

    public function testWhenLowercaseAndTomorrow(): void
    {
        $u = new \DateTimeZone('UTC');
        $now = (new \DateTimeImmutable('2026-10-12 09:00:00', $u))->getTimestamp();
        $at = static function (string $s) use ($u): int { return (new \DateTimeImmutable($s, $u))->getTimestamp(); };
        $this->assertSame('today 03:00', Format::when($at('2026-10-12 03:00:00'), $now, 'UTC', $this->t, false));
        $this->assertSame('yesterday 03:00', Format::when($at('2026-10-11 03:00:00'), $now, 'UTC', $this->t, false));
        $this->assertSame('tomorrow 03:00', Format::when($at('2026-10-13 03:00:00'), $now, 'UTC', $this->t, false));
        $this->assertSame('Tomorrow 03:00', Format::when($at('2026-10-13 03:00:00'), $now, 'UTC', $this->t));
        $this->assertSame('20 Oct 03:00', Format::when($at('2026-10-20 03:00:00'), $now, 'UTC', $this->t, false));
    }

    public function testWhenUsesInjectedDateFormatter(): void
    {
        $now = 1791800000;
        $fmt = static function (int $ts, string $pattern): string { return 'D(' . $pattern . ')'; };
        $this->assertSame('D(j M) ' . gmdate('H:i', $now - 10 * 86400), Format::when($now - 10 * 86400, $now, 'UTC', $this->t, true, $fmt));
    }

    public function testWhenAcrossBerlinDstChange(): void
    {
        $z = new \DateTimeZone('Europe/Berlin');
        $now = (new \DateTimeImmutable('2026-03-29 10:00:00', $z))->getTimestamp();
        $at = static function (string $s) use ($z): int { return (new \DateTimeImmutable($s, $z))->getTimestamp(); };
        $this->assertSame('Yesterday 03:00', Format::when($at('2026-03-28 03:00:00'), $now, 'Europe/Berlin', $this->t));
        $this->assertSame('Today 03:30', Format::when($at('2026-03-29 03:30:00'), $now, 'Europe/Berlin', $this->t));
        $this->assertSame('Tomorrow 03:00', Format::when($at('2026-03-30 03:00:00'), $now, 'Europe/Berlin', $this->t));
    }
}
