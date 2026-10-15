<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.unlink_unlink -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI); engine code runs outside WordPress and streams large files, which WP_Filesystem cannot do.
namespace Dosya\Backup\Jobs;

use Dosya\Backup\Archive\FileSink;
use Dosya\Backup\Archive\Format;
use Dosya\Backup\Archive\Manifest;
use Dosya\Backup\Archive\PathCodec;
use Dosya\Backup\Archive\Sink;
use Dosya\Backup\Archive\VolumeSet;
use Dosya\Backup\Db\Db;
use Dosya\Backup\Db\DbDumper;
use Dosya\Backup\Db\Escape;
use Dosya\Backup\Remote\RemoteStage;
use Dosya\Backup\Remote\RemoteStore;
use Dosya\Backup\Scan\Excludes;
use Dosya\Backup\Scan\FileScanner;
use Dosya\Backup\Support\AppendLog;
use Dosya\Backup\Support\Budget;
use Dosya\Backup\Support\Fs;
use Dosya\Backup\Support\Text;

final class BackupJob implements Steppable
{
    private const READ_CHUNK = 1048576;
    private const LIST_PAGE = 500;
    private const DISK_MARGIN = 52428800;
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    public const REMOTE_GONE = 'The dosya connection was removed during the upload; this backup is kept locally only.';
    public const LIST_UNREACHABLE = 'dosya could not be reached to check the earlier backups, so this backup is a full one.';
    public const CHAIN_TOO_LONG = 'The previous backup chain is too long for an incremental, so this backup is a full one.';

    private Db $db;
    private LocalStore $store;
    private SiteInfo $site;
    private Excludes $ex;
    private int $keep;
    private int $volumeTarget;
    private int $blockMax;
    /** @var string[] */
    private array $protect;
    private ?RemoteStage $remote;

    /** With a RemoteStage the sealed backup is also sent to dosya (finalize sub-stage remote); without one the job is local only. */
    public function __construct(Db $db, LocalStore $store, SiteInfo $site, Excludes $ex, int $keep = 3, int $volumeTarget = 67108864, int $blockMax = Format::BLOCK_MAX, array $protect = [], ?RemoteStage $remote = null)
    {
        $this->db = $db;
        $this->store = $store;
        $this->site = $site;
        $this->ex = $ex;
        $this->keep = $keep;
        $this->volumeTarget = $volumeTarget;
        $this->blockMax = $blockMax;
        $this->protect = $protect;
        $this->remote = $remote;
    }

    public static function initialCursor(string $scope = 'full', string $label = '', string $note = ''): array
    {
        if (!in_array($scope, ['full', 'db', 'files'], true)) {
            throw new \InvalidArgumentException('scope must be full, db or files');
        }
        return ['scope' => $scope, 'label' => $label, 'note' => $note, 'progress' => ['phase' => 'queued']];
    }

    public function step(string $state, array $c, Budget $b): array
    {
        switch ($state) {
            case 'queued': return $this->preflight($c);
            case 'db_dump': return $this->dbDump($c, $b);
            case 'file_scan': return $this->fileScan($c, $b);
            case 'archive': return $this->archive($c, $b);
            case 'finalize': return $this->finalize($c, $b);
        }
        throw new \LogicException('unknown backup state ' . $state);
    }

    private function preflight(array $c): array
    {
        $this->store->ensure();
        $id = bin2hex(random_bytes(16));
        Fs::ensureDir($this->store->backupDir($id) . '/volumes');
        $prefix = $this->db->prefix();
        $c = array_merge($c, [
            'id' => $id, 'created' => time(), 'vs' => null, 'logs' => ['entries' => 0, 'list' => 0], 'warnings' => [],
            'rows' => [], 'scan' => null, 'scan_bytes' => 0, 'scan_files' => 0, 'list_pos' => 0, 'cur' => null, 'done_bytes' => 0,
            'site' => [
                'home' => $this->site->homeUrl(), 'siteurl' => $this->site->siteUrl(), 'abspath' => $this->site->absPath(),
                'prefix' => $prefix, 'multisite' => $this->site->isMultisite(), 'wp' => $this->site->wpVersion(),
                'php' => PHP_VERSION, 'db_server' => $this->db->serverVersion(), 'charset' => $this->db->charset(),
            ],
        ]);
        $content = rtrim($this->site->contentDir(), '/') . '/';
        if (strpos($content, $this->site->absPath()) !== 0) {
            // only ABSPATH is scanned; say so instead of silently leaving wp-content out
            $c['warnings'][] = Text::utf8('wp-content is outside the WordPress folder and is not included in this backup: ' . $this->site->contentDir());
        }
        $c = $this->chooseKind($c);
        if ($c['scope'] === 'files') {
            return $this->next('file_scan', $c);
        }
        $dbBytes = (int) $this->db->value('SELECT COALESCE(SUM(data_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '
            . $this->db->quote(Escape::like($prefix) . '%'));
        if (($msg = $this->diskShortfall($id, (int) ($dbBytes * 0.6))) !== null) {
            return $this->skip($c, $msg);
        }
        $c['dump'] = (new DbDumper($this->db))->plan($this->site->isMultisite(), [$prefix . 'dosya_jobs']);
        return $this->next('db_dump', $c);
    }

