<?php
namespace Dosya\Backup\Tests\unit\Wp;

use Dosya\Backup\Wp\SettingsSchema;
use PHPUnit\Framework\TestCase;

final class SettingsSchemaTest extends TestCase
{
    public function testMissingKeysKeepCurrentValues(): void
    {
        $current = SettingsSchema::DEFAULTS;
        $current['volume_mb'] = 128;
        $out = SettingsSchema::normalize(['schedule' => 'weekly', 'keep' => '5', 'excludes' => ["*.log"]], $current);
        $this->assertSame('weekly', $out['schedule']);
        $this->assertSame(5, $out['keep']);
        $this->assertSame(128, $out['volume_mb'], 'a UI save that omits volume_mb must not reset it');
        $this->assertSame('03:00', $out['time']);
        $this->assertTrue($out['safety_copy']);
    }

    public function testInvalidValuesFallBackToCurrent(): void
    {
        $current = SettingsSchema::DEFAULTS;
        $out = SettingsSchema::normalize(['schedule' => 'hourly', 'time' => '25:99', 'keep' => '0', 'volume_mb' => '9999'], $current);
        $this->assertSame('daily', $out['schedule']);
        $this->assertSame('03:00', $out['time']);
        $this->assertSame(1, $out['keep'], 'keep clamps to 1..30');
        $this->assertSame(256, $out['volume_mb'], 'volume_mb clamps to 16..256');
    }

    public function testBooleansAcceptStrings(): void
    {
        $out = SettingsSchema::normalize(['safety_copy' => '0', 'email_fail' => '1', 'email_ok' => 'true'], SettingsSchema::DEFAULTS);
        $this->assertFalse($out['safety_copy']);
        $this->assertTrue($out['email_fail']);
        $this->assertTrue($out['email_ok']);
    }

    public function testTimeAcceptsHHMM(): void
    {
        $out = SettingsSchema::normalize(['time' => '23:30'], SettingsSchema::DEFAULTS);
        $this->assertSame('23:30', $out['time']);
    }

    public function testExcludesAreCleanedAndCapped(): void
    {
        $in = array_fill(0, 150, 'wp-content/cache/');
        $in[] = "bad\x00glob[";
        $out = SettingsSchema::normalize(['excludes' => $in], SettingsSchema::DEFAULTS);
        $this->assertLessThanOrEqual(100, count($out['excludes']));
        $this->assertSame(['wp-content/cache/'], array_values(array_unique($out['excludes'])));
    }

    public function testEmptyOrNullVolumeKeepsCurrent(): void
    {
        $cur = array_merge(SettingsSchema::DEFAULTS, ['volume_mb' => 128]);
        $this->assertSame(128, SettingsSchema::normalize(['volume_mb' => ''], $cur)['volume_mb']);
        $this->assertSame(128, SettingsSchema::normalize(['volume_mb' => null], $cur)['volume_mb']);
    }

    public function testStoredOffStaysOffWhenScheduleOmitted(): void
    {
        $cur = array_merge(SettingsSchema::DEFAULTS, ['schedule' => 'off']);
        $this->assertSame('off', SettingsSchema::normalize(['keep' => '4'], $cur)['schedule']);
    }

    public function testAbsentFlagsAndExcludesKeepCurrent(): void
    {
        $cur = array_merge(SettingsSchema::DEFAULTS, ['safety_copy' => false, 'email_ok' => true, 'excludes' => ['*.log']]);
        $out = SettingsSchema::normalize(['keep' => '4'], $cur);
        $this->assertFalse($out['safety_copy']);
        $this->assertTrue($out['email_ok']);
        $this->assertSame(['*.log'], $out['excludes']);
    }

    public function testBadFlagFallsBackToCurrent(): void
    {
        $cur = array_merge(SettingsSchema::DEFAULTS, ['safety_copy' => false, 'email_fail' => true]);
        $out = SettingsSchema::normalize(['safety_copy' => 'maybe', 'email_fail' => [1]], $cur);
        $this->assertFalse($out['safety_copy']);
        $this->assertTrue($out['email_fail']);
    }
}
