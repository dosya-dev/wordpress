<?php
namespace Dosya\Backup\Tests\unit\Wp\Admin;

use Dosya\Backup\Wp\Admin\AdminPage;
use Dosya\Backup\Wp\Admin\ViewModel\RunningCard;
use PHPUnit\Framework\TestCase;

/** The JS phase table in assets/js/api.js must carry exactly the keys and values of RunningCard::PHASES. */
final class PhasesSyncTest extends TestCase
{
    /** @return array<string,string> */
    private static function jsPhases(): array
    {
        $file = dirname(__DIR__, 4) . '/assets/js/api.js';
        self::assertFileExists($file);
        $src = (string) file_get_contents($file);
        self::assertSame(1, preg_match('/var PHASES = \{(.*?)\};/s', $src, $m), 'PHASES object not found in api.js');
        preg_match_all("/'([a-z_.]+)'\s*:\s*'([a-z_]+)'/", $m[1], $pairs, PREG_SET_ORDER);
        $out = [];
        foreach ($pairs as $p) {
            $out[$p[1]] = $p[2];
        }
        return $out;
    }

    public function testJsPhaseKeysMatchRunningCard(): void
    {
        $js = array_keys(self::jsPhases());
        $php = array_keys(RunningCard::PHASES);
        sort($js);
        sort($php);
        self::assertSame($php, $js);
    }

    public function testJsPhaseValuesMatchRunningCard(): void
    {
        $js = self::jsPhases();
        ksort($js);
        $php = RunningCard::PHASES;
        ksort($php);
        self::assertSame($php, $js);
    }

    public function testEveryI18nKeyUsedByTheScriptsIsLocalized(): void
    {
        $keys = AdminPage::i18nKeys();
        foreach (['api.js', 'backups.js', 'restore.js', 'schedule.js', 'dosya.js'] as $name) {
            $src = (string) file_get_contents(dirname(__DIR__, 4) . '/assets/js/' . $name);
            preg_match_all("/i18n\('([a-z0-9_]+)'/", $src, $m);
            foreach (array_unique($m[1]) as $key) {
                self::assertArrayHasKey($key, $keys, $name . ' uses an i18n key AdminPage::i18nKeys() lacks: ' . $key);
            }
        }
    }

    public function testEveryPhaseTextIsUsedByAPhase(): void
    {
        $used = array_unique(array_values(RunningCard::PHASES));
        $used[] = 'phase_working'; // the fallback for a path the table does not know
        $orphans = array_values(array_diff(array_keys(RunningCard::PHASE_TEXT), $used));
        self::assertSame([], $orphans, 'PHASE_TEXT keys no PHASES value points at: ' . implode(', ', $orphans));
    }
}