    /**
     * full or incremental. Incremental only with a RemoteStage, a previous uploaded backup that dosya
     * still lists as stored together with every backup it reads, a chain younger than 30 days, and the
     * local volumes of all of those on disk (restore reads them). The previous rows go to a sidecar
     * index in the backup folder (PrevIndex); the cursor keeps only the id, the row count and the
     * referenced volume sizes.
     */
    private function chooseKind(array $c): array
    {
        $c['kind'] = 'full';
        $c['full_since'] = $c['created'];
        $c['prev'] = null;
        if ($this->remote === null || $c['scope'] === 'db') {
            return $c;
        }
        $remote = $this->remote->store();
        $prev = $remote->previousManifest();
        if ($prev === null) {
            return $c;
        }
        // The server lists at most 200 backups, newest first, failed ones included. A base full that has
        // dropped out of that list makes this backup a full one, by design (the chain restarts).
        try {
            $listed = $this->remote->listBackups();
        } catch (\RuntimeException $e) {
            $c['warnings'][] = self::LIST_UNREACHABLE;
            return $c;
        }
        if (!$remote->previousIsUsable($listed, $prev, time())) {
            return $c;
        }
        $sizes = $remote->volumeSizes($prev);
        if ($sizes === null) {
            return $c;
        }
        // the refs this backup could collect are a subset of these; complete refuses more than MAX_REFS
        if (count($sizes) > RemoteStage::MAX_REFS) {
            $c['warnings'][] = self::CHAIN_TOO_LONG;
            return $c;
        }
        foreach (array_keys($sizes) as $k) {
            [$b, $v] = explode(':', (string) $k);
            if (!is_file($this->store->volumePath($b, (int) $v))) {
                return $c;
            }
        }
        $c['kind'] = 'incremental';
        $c['full_since'] = RemoteStore::fullSince($prev);
        $prevId = (string) $prev->meta['backup_id'];
        $c['prev'] = ['id' => $prevId, 'rows' => PrevIndex::build($prev, $this->store->backupDir($c['id'])), 'sizes' => $sizes];
        return $c;
    }

    private function dbDump(array $c, Budget $b): array
    {
        [$log, $vs] = $this->open($c);
        $c['dump'] = (new DbDumper($this->db))->step($c['dump'], $b, $vs);
        $this->close($c, $log, $vs);
        $c['progress'] = ['phase' => 'db_dump', 'tables_done' => $c['dump']['i'], 'tables' => count($c['dump']['tables'])];
        if (empty($c['dump']['done'])) {
            return ['state' => 'db_dump', 'cursor' => $c];
        }
        $c['rows'] = $c['dump']['rows'];
        $c['dump'] = ['done' => true];
        return $this->next($c['scope'] === 'db' ? 'finalize' : 'file_scan', $c);
    }

    private function fileScan(array $c, Budget $b): array
    {
        $list = new AppendLog($this->store->backupDir($c['id']) . '/files.jsonl', $c['logs']['list']);
        $scanner = new FileScanner($this->site->absPath(), $this->excludes());
        $c['scan'] = $scanner->run($c['scan'] ?? FileScanner::start(), $b, function (array $item) use ($list, &$c): void {
            $list->append($item);
            $c['scan_bytes'] += (int) $item['s'];
            $c['scan_files']++;
        }, $this->warner($c));
        $c['logs']['list'] = $list->length();
        $c['progress'] = ['phase' => 'file_scan', 'files' => $c['scan_files'], 'bytes' => $c['scan_bytes']];
        if (empty($c['scan']['done'])) {
            return ['state' => 'file_scan', 'cursor' => $c];
        }
        if (($msg = $this->diskShortfall($c['id'], $c['scan_bytes'])) !== null) {
            return $this->skip($c, $msg);
        }
        $c['scan'] = ['done' => true];
        return $this->next('archive', $c);
    }

