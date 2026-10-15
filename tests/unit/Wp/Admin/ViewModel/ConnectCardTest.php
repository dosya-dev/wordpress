<?php
namespace Dosya\Backup\Tests\unit\Wp\Admin\ViewModel;

use Dosya\Backup\Wp\Admin\ViewModel\ConnectCard;
use PHPUnit\Framework\TestCase;

final class ConnectCardTest extends TestCase
{
    private const NOW = 1791800000; // 2026-10-12 ~06:13 UTC
    private const GB = 1073741824;
    private $t;

    protected function setUp(): void { $this->t = static function (string $s): string { return $s; }; }

    private function base(array $over = []): array
    {
        return array_replace([
            'key_state' => 'none', 'connection' => null, 'storage' => null, 'last_upload' => null,
            'avg_backup' => null, 'plan_line' => 'Free plan: 1 site, 5 GB, 7 days', 'now' => self::NOW, 'tz' => 'UTC', 'fmt' => null,
        ], $over);
    }

    private function ok(array $over = []): array
    {
        return $this->base(array_replace([
            'key_state' => 'ok',
            'connection' => ['retention_days' => 30, 'immutability_hours' => 24, 'folder' => null, 'account' => null, 'workspace' => null],
            'storage' => ['used' => 2 * self::GB, 'total' => 5 * self::GB],
        ], $over));
    }

    public function testNotConnected(): void
    {
        $c = ConnectCard::build($this->base(), $this->t);
        $this->assertSame('none', $c['state']);
        $this->assertSame('neutral', $c['tone']);
        $this->assertSame('Store copies in dosya', $c['headline']);
        $this->assertSame('Connect to dosya', $c['primary']['label']);
        $this->assertSame('connect', $c['primary']['kind']);
        $this->assertSame([], $c['secondary']);
        $this->assertNull($c['meter']);
        $this->assertSame([], $c['facts']);
        $this->assertStringContainsString('You will approve this site on dosya.dev. dosya receives the site address, its name, and the backups you make. Nothing else.', $c['consent']);
        $this->assertSame('Free plan: 1 site, 5 GB, 7 days', $c['plan']);
        $this->assertNull($c['fix']);
        $this->assertNull($c['disconnect_confirm']);
    }

    public function testConnectedMinimal(): void
    {
        $c = ConnectCard::build($this->ok(), $this->t);
        $this->assertSame('connected', $c['state']);
        $this->assertSame('success', $c['tone']);
        $this->assertSame('Connected to dosya', $c['headline']);
        $this->assertSame('', $c['sub']);
        $this->assertNull($c['primary']);
        $this->assertSame(['open', 'disconnect'], array_column($c['secondary'], 'kind'));
        $this->assertSame('Disconnect this site from dosya? Backups already stored in dosya are kept.', $c['disconnect_confirm']);
        $this->assertNull($c['consent']);
        $this->assertNull($c['plan']);
        $this->assertNull($c['fix']);
        $this->assertSame([['label' => 'Retention', 'value' => '30 days'], ['label' => 'Protection', 'value' => '24 h after each upload']], $c['facts']);
    }

    public function testConnectedFullFacts(): void
    {
        $c = ConnectCard::build($this->ok([
            'connection' => ['retention_days' => 30, 'immutability_hours' => 24, 'folder' => 'Backups/example.com', 'account' => 'me@example.com', 'workspace' => 'Acme'],
            'last_upload' => ['created' => self::NOW - 3600 * 3, 'verified' => true],
        ]), $this->t);
        $this->assertSame('as me@example.com · workspace Acme', $c['sub']);
        $this->assertSame([
            ['label' => 'Folder', 'value' => 'Backups/example.com'],
            ['label' => 'Last upload', 'value' => 'Today 07:13, verified'],
            ['label' => 'Retention', 'value' => '30 days'],
            ['label' => 'Protection', 'value' => '24 h after each upload'],
        ], $c['facts']);

        $c = ConnectCard::build($this->ok(['last_upload' => ['created' => self::NOW - 3600 * 3, 'verified' => false]]), $this->t);
        $this->assertSame('Today 07:13, not yet verified', $c['facts'][0]['value']);
    }

    public function testAccountWithoutWorkspace(): void
    {
        $c = ConnectCard::build($this->ok(['connection' => ['retention_days' => null, 'immutability_hours' => null, 'folder' => null, 'account' => 'me@example.com', 'workspace' => null]]), $this->t);
        $this->assertSame('as me@example.com', $c['sub']);
        $this->assertSame([], $c['facts']);
    }

    public function testMeterAndProjection(): void
    {
        $c = ConnectCard::build($this->ok(['avg_backup' => 500 * 1048576]), $this->t);
        $this->assertSame(['used' => 2 * self::GB, 'total' => 5 * self::GB, 'percent' => 40,
            'projection' => 'about 6 more backups at the current size', 'storage_text' => '2 GB of 5 GB used'], $c['meter']);
    }

