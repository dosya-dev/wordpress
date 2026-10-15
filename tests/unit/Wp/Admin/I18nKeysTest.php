<?php
namespace Dosya\Backup\Tests\unit\Wp\Admin;

use Dosya\Backup\Wp\Admin\AdminPage;
use Dosya\Backup\Wp\Admin\ViewModel\RunningCard;
use PHPUnit\Framework\TestCase;

/** The i18n keys the scripts use and AdminPage::i18nKeys() localizes must match both ways. */
final class I18nKeysTest extends TestCase
{
    /** @return array<string,string> js file name => source */
    private static function scripts(): array
    {
        $out = [];
        foreach (SourceStrings::files(SourceStrings::root() . '/assets/js', 'js') as $file) {
            $out[basename($file)] = (string) file_get_contents($file);
        }
        self::assertNotEmpty($out);
        return $out;
    }

    /**
     * Literal keys of every i18n(...) call: a plain quoted first argument, or both branches of a ternary first argument.
     *
     * @return string[]
     */
    public static function literalKeys(string $src): array
    {
        $keys = [];
        preg_match_all('/(?<![A-Za-z0-9_$.])i18n\(/', $src, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as $hit) {
            $i = $hit[1] + strlen($hit[0]);
            $arg = '';
            $quote = '';
            for ($n = strlen($src); $i < $n; $i++) {
                $c = $src[$i];
                if ($quote !== '') {
                    $arg .= $c;
                    if ($c === '\\') {
                        $arg .= $src[++$i] ?? '';
                    } elseif ($c === $quote) {
                        $quote = '';
                    }
                    continue;
                }
                if ($c === ',' || $c === ')') {
                    break;
                }
                if ($c === "'" || $c === '"') {
                    $quote = $c;
                }
                $arg .= $c;
            }
            $q = strpos($arg, '?');
            if ($q !== false) {
                $arg = substr($arg, $q + 1); // the condition may hold literals of its own; only the branches are keys
            }
            preg_match_all('/\'([^\']*)\'|"([^"]*)"/', $arg, $lits, PREG_SET_ORDER);
            foreach ($lits as $l) {
                $keys[] = ($l[1] ?? '') !== '' ? $l[1] : ($l[2] ?? '');
            }
        }
        return array_values(array_unique($keys));
    }

    public function testLiteralScanReadsTernaryBranchesAndDoubleQuotes(): void
    {
        $src = "x = i18n(s.state === 'skipped' ? 'skipped_prefix' : 'failed_prefixx', cause); y = i18n(\"dq_key\"); z = i18n(key, a);";
        self::assertSame(['skipped_prefix', 'failed_prefixx', 'dq_key'], self::literalKeys($src));
        $known = AdminPage::i18nKeys();
        self::assertArrayNotHasKey('failed_prefixx', $known, 'a misspelled ternary branch must be reported as missing');
    }

    public function testEveryLiteralI18nCallHasAKey(): void
    {
        $keys = AdminPage::i18nKeys();
        $unknown = [];
        $seen = 0;
        foreach (self::scripts() as $name => $src) {
            foreach (self::literalKeys($src) as $key) {
                $seen++;
                if (!array_key_exists($key, $keys)) {
                    $unknown[] = $name . ': ' . $key;
                }
            }
        }
        self::assertGreaterThan(20, $seen, 'the scan found too few literal i18n() calls');
        self::assertSame([], $unknown, "i18n keys used in JS but missing from AdminPage::i18nKeys():\n" . implode("\n", $unknown));
    }

    public function testNoDeadKeys(): void
    {
        $all = implode("\n", self::scripts());
        $dead = [];
        foreach (array_keys(AdminPage::i18nKeys()) as $key) {
            if (array_key_exists($key, RunningCard::PHASE_TEXT)) {
                continue; // phase keys are resolved through the PHASES table in api.js
            }
            if (strpos($all, "'" . $key . "'") === false) {
                $dead[] = $key;
            }
        }
        self::assertSame([], $dead, "Dead i18n keys (no script references them):\n" . implode("\n", $dead));
    }
}