    private function archive(array $c, Budget $b): array
    {
        [$log, $vs] = $this->open($c);
        $list = new AppendLog($this->store->backupDir($c['id']) . '/files.jsonl', $c['logs']['list']);
        $warn = $this->warner($c);
        $root = rtrim($this->site->absPath(), '/');
        $prev = empty($c['prev']) ? null : PrevIndex::open($this->store->backupDir($c['id']));
        $finished = false;
        $progressed = false;
        while (!$finished) {
            if ($progressed && $b->exhausted()) {
                break;
            }
            $progressed = true;
            if ($c['cur'] !== null) {
                $this->pump($c, $vs, $b, $root, $warn);
                continue;
            }
            $page = $list->readFrom((int) $c['list_pos'], self::LIST_PAGE);
            if (!$page) {
                $finished = true;
                break;
            }
            foreach ($page as [$item, $end]) {
                $c['list_pos'] = $end;
                $path = PathCodec::unpack($item);
                $t = (int) $item['t'];
                if ($t === Format::TYPE_DIR || $t === Format::TYPE_SYMLINK) {
                    $vs->beginEntry($t, $path, (int) $item['m'], (int) $item['mt'], false);
                    if ($t === Format::TYPE_SYMLINK) {
                        $vs->write(PathCodec::unpack($item['l']));
                    }
                    $vs->endEntry();
                } elseif ($prev !== null && ($row = $prev->find($path, (int) $item['s'], (int) $item['mt'])) !== null) {
                    // unchanged since the previous backup: reuse its row, which points at its volume
                    $log->append($row);
                    $c['done_bytes'] += (int) $item['s'];
                } elseif (!is_readable($root . '/' . $path)) {
                    $warn('File disappeared before it was saved: ' . $path);
                } else {
                    $vs->beginEntry($t, $path, (int) $item['m'], (int) $item['mt'], Format::shouldCompress($path));
                    $c['cur'] = PathCodec::pack($path) + ['off' => 0, 's' => (int) $item['s']];
                    $this->pump($c, $vs, $b, $root, $warn);
                }
                if ($c['cur'] !== null || $b->exhausted()) {
                    break;
                }
            }
        }
        $this->close($c, $log, $vs);
        $c['progress'] = ['phase' => 'archive', 'bytes_done' => $c['done_bytes'], 'bytes_total' => $c['scan_bytes']];
        return $finished ? $this->next('finalize', $c) : ['state' => 'archive', 'cursor' => $c];
    }

    private function pump(array &$c, VolumeSet $vs, Budget $b, string $root, callable $warn): void
    {
        $path = PathCodec::unpack($c['cur']);
        $abs = $root . '/' . $path;
        $fh = is_readable($abs) ? fopen($abs, 'rb') : false;
        if ($fh === false || fseek($fh, (int) $c['cur']['off']) !== 0) {
            $warn('File changed while it was being saved: ' . $path);
            if ($fh !== false) {
                fclose($fh);
            }
            $vs->endEntry();
            $c['cur'] = null;
            return;
        }
        while (($chunk = fread($fh, self::READ_CHUNK)) !== false && $chunk !== '') {
            $vs->write($chunk);
            $c['cur']['off'] += strlen($chunk);
            $c['done_bytes'] += strlen($chunk);
            if ($b->exhausted()) {
                fclose($fh);
                return;
            }
        }
        fclose($fh);
        $vs->endEntry();
        if ($c['cur']['off'] !== $c['cur']['s']) {
            $warn('File changed while it was being saved: ' . $path);
        }
        $c['cur'] = null;
    }

