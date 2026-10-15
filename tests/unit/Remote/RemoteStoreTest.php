<?php
namespace Dosya\Backup\Tests\unit\Remote;

use Dosya\Backup\Archive\Manifest;
use Dosya\Backup\Remote\RemoteStore;
use PHPUnit\Framework\TestCase;

final class RemoteStoreTest extends TestCase
{
    private const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private function prev(int $fullSince): Manifest
    {
        return Manifest::fromEntries(['backup_id' => self::B, 'created' => $fullSince + 10, 'kind' => 'incremental', 'full_since' => $fullSince,
            'volumes' => [['v' => 1, 'size' => 5, 'sha256' => str_repeat('0', 64)]]], [
            ['p' => 'a.txt', 't' => 1, 's' => 1, 'h' => 'x', 'm' => 0644, 'mt' => 1, 'loc' => ['b' => self::A, 'v' => 2, 'ho' => 0]],
        ]);
    }

    public function testChainAgeBoundaryIsExclusive(): void
    {
        $store = new RemoteStore(sys_get_temp_dir() . '/dosya-rst-' . bin2hex(random_bytes(4)));
        $list = [['id' => self::B, 'state' => 'uploaded'], ['id' => self::A, 'state' => 'verified']];
        $since = 1700000000;
        $this->assertTrue($store->previousIsUsable($list, $this->prev($since), $since + RemoteStore::CHAIN_MAX_AGE - 1));
        $this->assertFalse($store->previousIsUsable($list, $this->prev($since), $since + RemoteStore::CHAIN_MAX_AGE));
    }

    public function testEveryReadBackupMustBeStored(): void
    {
        $store = new RemoteStore(sys_get_temp_dir() . '/dosya-rst-' . bin2hex(random_bytes(4)));
        $since = 1700000000;
        $this->assertFalse($store->previousIsUsable([['id' => self::B, 'state' => 'uploaded'], ['id' => self::A, 'state' => 'failed']], $this->prev($since), $since));
        $this->assertFalse($store->previousIsUsable([['id' => self::A, 'state' => 'uploaded']], $this->prev($since), $since));
        $this->assertFalse($store->previousIsUsable([['id' => self::B, 'state' => 'running'], ['id' => self::A, 'state' => 'uploaded']], $this->prev($since), $since));
    }
}
