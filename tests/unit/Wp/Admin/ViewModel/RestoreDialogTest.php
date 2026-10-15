<?php
namespace Dosya\Backup\Tests\unit\Wp\Admin\ViewModel;

use Dosya\Backup\Wp\Admin\ViewModel\RestoreDialog;
use PHPUnit\Framework\TestCase;

final class RestoreDialogTest extends TestCase
{
    private const NOW = 1791767600; // 2026-10-12 06:13 UTC

    private function t(): callable { return static function (string $s): string { return $s; }; }

    public function testSameSiteVariantIgnoresSchemeAndSlash(): void
    {
        $this->assertTrue(RestoreDialog::sameSite('http://example.com/', 'https://example.com'));
        $this->assertTrue(RestoreDialog::sameSite('https://www.example.com', 'https://example.com/'));
        $this->assertFalse(RestoreDialog::sameSite('https://shop.example.net', 'https://example.com'));
        $this->assertTrue(RestoreDialog::sameSite(null, 'https://example.com'), 'no origin means a local backup of this site');
        $this->assertTrue(RestoreDialog::sameSite('', 'https://example.com'));
        $this->assertTrue(RestoreDialog::sameSite('HTTPS://Example.COM', 'https://example.com'));
    }

    public function testSameSiteDialog(): void
    {
        $d = RestoreDialog::build(['id' => 'a', 'created' => self::NOW - 90000, 'size' => 1181116006, 'note' => 'before theme update', 'origin' => 'https://example.com', 'scope' => 'full', 'kind' => 'full', 'remote_state' => 'verified', 'is_uploaded' => false],
            ['now' => self::NOW, 'tz' => 'UTC', 'home_url' => 'https://example.com', 'domain' => 'example.com', 'safety_copy' => true], $this->t());
        $this->assertSame('same', $d['variant']);
        $this->assertSame('Restore the backup from Yesterday 00:13?', $d['title']);
        $this->assertStringContainsString('This replaces the current example.com with the site as it was then:', $d['consequence']);
        $this->assertSame(['Database: posts, pages, settings, users, orders', 'Files: themes, plugins, uploads'], $d['includes']);
        $this->assertStringContainsString('A safety copy of the current site is made first.', $d['safety']);
        $this->assertFalse($d['type_to_confirm']);
        $this->assertFalse($d['show_address']);
        $this->assertSame(['cancel' => 'Keep current site', 'go' => 'Restore site'], $d['buttons']);
        $this->assertStringContainsString('1.1 GB', $d['facts']);
        $this->assertStringContainsString('before theme update', $d['facts']);
    }

    public function testMigrationDialog(): void
    {
        $d = RestoreDialog::build(['id' => 'a', 'created' => self::NOW - 200000, 'size' => 2576980378, 'note' => '', 'origin' => 'https://shop.example.net', 'scope' => 'full', 'kind' => 'full', 'remote_state' => 'none', 'is_uploaded' => true],
            ['now' => self::NOW, 'tz' => 'UTC', 'home_url' => 'https://example.com', 'domain' => 'example.com', 'safety_copy' => true], $this->t());
        $this->assertSame('migration', $d['variant']);
        $this->assertSame('Move shop.example.net onto this site?', $d['title']);
        $this->assertTrue($d['show_address']); $this->assertSame('https://example.com', $d['address_default']);
        $this->assertTrue($d['type_to_confirm']); $this->assertSame('example.com', $d['confirm_domain']);
        $this->assertSame('Replace this site', $d['buttons']['go']);
        $this->assertStringContainsString('This replaces everything on example.com with shop.example.net', $d['consequence']);
    }

    public function testTypeToConfirmWhenSafetyCopyOff(): void
    {
        $backup = ['id' => 'a', 'created' => self::NOW - 90000, 'size' => 1181116006, 'note' => '', 'origin' => 'https://example.com', 'scope' => 'full', 'kind' => 'full', 'remote_state' => 'none', 'is_uploaded' => false];
        $ctx = ['now' => self::NOW, 'tz' => 'UTC', 'home_url' => 'https://example.com', 'domain' => 'example.com', 'safety_copy' => false];
        $d = RestoreDialog::build($backup, $ctx, $this->t());
        $this->assertSame('same', $d['variant']);
        $this->assertTrue($d['type_to_confirm']);
        $this->assertNull($d['safety']);
        $this->assertSame('Restore site', $d['buttons']['go']);

        $backup['origin'] = 'https://shop.example.net';
        $d = RestoreDialog::build($backup, $ctx, $this->t());
        $this->assertTrue($d['type_to_confirm']);
        $this->assertNull($d['safety']);
        $this->assertSame('Replace this site', $d['buttons']['go']);
    }

    public function testIncludesFollowScope(): void
    {
        $ctx = ['now' => self::NOW, 'tz' => 'UTC', 'home_url' => 'https://example.com', 'domain' => 'example.com', 'safety_copy' => true];
        $b = ['id' => 'a', 'created' => self::NOW - 90000, 'size' => 1, 'note' => '', 'origin' => null, 'scope' => 'db', 'kind' => 'db', 'remote_state' => 'none', 'is_uploaded' => false];
        $this->assertSame(['Database: posts, pages, settings, users, orders'], RestoreDialog::build($b, $ctx, $this->t())['includes']);
        $b['scope'] = 'files';
        $this->assertSame(['Files: themes, plugins, uploads'], RestoreDialog::build($b, $ctx, $this->t())['includes']);
    }

