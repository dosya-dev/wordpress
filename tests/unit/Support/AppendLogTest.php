<?php
namespace Dosya\Backup\Tests\unit\Support;

use Dosya\Backup\Support\AppendLog;
use PHPUnit\Framework\TestCase;

final class AppendLogTest extends TestCase
{
    public function testResumeTruncatesUncheckpointedRows(): void
    {
        $path = sys_get_temp_dir() . '/al-' . bin2hex(random_bytes(4));
        $log = new AppendLog($path, 0);
        $log->append(['n' => 1]);
        $checkpoint = $log->length();
        $log->append(['n' => 2]);

        $log2 = new AppendLog($path, $checkpoint);
        $log2->append(['n' => 3]);
        $rows = array_column($log2->readFrom(0, 10), 0);
        $this->assertSame([['n' => 1], ['n' => 3]], $rows);

        $page = $log2->readFrom(0, 1);
        $this->assertSame($checkpoint, $page[0][1]);
        unlink($path);
    }

    public function testResumeBeyondFileSizeThrows(): void
    {
        $path = sys_get_temp_dir() . '/al-' . bin2hex(random_bytes(4));
        $log = new AppendLog($path, 0);
        $log->append(['n' => 1]);
        $this->expectException(\RuntimeException::class);
        try {
            new AppendLog($path, $log->length() + 100);
        } finally {
            unlink($path);
        }
    }

    public function testReadFromMissingFileThrows(): void
    {
        $path = sys_get_temp_dir() . '/al-' . bin2hex(random_bytes(4));
        $log = new AppendLog($path, 0);
        unlink($path);
        $this->expectException(\RuntimeException::class);
        $log->readFrom(0, 1);
    }
}
