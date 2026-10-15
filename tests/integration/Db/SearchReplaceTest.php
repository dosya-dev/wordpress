<?php
namespace Dosya\Backup\Tests\integration\Db;

use Dosya\Backup\Db\SearchReplace;
use Dosya\Backup\Db\UrlPairs;
use Dosya\Backup\Support\Budget;
use Dosya\Backup\Tests\integration\DbTestCase;

final class SearchReplaceTest extends DbTestCase
{
    public function testBinaryKeyCursorSurvivesJsonRoundTrip(): void
    {
        $t = $this->prefix . 'bin';
        $this->db->exec('CREATE TABLE ' . $this->db->ident($t) . ' (k BINARY(16) PRIMARY KEY, v TEXT)');
        // more rows than SearchReplace's batch (200), so the cursor is saved and resumed
        $vals = [];
        for ($i = 0; $i < 450; $i++) {
            $vals[] = '(UNHEX(' . $this->db->quote(sprintf('%032x', 0xF0F0F0F0 + $i * 7919)) . '), ' . $this->db->quote("see http://a.test/$i") . ')';
        }
        $this->db->exec('INSERT INTO ' . $this->db->ident($t) . ' VALUES ' . implode(',', $vals));
        $sr = new SearchReplace($this->db);
        $pairs = UrlPairs::build('http://a.test', 'https://b.test');
        $c = SearchReplace::start([$t]);
        $guard = 0;
        do {
            $c = $sr->run($c, $pairs, new Budget(0.0, 0), function (): void {});
            $c = json_decode(json_encode($c, JSON_THROW_ON_ERROR), true);
        } while (empty($c['done']) && ++$guard < 20);
        $this->assertSame(450, $c['changed']);
        $this->assertSame('450', (string) $this->db->value('SELECT COUNT(*) FROM ' . $this->db->ident($t) . " WHERE v LIKE 'see https://b.test/%'"));
    }
}
