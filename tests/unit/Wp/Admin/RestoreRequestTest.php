<?php
namespace Dosya\Backup\Tests\unit\Wp\Admin;

use Dosya\Backup\Wp\Admin\RestoreRequest;
use Dosya\Backup\Wp\Admin\ViewModel\RestoreDialog;
use PHPUnit\Framework\TestCase;

final class RestoreRequestTest extends TestCase
{
    private static function vm(?string $origin, bool $safety = true): array
    {
        return RestoreDialog::build(['created' => 1700000000, 'scope' => 'full', 'origin' => $origin],
            ['now' => 1700000000, 'tz' => 'UTC', 'home_url' => 'https://example.com', 'domain' => 'example.com', 'safety_copy' => $safety],
            static function (string $s): string { return $s; });
    }

    public function testMissingConfirmIsRefused(): void
    {
        self::assertSame('confirm', RestoreRequest::validate(self::vm(null), ['scope' => 'full'], 'example.com'));
        self::assertSame('confirm', RestoreRequest::validate(self::vm(null), ['confirm' => 'yes'], 'example.com'));
    }

    public function testSameSiteWithSafetyCopyNeedsNoTypedDomain(): void
    {
        self::assertSame(['scope' => 'db'], RestoreRequest::validate(self::vm(null), ['confirm' => '1', 'scope' => 'db'], 'example.com'));
    }

    public function testMigrationWithoutTypedDomainIsRefused(): void
    {
        self::assertSame('domain', RestoreRequest::validate(self::vm('https://old.test'), ['confirm' => '1', 'scope' => 'full'], 'example.com'));
    }

    public function testMigrationWithWrongDomainIsRefused(): void
    {
        self::assertSame('domain', RestoreRequest::validate(self::vm('https://old.test'),
            ['confirm' => '1', 'confirm_domain' => 'old.test'], 'example.com'));
    }

    public function testMigrationAcceptsTheDomainCaseInsensitiveAndTrimmed(): void
    {
        $opts = RestoreRequest::validate(self::vm('https://old.test'),
            ['confirm' => '1', 'confirm_domain' => '  Example.COM ', 'scope' => 'full', 'new_url' => 'https://example.com/new'], 'example.com');
        self::assertSame(['scope' => 'full', 'new_url' => 'https://example.com/new'], $opts);
    }

    public function testSafetyCopyOffRequiresTheDomainOnTheSameSite(): void
    {
        $vm = self::vm(null, false);
        self::assertSame('domain', RestoreRequest::validate($vm, ['confirm' => '1'], 'example.com'));
        self::assertSame(['scope' => 'full'], RestoreRequest::validate($vm, ['confirm' => '1', 'confirm_domain' => 'example.com'], 'example.com'));
    }

    public function testNewUrlIsIgnoredWhenTheAddressFieldIsHidden(): void
    {
        $vm = self::vm(null);
        self::assertFalse($vm['show_address']);
        self::assertSame(['scope' => 'full'], RestoreRequest::validate($vm, ['confirm' => '1', 'new_url' => 'https://evil.test'], 'example.com'));
    }

    public function testUnknownScopeFallsBackToFull(): void
    {
        self::assertSame(['scope' => 'full'], RestoreRequest::validate(self::vm(null), ['confirm' => '1', 'scope' => 'everything'], 'example.com'));
    }

    public function testEmptyDomainNeverMatches(): void
    {
        self::assertSame('domain', RestoreRequest::validate(self::vm('https://old.test'), ['confirm' => '1', 'confirm_domain' => ''], ''));
    }

    public function testFilesOnlyRestoreHasNoSafetyCopySoNeedsTheDomain(): void
    {
        $vm = self::vm(null);
        self::assertFalse($vm['type_to_confirm']);
        self::assertSame('domain', RestoreRequest::validate($vm, ['confirm' => '1', 'scope' => 'files'], 'example.com'));
        self::assertSame('domain', RestoreRequest::validate($vm, ['confirm' => '1', 'scope' => 'files', 'confirm_domain' => 'other.test'], 'example.com'));
        self::assertSame(['scope' => 'files'], RestoreRequest::validate($vm, ['confirm' => '1', 'scope' => 'files', 'confirm_domain' => 'EXAMPLE.com'], 'example.com'));
    }

    public function testDialogInputFallsBackToTheManifestHomeWithoutASidecarOrigin(): void
    {
        $row = ['id' => 'x', 'created' => 1700000000, 'kind' => 'full', 'scope' => 'full', 'size' => 10, 'note' => '', 'origin' => null, 'uploaded' => true];
        $in = RestoreRequest::dialogInput($row, null, 'https://old.test');
        self::assertSame('https://old.test', $in['origin']);
        self::assertSame('none', $in['remote_state']);
        self::assertTrue($in['is_uploaded']);
        $vm = RestoreDialog::build($in, ['now' => 1700000000, 'tz' => 'UTC', 'home_url' => 'https://example.com', 'domain' => 'example.com',
            'safety_copy' => true], static function (string $s): string { return $s; });
        self::assertSame('migration', $vm['variant']);
    }

    public function testDialogInputPrefersTheSidecarOrigin(): void
    {
        $row = ['id' => 'x', 'created' => 1, 'kind' => 'full', 'scope' => 'database', 'size' => 1, 'note' => 'n', 'origin' => 'https://example.com', 'uploaded' => false];
        $in = RestoreRequest::dialogInput($row, ['verified' => true], 'https://old.test');
        self::assertSame('https://example.com', $in['origin']);
        self::assertSame('db', $in['scope']);
        self::assertSame('verified', $in['remote_state']);
        self::assertSame('uploaded', RestoreRequest::dialogInput($row, ['verified' => false], null)['remote_state']);
    }

    public function testDialogInputWithoutAnyOriginIsSameSite(): void
    {
        $row = ['id' => 'x', 'created' => 1, 'kind' => 'full', 'scope' => 'full', 'size' => 1, 'note' => '', 'origin' => null, 'uploaded' => false];
        self::assertNull(RestoreRequest::dialogInput($row, null, null)['origin']);
        self::assertNull(RestoreRequest::dialogInput($row, null, '  ')['origin']);
    }
}
