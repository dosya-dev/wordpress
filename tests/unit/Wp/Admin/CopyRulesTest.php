<?php
namespace Dosya\Backup\Tests\unit\Wp\Admin;

use Dosya\Backup\Wp\Admin\AdminPage;
use PHPUnit\Framework\TestCase;

/** Copy rules (spec section 10): no dashes, no advertising words, except the sentences listed here. */
final class CopyRulesTest extends TestCase
{
    /** The only commercial sentences allowed anywhere in the admin copy. */
    private const ALLOWED = [
        'Free plan: 1 site, 5 GB, 7 days',
        'plans start at 50 GB on dosya.dev',
        'Restoring is free at any size.',
    ];

    /** @return array<string,true> */
    private static function allStrings(): array
    {
        $out = [];
        foreach (AdminPage::i18nKeys() as $s) {
            $out[$s] = true;
        }
        $catalog = SourceStrings::root() . '/src/Wp/Admin/I18nCatalog.php';
        if (is_file($catalog)) {
            foreach (SourceStrings::literalArgs((string) file_get_contents($catalog), ['__']) as $s) {
                $out[$s] = true;
            }
        }
        $files = array_merge(
            SourceStrings::files(SourceStrings::root() . '/src/Wp/Admin/templates'),
            glob(SourceStrings::root() . '/src/Wp/Admin/*.php') ?: []
        );
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            foreach (SourceStrings::literalArgs($src, ['__', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e', '_e']) as $s) {
                $out[$s] = true;
            }
        }
        return $out;
    }

    public function testCollectsStrings(): void
    {
        self::assertGreaterThan(200, count(self::allStrings()));
    }

    public function testAllowedSentencesAreInTheCopy(): void
    {
        $all = implode("\n", array_keys(self::allStrings()));
        foreach (self::ALLOWED as $sentence) {
            self::assertStringContainsString($sentence, $all, 'allowed sentence not found in the collected strings: ' . $sentence);
        }
    }

    public function testNoEmDashOrEnDash(): void
    {
        $bad = [];
        foreach (array_keys(self::allStrings()) as $s) {
            if (strpos($s, "\u{2014}") !== false || strpos($s, "\u{2013}") !== false) {
                $bad[] = $s;
            }
        }
        self::assertSame([], $bad, "Strings with an em or en dash:\n" . implode("\n", $bad));
    }

    public function testNoAdvertisingWords(): void
    {
        $bad = [];
        foreach (array_keys(self::allStrings()) as $s) {
            $check = str_replace(self::ALLOWED, '', $s);
            if (preg_match('/\b(upgrade|premium|pro|unlock)\b|buy now/i', $check, $m)) {
                $bad[] = '"' . $m[0] . '" in: ' . $s;
            }
        }
        self::assertSame([], $bad, "Strings with advertising words:\n" . implode("\n", $bad));
    }
}