    public function testNoProjectionWithoutAverage(): void
    {
        $this->assertNull(ConnectCard::build($this->ok(), $this->t)['meter']['projection']);
        $this->assertNull(ConnectCard::build($this->ok(['avg_backup' => 0]), $this->t)['meter']['projection']);
        $this->assertNull(ConnectCard::build($this->ok(['storage' => ['used' => 5 * self::GB, 'total' => 5 * self::GB], 'avg_backup' => 100]), $this->t)['meter']['projection']);
    }

    public function testNoStorageNoMeter(): void
    {
        $c = ConnectCard::build($this->ok(['storage' => null]), $this->t);
        $this->assertSame('connected', $c['state']);
        $this->assertNull($c['meter']);
    }

    public function testNinetyPercentBoundary(): void
    {
        $below = ConnectCard::build($this->ok(['storage' => ['used' => 899, 'total' => 1000]]), $this->t);
        $this->assertSame('connected', $below['state']);
        $at = ConnectCard::build($this->ok(['storage' => ['used' => 900, 'total' => 1000]]), $this->t);
        $this->assertSame('nearly_full', $at['state']);
        $this->assertSame(90, $at['meter']['percent']);
    }

    public function testNearlyFull(): void
    {
        $c = ConnectCard::build($this->ok(['storage' => ['used' => (int) (4.6 * self::GB), 'total' => 5 * self::GB], 'avg_backup' => 1000]), $this->t);
        $this->assertSame('nearly_full', $c['state']);
        $this->assertSame('caution', $c['tone']);
        $this->assertStringContainsString('4.6 GB of 5 GB. When it is full, the next upload is skipped and the backup stays on this server only.', $c['sub']);
        $this->assertSame('Free the space first: exclude the uploads folder under Schedule, or shorten retention on dosya.dev. If you need more, plans start at 50 GB on dosya.dev.', $c['fix']);
        $this->assertSame('review_excludes', $c['primary']['kind']);
        $this->assertSame('Review excludes', $c['primary']['label']);
        $this->assertSame(['open'], array_column($c['secondary'], 'kind'));
        $this->assertNull($c['plan']);
        $this->assertNull($c['consent']);
        $this->assertSame('Disconnect this site from dosya? Backups already stored in dosya are kept.', $c['disconnect_confirm']);
    }

    public function testUnreadable(): void
    {
        $c = ConnectCard::build($this->ok(['key_state' => 'unreadable']), $this->t);
        $this->assertSame('unreadable', $c['state']);
        $this->assertSame('error', $c['tone']);
        $this->assertSame('Connection to dosya lost', $c['headline']);
        $this->assertSame("Your site's security keys changed, so the saved connection cannot be read. Backups already in dosya are safe. Connect again to resume uploads.", $c['sub']);
        $this->assertSame('Connect again', $c['primary']['label']);
        $this->assertSame('connect', $c['primary']['kind']);
        $this->assertNull($c['meter']);
        $this->assertNull($c['plan']);
        $this->assertNull($c['consent']);
        $this->assertSame([], $c['secondary']);
    }

    public function testRevoked(): void
    {
        $c = ConnectCard::build($this->ok(['key_state' => 'revoked']), $this->t);
        $this->assertSame('revoked', $c['state']);
        $this->assertSame('error', $c['tone']);
        $this->assertSame('This site was disconnected from dosya.dev', $c['headline']);
        $this->assertSame('Connect again to resume uploads.', $c['sub']);
        $this->assertSame('Connect again', $c['primary']['label']);
        $this->assertNull($c['plan']);
        $this->assertNull($c['fix']);
    }

    public function testStatePrecedenceOverStorage(): void
    {
        $full = ['used' => 990, 'total' => 1000];
        $this->assertSame('unreadable', ConnectCard::build($this->ok(['key_state' => 'unreadable', 'storage' => $full]), $this->t)['state']);
        $this->assertSame('none', ConnectCard::build($this->base(['storage' => $full]), $this->t)['state']);
    }

    public function testNoEmDashesAnywhere(): void
    {
        $inputs = [$this->base(), $this->ok(['connection' => ['retention_days' => 7, 'immutability_hours' => 1, 'folder' => 'f', 'account' => 'a', 'workspace' => 'w'], 'last_upload' => ['created' => self::NOW, 'verified' => true], 'avg_backup' => 10]),
            $this->ok(['storage' => ['used' => 95, 'total' => 100]]), $this->ok(['key_state' => 'unreadable']), $this->ok(['key_state' => 'revoked'])];
        foreach ($inputs as $in) {
            $this->assertStringNotContainsString("\u{2014}", json_encode(ConnectCard::build($in, $this->t), JSON_UNESCAPED_UNICODE));
        }
    }
}
