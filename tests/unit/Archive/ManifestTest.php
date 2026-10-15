<?php
namespace Dosya\Backup\Tests\unit\Archive;

use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\Manifest;
use PHPUnit\Framework\TestCase;

final class ManifestTest extends TestCase
{
    private function row(string $p, int $t, string $b = 'self', int $v = 1, int $ho = 64): array
    {
        return ['p' => $p, 't' => $t, 's' => 1, 'h' => 'x', 'm' => 0644, 'mt' => 1, 'loc' => ['b' => $b, 'v' => $v, 'ho' => $ho]];
    }

    public function testGroupsDatabaseEntriesAndDedupesFiles(): void
    {
        $rows = [
            $this->row('db/wp_posts/schema.sql', Format::TYPE_DB_SCHEMA),
            $this->row('db/wp_posts/part-0002.sql', Format::TYPE_DB_PART, 'self', 1, 300),
            $this->row('db/wp_posts/part-0001.sql', Format::TYPE_DB_PART, 'self', 1, 200),
            $this->row('db/_views/wp_v.sql', Format::TYPE_DB_SCHEMA),
            $this->row('db/_triggers/wp_t.sql', Format::TYPE_DB_SCHEMA),
            $this->row('index.php', Format::TYPE_FILE, 'self', 1, 10),
            $this->row('index.php', Format::TYPE_FILE, 'self', 1, 99),
            $this->row('wp-content', Format::TYPE_DIR),
        ];
        $m = Manifest::fromEntries(['backup_id' => 'self', 'created' => 5, 'kind' => 'full', 'volumes' => []], $rows, ['w1']);
        $this->assertSame([200, 300], array_column($m->tables['wp_posts']['parts'], 'ho'));
        $this->assertSame(64, $m->tables['wp_posts']['schema']['ho']);
        $this->assertArrayHasKey('wp_v', $m->views);
        $this->assertArrayHasKey('wp_t', $m->triggers);
        $this->assertCount(2, $m->files);
        $this->assertSame(99, $m->files[0]['loc']['ho']);
        $this->assertSame(['w1'], $m->warnings);
    }

    public function testGzRoundTripAndPublicViewRefs(): void
    {
        $rows = [$this->row('a', Format::TYPE_FILE, 'self'), $this->row('b', Format::TYPE_FILE, 'older', 3)];
        $m = Manifest::fromEntries(['backup_id' => 'self', 'created' => 5, 'kind' => 'incremental', 'volumes' => [['v' => 1, 'size' => 9, 'sha256' => 'h']]], $rows);
        $path = sys_get_temp_dir() . '/m-' . bin2hex(random_bytes(4)) . '.json.gz';
        $m->saveGz($path);
        $back = Manifest::loadGz($path);
        unlink($path);
        $this->assertSame($m->toArray(), $back->toArray());
        $pub = $back->publicView();
        $this->assertSame([['b' => 'older', 'v' => 3]], $pub['refs']);
        $this->assertSame(['full', ''], [$pub['scope'], $pub['label']]);
        $this->assertArrayNotHasKey('files', $pub);
    }

    public function testLoadGzMissingPathThrowsFormatException(): void
    {
        $this->expectException(\Dosya\Backup\Archive\FormatException::class);
        Manifest::loadGz(sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(4)) . '.json.gz');
    }

    public function testLoadGzRandomBytesThrowsFormatException(): void
    {
        $path = sys_get_temp_dir() . '/m-' . bin2hex(random_bytes(4)) . '.json.gz';
        file_put_contents($path, random_bytes(64));
        try {
            $this->expectException(\Dosya\Backup\Archive\FormatException::class);
            Manifest::loadGz($path);
        } finally {
            unlink($path);
        }
    }

    public function testLoadGzNonArrayFieldThrowsFormatException(): void
    {
        $path = sys_get_temp_dir() . '/m-' . bin2hex(random_bytes(4)) . '.json.gz';
        file_put_contents($path, gzencode('{"format":1,"meta":"x"}'));
        try {
            $this->expectException(\Dosya\Backup\Archive\FormatException::class);
            Manifest::loadGz($path);
        } finally {
            unlink($path);
        }
    }

    public function testViewAndTriggerWithSameNameBothKeepTheirRefs(): void
    {
        $rows = [
            $this->row('db/_views/same.sql', Format::TYPE_DB_SCHEMA, 'A', 1),
            $this->row('db/_triggers/same.sql', Format::TYPE_DB_SCHEMA, 'B', 2),
        ];
        $m = Manifest::fromEntries(['backup_id' => 'self', 'created' => 5, 'kind' => 'full', 'volumes' => []], $rows);
        $refs = $m->publicView()['refs'];
        $this->assertContains(['b' => 'A', 'v' => 1], $refs);
        $this->assertContains(['b' => 'B', 'v' => 2], $refs);
        $this->assertCount(2, $refs);
    }
}