    public function testNoEmDashes(): void
    {
        $b = ['id' => 'a', 'created' => self::NOW - 90000, 'size' => 1, 'note' => '', 'origin' => 'https://x.test', 'scope' => 'full', 'kind' => 'full', 'remote_state' => 'verified', 'is_uploaded' => true];
        $d = RestoreDialog::build($b, ['now' => self::NOW, 'tz' => 'UTC', 'home_url' => 'https://example.com', 'domain' => 'example.com', 'safety_copy' => true], $this->t());
        $this->assertStringNotContainsString("\u{2014}", json_encode($d, JSON_UNESCAPED_UNICODE));
    }

    public function testSameSiteComparesPortAndPath(): void
    {
        $this->assertFalse(RestoreDialog::sameSite('https://example.com:8080', 'https://example.com'));
        $this->assertFalse(RestoreDialog::sameSite('https://example.com/blog', 'https://example.com'));
        $this->assertTrue(RestoreDialog::sameSite('https://example.com/blog/', 'http://www.example.com/blog'));
        $this->assertTrue(RestoreDialog::sameSite('http://example.com:80', 'https://example.com:443'));
        $this->assertFalse(RestoreDialog::sameSite('http://', 'https://example.com'), 'unparsable origin is a different site');
        $this->assertFalse(RestoreDialog::sameSite('///', 'https://example.com'));
    }

    public function testFactsLineLeadsWithTypeAndPartsAndLabels(): void
    {
        $ctx = ['now' => self::NOW, 'tz' => 'UTC', 'home_url' => 'https://example.com', 'domain' => 'example.com', 'safety_copy' => true];
        $b = ['id' => 'a', 'created' => self::NOW - 90000, 'size' => 1181116006, 'note' => 'n', 'origin' => 'https://example.com', 'scope' => 'full', 'kind' => 'full', 'remote_state' => 'verified', 'is_uploaded' => false];
        $d = RestoreDialog::build($b, $ctx, $this->t());
        $this->assertSame('%1$s · %2$s', '%1$s · %2$s');
        $this->assertSame('Full backup · 1.1 GB · n · Stored in dosya, verified restorable', $d['facts']);
        $this->assertSame(['before' => 'This replaces the current ', 'domain' => 'example.com', 'after' => ' with the site as it was then:'], $d['consequence_parts']);
        $this->assertSame('Site address after restore', $d['labels']['address']);
        $this->assertSame("Type the current site's domain to confirm", $d['labels']['confirm']);
        $b['kind'] = 'incremental';
        $this->assertStringStartsWith('Incremental backup · ', RestoreDialog::build($b, $ctx, $this->t())['facts']);
        $b['kind'] = 'db'; $b['scope'] = 'db';
        $this->assertStringStartsWith('Database backup · ', RestoreDialog::build($b, $ctx, $this->t())['facts']);
        $b['origin'] = 'https://shop.example.net'; $b['kind'] = 'full'; $b['scope'] = 'full';
        $m = RestoreDialog::build($b, $ctx, $this->t());
        $this->assertSame('This replaces everything on ', $m['consequence_parts']['before']);
        $this->assertStringContainsString("shop.example.net's database and files.", $m['consequence_parts']['after']);
    }

    public function testSameSiteShowsLinkRewriteWhenSchemeOrWwwChanged(): void
    {
        $ctx = ['now' => self::NOW, 'tz' => 'UTC', 'home_url' => 'https://example.com', 'domain' => 'example.com', 'safety_copy' => true];
        $b = ['id' => 'a', 'created' => self::NOW - 90000, 'size' => 1, 'note' => '', 'origin' => 'http://example.com', 'scope' => 'full', 'kind' => 'full', 'remote_state' => 'none', 'is_uploaded' => false];
        $d = RestoreDialog::build($b, $ctx, $this->t());
        $this->assertSame('same', $d['variant']);
        $this->assertTrue($d['show_address']);
        $this->assertSame('https://example.com', $d['address_default']);
        $this->assertSame('Links are updated from http://example.com to https://example.com.', $d['url_change']);
        $this->assertFalse($d['type_to_confirm']);

        $b['origin'] = 'https://www.example.com';
        $this->assertNotNull(RestoreDialog::build($b, $ctx, $this->t())['url_change']);

        foreach (['https://example.com', 'https://example.com/', 'HTTPS://EXAMPLE.COM', null] as $o) {
            $b['origin'] = $o;
            $d = RestoreDialog::build($b, $ctx, $this->t());
            $this->assertNull($d['url_change'], (string) $o);
            $this->assertFalse($d['show_address'], (string) $o);
        }

        $b['origin'] = 'https://shop.example.net';
        $d = RestoreDialog::build($b, $ctx, $this->t());
        $this->assertSame('migration', $d['variant']);
        $this->assertNull($d['url_change']);
        $this->assertTrue($d['show_address']);
    }
}
