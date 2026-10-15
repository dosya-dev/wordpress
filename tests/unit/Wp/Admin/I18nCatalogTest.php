<?php
namespace Dosya\Backup\Tests\unit\Wp\Admin;

use Dosya\Backup\Wp\Admin\AdminPage;
use PHPUnit\Framework\TestCase;

/** Every string the view models pass to $t() must be listed in I18nCatalog, so `wp i18n make-pot` extracts it. */
final class I18nCatalogTest extends TestCase
{
    /** @return string[] */
    private static function viewModelStrings(): array
    {
        $out = [];
        // ListPayload is pure too and hands its fallback causes to the same translator
        $files = array_merge(SourceStrings::files(SourceStrings::root() . '/src/Wp/Admin/ViewModel'), [SourceStrings::root() . '/src/Wp/Admin/ListPayload.php']);
        foreach ($files as $file) {
            foreach (SourceStrings::literalArgs((string) file_get_contents($file), ['$t']) as $s) {
                $out[$s] = true;
            }
        }
        return array_keys($out);
    }

    /** @return string[] */
    private static function catalogStrings(): array
    {
        $file = SourceStrings::root() . '/src/Wp/Admin/I18nCatalog.php';
        self::assertFileExists($file);
        $src = (string) file_get_contents($file);
        preg_match_all('/__\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")\s*,\s*\'dosya-backup-restore-migration\'\s*\)/s', $src, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $hit) {
            $out[$hit[1] !== '' ? SourceStrings::decode($hit[1], "'") : SourceStrings::decode($hit[2] ?? '', '"')] = true;
        }
        return array_keys($out);
    }

    /** The localized JS strings are passed to __() with a variable, which make-pot cannot read, so the catalog carries them too. */
    public function testCatalogHoldsEveryViewModelString(): void
    {
        $want = self::viewModelStrings();
        self::assertNotEmpty($want);
        $missing = array_values(array_diff($want, self::catalogStrings()));
        self::assertSame([], $missing, "ViewModel strings missing from I18nCatalog:\n" . implode("\n", $missing));
    }

    public function testCatalogHoldsEveryScriptString(): void
    {
        $missing = array_values(array_diff(array_values(AdminPage::i18nKeys()), self::catalogStrings()));
        self::assertSame([], $missing, "AdminPage::i18nKeys() strings missing from I18nCatalog:\n" . implode("\n", $missing));
    }

    /** translate() looks strings up by key, so a key that differs from its literal would silently fall back to English. */
    public function testEveryKeyRepeatsItsOwnLiteral(): void
    {
        $src = (string) file_get_contents(SourceStrings::root() . '/src/Wp/Admin/I18nCatalog.php');
        preg_match_all('/^\s+(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*=>\s*__\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*,/m', $src, $m, PREG_SET_ORDER);
        self::assertSame(count(self::catalogStrings()), count($m), 'every catalog entry is written as key => __(literal, domain)');
        foreach ($m as $hit) {
            self::assertSame($hit[2], $hit[1], 'catalog key must repeat the literal');
        }
    }

    public function testCatalogHasNothingElse(): void
    {
        $known = array_merge(self::viewModelStrings(), array_values(AdminPage::i18nKeys()));
        $extra = array_values(array_diff(self::catalogStrings(), $known));
        self::assertSame([], $extra, "I18nCatalog strings no view model or script uses:\n" . implode("\n", $extra));
    }
}