    private function finalize(array $c, Budget $b): array
    {
        $stage = $c['fin']['stage'] ?? 'seal';
        if ($stage === 'seal') {
            [$log, $vs] = $this->open($c);
            $vols = $vs->finish();
            $c['vs'] = null;
            $c['fin'] = ['stage' => 'hash', 'vols' => $vols, 'i' => 0];
            return $this->fin($c, 'seal');
        }
        if ($stage === 'hash') {
            $n = count($c['fin']['vols']);
            while ($c['fin']['i'] < $n) {
                $i = (int) $c['fin']['i'];
                $c['fin']['vols'][$i]['sha256'] = hash_file('sha256', $this->store->volumePath($c['id'], (int) $c['fin']['vols'][$i]['v']));
                $c['fin']['i'] = $i + 1;
                if ($b->exhausted()) {
                    break;
                }
            }
            if ($c['fin']['i'] >= $n) {
                $c['fin']['stage'] = 'write';
            }
            return $this->fin($c, 'hash');
        }
        if ($stage === 'write') {
            if (!is_file($this->store->manifestPath($c['id']))) {
                $log = new AppendLog($this->store->backupDir($c['id']) . '/entries.jsonl', (int) $c['logs']['entries']);
                $entries = (function () use ($log): \Generator {
                    $pos = 0;
                    while ($page = $log->readFrom($pos, 1000)) {
                        foreach ($page as [$row, $end]) {
                            $pos = $end;
                            yield $row;
                        }
                    }
                })();
                $m = Manifest::fromEntries([
                    'backup_id' => $c['id'], 'created' => $c['created'], 'kind' => 'full', 'scope' => $c['scope'], 'label' => $c['label'],
                    'site' => $c['site'], 'volumes' => $c['fin']['vols'], 'rows' => $c['rows'],
                ], $entries, $c['warnings']);
                $this->settleKind($m, $c);
                $m->saveGz($this->store->manifestPath($c['id']));
            } else {
                $m = $this->store->load($c['id']);
            }
            if (!is_file($this->store->publicManifestPath($c['id']))) {
                file_put_contents($this->store->publicManifestPath($c['id']), json_encode($m->publicView(), self::FLAGS));
            }
            $this->store->writeLocalMeta($c['id'], (string) ($c['note'] ?? ''), (string) ($m->meta['site']['home'] ?? ''), false);
            $c['fin']['kind'] = (string) $m->meta['kind'];
            $c['fin']['ref_bytes'] = (int) ($m->meta['bytes_total'] ?? 0) - (int) ($m->meta['bytes_new'] ?? 0);
            $c['fin']['stage'] = $this->remote !== null ? 'remote' : 'cleanup';
            return $this->fin($c, 'write');
        }
        if ($stage === 'remote') {
            return $this->remoteStep($c, $b);
        }
        $dir = $this->store->backupDir($c['id']);
        foreach (['entries.jsonl', 'files.jsonl', PrevIndex::INDEX, PrevIndex::ROWS] as $f) {
            if (is_file($dir . '/' . $f)) {
                unlink($dir . '/' . $f);
            }
        }
        $this->store->prune($this->keep, 2, time(), $this->protect);
        $m = $this->store->load($c['id']);
        $size = (int) array_sum(array_column($c['fin']['vols'], 'size'));
        $result = ['backup_id' => $c['id'], 'size' => $size, 'files' => count($m->files), 'tables' => count($m->tables),
            'warnings' => array_merge($c['fin']['warn'] ?? [], $m->warnings)];
        if (!empty($c['remote']['done'])) {
            $result['remote'] = $c['remote']['result'];
        }
        return ['state' => 'done', 'cursor' => ['scope' => $c['scope'], 'label' => $c['label'], 'id' => $c['id'], 'progress' => ['phase' => 'done']],
            'result' => $result];
    }

    /** One RemoteStage step per job step; the cursor is written after each. */
    private function remoteStep(array $c, Budget $b): array
    {
        if ($this->remote === null) {
            // disconnected while the upload ran: the backup stays local only. The key is gone, so an open
            // upload session cannot be aborted from here; Connector::disconnect aborts it before revoking.
            $c['fin']['stage'] = 'cleanup';
            $c['fin']['warn'] = [self::REMOTE_GONE];
            return $this->fin($c, 'remote');
        }
        if (!isset($c['remote'])) {
            $vols = [];
            foreach ($c['fin']['vols'] as $v) {
                $vols[] = $v + ['path' => $this->store->volumePath($c['id'], (int) $v['v'])];
            }
            $pub = json_decode((string) file_get_contents($this->store->publicManifestPath($c['id'])), true, 512, JSON_THROW_ON_ERROR);
            $c['remote'] = RemoteStage::start($c['id'], (string) ($c['fin']['kind'] ?? 'full'), (int) $c['created'], $vols, [
                RemoteStage::MANIFEST => $this->store->manifestPath($c['id']),
                RemoteStage::PUBLIC_MANIFEST => $this->store->publicManifestPath($c['id']),
            ], is_array($pub['refs'] ?? null) ? $pub['refs'] : [], (int) ($c['fin']['ref_bytes'] ?? 0));
        }
        $c['remote'] = $this->remote->step($c['remote'], $b);
        if (!empty($c['remote']['done'])) {
            $c['fin']['stage'] = 'cleanup';
        }
        $c['progress'] = ['phase' => 'remote'] + RemoteStage::progress($c['remote']);
        return ['state' => 'finalize', 'cursor' => $c];
    }

