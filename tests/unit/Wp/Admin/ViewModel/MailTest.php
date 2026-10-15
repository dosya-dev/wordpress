<?php
namespace Dosya\Backup\Tests\unit\Wp\Admin\ViewModel;

use Dosya\Backup\Wp\Admin\ViewModel\Mail;
use PHPUnit\Framework\TestCase;

final class MailTest extends TestCase
{
    private $t;

    protected function setUp(): void { $this->t = static function (string $s): string { return $s; }; }

    private function ctx(array $o = []): array
    {
        return $o + ['site_name' => 'Acme', 'admin_url' => 'https://acme.test/wp-admin/admin.php?page=dosya-backup',
            'last_good' => ['created_text' => 'yesterday at 03:00', 'stored_phrase' => 'verified in dosya'],
            'email_fail' => true, 'email_ok' => false, 'now' => 1760000000, 'tz' => 'UTC'];
    }

    private function status(string $state, ?string $error = null, ?array $result = null, string $kind = 'backup'): array
    {
        return ['kind' => $kind, 'state' => $state, 'error' => $error, 'result' => $result];
    }

    public function testFailedSubjectAndBody(): void
    {
        $m = Mail::build($this->status('failed', 'Not enough disk space for a local backup: about 1 GB needed, 2 MB free.'), $this->ctx(), $this->t);
        $this->assertStringContainsString('Backup failed:', $m['subject']);
        $this->assertStringContainsString('[Acme]', $m['subject']);
        $this->assertStringContainsString('Not enough disk space', $m['subject']);
        $this->assertStringContainsString('Your last good backup is from yesterday at 03:00 and is verified in dosya.', $m['body']);
        $this->assertStringContainsString('Free up disk space or exclude folders under Schedule, then retry.', $m['body']);
        $this->assertStringContainsString("\nhttps://acme.test/wp-admin/admin.php?page=dosya-backup\n", $m['body']);
        $this->assertStringNotContainsString("\u{2014}", $m['body'] . $m['subject']);
        $this->assertStringNotContainsString('<', $m['body']);
    }

    public function testCauseTruncatedAt120(): void
    {
        $m = Mail::build($this->status('failed', str_repeat('x', 300)), $this->ctx(), $this->t);
        $this->assertStringContainsString(str_repeat('x', 120) . '...', $m['subject']);
        $this->assertStringNotContainsString(str_repeat('x', 121), $m['subject']);
    }

    public function testNoEarlierBackupAndGenericFix(): void
    {
        $m = Mail::build($this->status('failed', 'boom'), $this->ctx(['last_good' => null]), $this->t);
        $this->assertStringContainsString('No earlier backup exists yet.', $m['body']);
        $this->assertStringContainsString('Open dosya Backup to see the log and retry.', $m['body']);
    }

    public function testConnectionFix(): void
    {
        $m = Mail::build($this->status('failed', 'dosya connection was revoked'), $this->ctx(), $this->t);
        $this->assertStringContainsString('Connect to dosya again', $m['body']);
    }

    public function testFailedRespectsFlag(): void
    {
        $this->assertNull(Mail::build($this->status('failed', 'boom'), $this->ctx(['email_fail' => false]), $this->t));
    }

    public function testDone(): void
    {
        $this->assertNull(Mail::build($this->status('done', null, ['size' => 1288490188]), $this->ctx(), $this->t));
        $m = Mail::build($this->status('done', null, ['size' => 1288490188, 'remote' => true, 'locked_until' => 1762000000]), $this->ctx(['email_ok' => true]), $this->t);
        $this->assertSame('[Acme] Backup complete', $m['subject']);
        $this->assertStringContainsString('1.2 GB', $m['body']);
        $this->assertStringContainsString('stored in dosya', $m['body']);
        $this->assertStringContainsString('protected from deletion until', $m['body']);
        $local = Mail::build($this->status('done', null, ['size' => 1048576]), $this->ctx(['email_ok' => true]), $this->t);
        $this->assertStringContainsString('on this server', $local['body']);
    }

    public function testDonePastLockHasNoProtectedLine(): void
    {
        $m = Mail::build($this->status('done', null, ['size' => 1048576, 'remote' => true, 'locked_until' => 1750000000]), $this->ctx(['email_ok' => true]), $this->t);
        $this->assertStringContainsString('stored in dosya', $m['body']);
        $this->assertStringNotContainsString('protected', $m['body']);
    }

    public function testMultibyteCauseTruncatesOnCharacters(): void
    {
        $m = Mail::build($this->status('failed', str_repeat("\u{00fc}", 300)), $this->ctx(), $this->t);
        $this->assertStringContainsString(str_repeat("\u{00fc}", 120) . '...', $m['subject']);
        $this->assertStringNotContainsString(str_repeat("\u{00fc}", 121), $m['subject']);
        $this->assertSame(1, preg_match('//u', $m['subject']));
    }

    public function testSkipped(): void
    {
        $m = Mail::build($this->status('skipped', 'A backup is already running.'), $this->ctx(), $this->t);
        $this->assertSame('[Acme] Backup skipped: A backup is already running.', $m['subject']);
        $this->assertNull(Mail::build($this->status('skipped', 'x'), $this->ctx(['email_fail' => false]), $this->t));
    }

    public function testRestoreAndOthersNull(): void
    {
        $this->assertNull(Mail::build($this->status('failed', 'boom', null, 'restore'), $this->ctx(), $this->t));
        $this->assertNull(Mail::build($this->status('done', null, [], 'restore'), $this->ctx(['email_ok' => true]), $this->t));
        $this->assertNull(Mail::build($this->status('cancelled'), $this->ctx(), $this->t));
    }
}