    /**
     * Sets kind, full_since, bytes_new, bytes_total and ref_volumes on the new manifest. An incremental
     * that ended up reading nothing earlier (every file changed) is recorded as a full one.
     */
    private function settleKind(Manifest $m, array $c): void
    {
        $refs = $m->publicView()['refs'];
        $kind = ($c['kind'] ?? 'full') === 'incremental' && $refs !== [] ? 'incremental' : 'full';
        $new = (int) array_sum(array_column($c['fin']['vols'], 'size'));
        $refVolumes = [];
        $refBytes = 0;
        foreach ($refs as $r) {
            $k = $r['b'] . ':' . $r['v'];
            $size = $c['prev']['sizes'][$k] ?? null;
            if ($size === null) {
                $local = $this->store->volumePath($r['b'], (int) $r['v']);
                $size = is_file($local) ? filesize($local) : false;
                if ($size === false) {
                    throw new \RuntimeException('This backup reads backup ' . $r['b'] . ', which is no longer on this site.');
                }
            }
            $refVolumes[] = ['b' => $r['b'], 'v' => (int) $r['v'], 'size' => (int) $size];
            $refBytes += (int) $size;
        }
        $m->meta['kind'] = $kind;
        $m->meta['full_since'] = $kind === 'incremental' ? (int) $c['full_since'] : (int) $c['created'];
        $m->meta['bytes_new'] = $new;
        $m->meta['bytes_total'] = $new + $refBytes;
        $m->meta['ref_volumes'] = $refVolumes;
    }

    private function fin(array $c, string $phase): array
    {
        $c['progress'] = ['phase' => 'finalize', 'step' => $phase];
        return ['state' => 'finalize', 'cursor' => $c];
    }

    /** @return array{0:AppendLog,1:VolumeSet} */
    private function open(array $c): array
    {
        $log = new AppendLog($this->store->backupDir($c['id']) . '/entries.jsonl', (int) $c['logs']['entries']);
        $sinkFor = function (int $n, int $at) use ($c): Sink { return new FileSink($this->store->volumePath($c['id'], $n), $at); };
        $record = function (array $e) use ($log): void { $log->append($e); };
        $vs = $c['vs'] === null
            ? new VolumeSet($c['id'], (int) $c['created'], $this->volumeTarget, $sinkFor, $record, $this->blockMax)
            : VolumeSet::resume($c['vs'], $sinkFor, $record);
        return [$log, $vs];
    }

    private function close(array &$c, AppendLog $log, VolumeSet $vs): void
    {
        $c['vs'] = $vs->suspend();
        $c['logs']['entries'] = $log->length();
    }

    private function excludes(): Excludes
    {
        $abs = rtrim($this->site->absPath(), '/') . '/';
        $store = $this->store->dir();
        return strpos($store . '/', $abs) === 0 ? $this->ex->with(substr($store, strlen($abs)) . '/**') : $this->ex;
    }

    private function warner(array &$c): callable
    {
        return function (string $w) use (&$c): void {
            if (count($c['warnings']) < 200) {
                $c['warnings'][] = Text::utf8($w);
            }
        };
    }

    private function diskShortfall(string $id, int $needed): ?string
    {
        $free = disk_free_space($this->store->backupDir($id));
        if ($free === false || $free >= $needed + self::DISK_MARGIN) {
            return null;
        }
        return sprintf('Not enough disk space for a local backup: about %s needed, %s free.',
            self::human($needed + self::DISK_MARGIN), self::human((int) $free));
    }

    private function skip(array $c, string $msg): array
    {
        Fs::rrmdir($this->store->backupDir($c['id']));
        $c['progress'] = ['phase' => 'skipped'];
        return ['state' => 'skipped', 'cursor' => $c, 'error' => $msg];
    }

    private function next(string $state, array $c): array
    {
        $c['progress'] = ['phase' => $state];
        return ['state' => $state, 'cursor' => $c];
    }

    private static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < 4) {
            $v /= 1024;
            $i++;
        }
        return round($v, 1) . ' ' . $units[$i];
    }
}
