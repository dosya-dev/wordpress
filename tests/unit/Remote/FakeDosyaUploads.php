<?php
namespace Dosya\Backup\Tests\unit\Remote;

use Dosya\Backup\Connect\HttpClient;

/**
 * In-process dosya upload API over a temp dir. FakeDosyaApi adds the /api/wp/* routes through route().
 * Stored files and session parts live on disk under a per-instance temp dir (never in memory: PHPUnit
 * keeps every test case, and so every fake, alive until the suite ends); read them through fileBytes().
 */
class FakeDosyaUploads implements HttpClient
{
    /** @var list<array<string,mixed>> */
    public array $requests = [];
    /** @var array<string,array{name:string,size:int,parts:array<int,int>,pretend:array<int,bool>,complete:bool,file_id:?string,total:int}> parts: n => stored byte count */
    private array $sessions = [];
    /** @var array<string,int> file id => stored byte count (the bytes are in fileDir()) */
    private array $files = [];
    private ?string $dir = null;
    /** @var array<string,array<string,mixed>> idempotency key => the 201 body it replays */
    private array $idempotent = [];
    /** @var array<string,array{folder:string,name:string}> file id => where it was stored */
    public array $fileMeta = [];
    /** @var array<string,array<int,int>> session => [n => count] */
    private array $received = [];
    /** @var list<array{method:string,re:string,status:int,applyFirst:bool,headers:array<string,string>}> */
    private array $fail = [];
    private int $seq = 0;
    /** @var array<string,int> */
    private array $bytesOverride = [];
    private int $partSize;

    public function __construct(int $partSize = 10485760)
    {
        $this->partSize = $partSize;
    }

    /** @param array<string,string> $headers response headers, lowercase names (e.g. retry-after) */
    public function failNext(string $method, string $pathRegex, int $status, bool $applyFirst = false, array $headers = []): self
    {
        $this->fail[] = ['method' => $method, 're' => $pathRegex, 'status' => $status, 'applyFirst' => $applyFirst, 'headers' => $headers];
        return $this;
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    /** Deletes every stored body. The fake forgets its files and parts. */
    public function cleanup(): void
    {
        if ($this->dir !== null && is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->dir);
        }
        $this->dir = null;
        $this->files = [];
        foreach ($this->sessions as $sid => $s) {
            $this->sessions[$sid]['parts'] = [];
        }
    }

    public function hasFile(string $id): bool
    {
        return isset($this->files[$id]);
    }

    /** The stored bytes of a file (read back from disk). */
    public function fileBytes(string $id): string
    {
        if (!isset($this->files[$id])) {
            throw new \OutOfBoundsException('no stored file ' . $id);
        }
        return (string) file_get_contents($this->filePath($id));
    }

    public function fileSize(string $id): int
    {
        if (!isset($this->files[$id])) {
            throw new \OutOfBoundsException('no stored file ' . $id);
        }
        return $this->files[$id];
    }

    public function fileSha256(string $id): string
    {
        $this->fileSize($id);
        return (string) hash_file('sha256', $this->filePath($id));
    }

    public function fileCount(): int
    {
        return count($this->files);
    }

    public function forgetPart(string $sid, int $n): void
    {
        unset($this->sessions[$sid]['parts'][$n]);
        $p = $this->partPath($sid, $n);
        if (is_file($p)) {
            unlink($p);
        }
    }

    public function pretendUploaded(string $sid, int $n): void
    {
        $this->sessions[$sid]['pretend'][$n] = true;
    }

    /** @return array<int,int> */
    public function partsReceived(string $sid): array
    {
        $r = $this->received[$sid] ?? [];
        ksort($r);
        return $r;
    }

    /** Make status lie about the stored byte count (a server that over-counted). */
    public function reportBytesUploaded(string $sid, int $bytes): void
    {
        $this->bytesOverride[$sid] = $bytes;
    }

    public function firstSessionId(): ?string
    {
        $k = array_keys($this->sessions);
        return $k[0] ?? null;
    }

    /** @return list<string> */
    public function requestLines(): array
    {
        return array_map(static function (array $r): string {
            return $r['method'] . ' ' . strtok((string) parse_url($r['url'], PHP_URL_PATH), '?');
        }, $this->requests);
    }

    public function send(array $req): array
    {
        $path = (string) parse_url($req['url'], PHP_URL_PATH);
        $query = [];
        parse_str((string) parse_url($req['url'], PHP_URL_QUERY), $query);
        // Keep requests light: a large body is spilled to a temp file and recorded as a length and digest only.
        $spill = null;
        if (!empty($req['bodyFile'])) {
            $f = $req['bodyFile'];
            if ((int) $f['length'] > 65536) {
                $spill = $this->spill(['path' => (string) $f['path'], 'offset' => (int) $f['offset'], 'length' => (int) $f['length']], null);
            } else {
                $req['body'] = (string) file_get_contents($f['path'], false, null, $f['offset'], $f['length']);
            }
        } elseif (isset($req['body']) && strlen((string) $req['body']) > 65536) {
            $spill = $this->spill(null, (string) $req['body']);
            unset($req['body']);
        }
        $rec = $req;
        if ($spill !== null) {
            $rec['body_len'] = (int) filesize($spill);
            $rec['body_sha256'] = (string) hash_file('sha256', $spill);
        }
        $this->requests[] = $rec;
        try {
            return $this->dispatch($req, $path, $query, $spill);
        } finally {
            if ($spill !== null && is_file($spill)) {
                unlink($spill);
            }
        }
    }

    /**
     * @param array<string,mixed> $req
     * @param array<string,mixed> $query
     * @param string|null $spill the request body when it was spilled to disk
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function dispatch(array $req, string $path, array $query, ?string $spill): array
    {
        $m = $req['method'];
        foreach ($this->fail as $i => $f) {
            if ($f['method'] === $m && preg_match($f['re'], $path) === 1) {
                unset($this->fail[$i]);
                $this->fail = array_values($this->fail);
                if ($f['applyFirst'] && preg_match('#^/api/upload/([^/]+)/complete$#', $path, $mm) === 1) {
                    $this->doComplete($mm[1]);
                }
                return ['headers' => $f['headers']] + $this->json($f['status'], ['error' => $f['status'] === 409 ? 'Upload session is already complete' : 'unavailable']);
            }
        }

        $routed = $this->route($m, $path, $req);
        if ($routed !== null) {
            return $routed;
        }
        if ($m === 'POST' && $path === '/api/upload/init') {
            $b = json_decode((string) $req['body'], true);
            $sid = 'upl_' . (++$this->seq);
            $size = (int) $b['file_size'];
            $multi = !empty($b['force_multipart']) || $size > 50 * 1048576;
            $total = $multi ? max(1, (int) ceil($size / $this->partSize)) : 0;
            $this->sessions[$sid] = ['name' => $b['file_name'], 'folder' => (string) ($b['folder_id'] ?? ''), 'size' => $size, 'parts' => [], 'pretend' => [], 'complete' => false, 'file_id' => null, 'total' => $total];
            return $this->json(201, [
                'ok' => true, 'session_id' => $sid,
                'resumable' => $multi ? ['part_size' => $this->partSize, 'total_parts' => $total] : null,
            ]);
        }
        if ($m === 'PUT' && $path === '/api/upload/file') {
            // mirrors apps/api src/lib/upload/idempotency.ts: a UUID v4 key replays the first 201 body as a 200
            $key = isset($req['headers']['X-Dosya-Idempotency-Key']) ? strtolower(trim((string) $req['headers']['X-Dosya-Idempotency-Key'])) : '';
            if ($key !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $key) !== 1) {
                return $this->json(400, ['error' => 'X-Dosya-Idempotency-Key must be a UUID v4']);
            }
            if ($key !== '' && isset($this->idempotent[$key])) {
                return $this->json(200, $this->idempotent[$key]);
            }
            $given = $req['headers']['X-Dosya-Sha256'] ?? '';
            $actual = $spill !== null ? (string) hash_file('sha256', $spill) : hash('sha256', (string) ($req['body'] ?? ''));
            if ($given !== $actual) {
                return $this->json(400, ['error' => 'checksum mismatch']);
            }
            $id = 'file_' . (++$this->seq);
            $this->files[$id] = $this->storeBody($this->filePath($id), $req, $spill);
            $this->fileMeta[$id] = ['folder' => (string) ($query['folder_id'] ?? ''), 'name' => (string) ($query['file_name'] ?? '')];
            $body = ['ok' => true, 'file' => ['id' => $id, 'size_bytes' => $this->files[$id]]];
            if ($key !== '') {
                $this->idempotent[$key] = $body;
            }
            return $this->json(201, $body);
        }
        if (preg_match('#^/api/upload/([^/]+)(?:/(part/(\d+)|status|complete))?$#', $path, $mm) === 1 && isset($this->sessions[$mm[1]])) {
            $sid = $mm[1];
            $what = $mm[2] ?? '';
            if ($m === 'PUT' && ($mm[3] ?? '') !== '') {
                if ($this->sessions[$sid]['complete']) {
                    return $this->json(409, ['error' => 'Upload session is already complete']);
                }
                $n = (int) $mm[3];
                if ($this->sessions[$sid]['total'] > 0 && $n > $this->sessions[$sid]['total']) {
                    return $this->json(400, ['error' => 'Part number ' . $n . ' exceeds total parts ' . $this->sessions[$sid]['total']]);
                }
                $this->sessions[$sid]['parts'][$n] = $this->storeBody($this->partPath($sid, $n), $req, $spill);
                $this->received[$sid][$n] = ($this->received[$sid][$n] ?? 0) + 1;
                return $this->json(200, ['ok' => true, 'part_number' => $n]);
            }
            if ($m === 'GET' && $what === 'status') {
                $s = $this->sessions[$sid];
                $nums = array_keys($s['parts'] + $s['pretend']);
                sort($nums);
                return $this->json(200, [
                    'ok' => true, 'status' => $s['complete'] ? 'complete' : 'uploading',
                    'uploaded_parts' => $nums, 'bytes_uploaded' => $this->bytesOverride[$sid] ?? ($s['complete'] ? $this->files[$s['file_id']] : 0), 'size_bytes' => $s['size'], 'file_id' => $s['complete'] ? $s['file_id'] : null,
                ]);
            }
            if ($m === 'POST' && $what === 'complete') {
                if ($this->sessions[$sid]['complete']) {
                    return $this->json(409, ['error' => 'Upload session is already complete']);
                }
                if (count($this->sessions[$sid]['parts']) < $this->sessions[$sid]['total']) {
                    return $this->json(400, ['error' => 'Only ' . count($this->sessions[$sid]['parts']) . ' of ' . $this->sessions[$sid]['total'] . ' parts uploaded. Upload remaining parts before completing.']);
                }
                return $this->json(200, $this->doComplete($sid));
            }
            if ($m === 'DELETE' && $what === '') {
                foreach (array_keys($this->sessions[$sid]['parts']) as $n) {
                    $this->forgetPart($sid, (int) $n);
                }
                unset($this->sessions[$sid]);
                return $this->json(200, ['ok' => true, 'already_gone' => false]);
            }
        }
        if (preg_match('#^/api/upload/([^/]+)#', $path) === 1) {
            return $this->json(404, ['error' => 'Upload session not found']);
        }
        throw new \LogicException('unhandled request ' . $m . ' ' . $path);
    }

    /**
     * Extra routes for subclasses; null falls through to the upload routes.
     * @param array<string,mixed> $req
     * @return array{status:int,headers:array<string,string>,body:string}|null
     */
    protected function route(string $method, string $path, array $req): ?array
    {
        return null;
    }

    /** @return array<string,mixed> */
    private function doComplete(string $sid): array
    {
        $s = &$this->sessions[$sid];
        ksort($s['parts']);
        $id = 'file_' . (++$this->seq);
        $out = fopen($this->filePath($id), 'wb');
        foreach (array_keys($s['parts']) as $n) {
            $in = fopen($this->partPath($sid, (int) $n), 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            unlink($this->partPath($sid, (int) $n));
        }
        fclose($out);
        $this->files[$id] = (int) filesize($this->filePath($id));
        $this->fileMeta[$id] = ['folder' => (string) ($s['folder'] ?? ''), 'name' => (string) $s['name']];
        $s['parts'] = array_fill_keys(array_keys($s['parts']), 0);
        $s['complete'] = true;
        $s['file_id'] = $id;
        return ['ok' => true, 'file' => ['id' => $id, 'size_bytes' => $this->files[$id]]];
    }

    private function dir(): string
    {
        if ($this->dir === null) {
            $d = sys_get_temp_dir() . '/dosya-fake-' . bin2hex(random_bytes(8));
            if (!mkdir($d, 0700, true) && !is_dir($d)) {
                throw new \RuntimeException('cannot create ' . $d);
            }
            $this->dir = $d;
        }
        return $this->dir;
    }

    private function filePath(string $id): string
    {
        return $this->dir() . '/f_' . $id;
    }

    private function partPath(string $sid, int $n): string
    {
        return $this->dir() . '/p_' . $sid . '_' . $n;
    }

    /** Writes a large request body (a slice of a file, or bytes) to a temp file and returns its path. @param array{path:string,offset:int,length:int}|null $slice */
    private function spill(?array $slice, ?string $bytes): string
    {
        $p = $this->dir() . '/req_' . (++$this->seq);
        if ($bytes !== null) {
            file_put_contents($p, $bytes);
            return $p;
        }
        $in = fopen($slice['path'], 'rb');
        $out = fopen($p, 'wb');
        if ($in === false || $out === false) {
            throw new \RuntimeException('cannot spill request body');
        }
        stream_copy_to_stream($in, $out, $slice['length'], $slice['offset']);
        fclose($in);
        fclose($out);
        return $p;
    }

    /** Stores the request body at $dest (moving the spilled file when there is one) and returns its size. @param array<string,mixed> $req */
    private function storeBody(string $dest, array $req, ?string $spill): int
    {
        if ($spill !== null) {
            rename($spill, $dest);
        } else {
            file_put_contents($dest, (string) ($req['body'] ?? ''));
        }
        return (int) filesize($dest);
    }

    /** @param array<string,mixed> $body @return array{status:int,headers:array<string,string>,body:string} */
    protected function json(int $status, array $body): array
    {
        return ['status' => $status, 'headers' => [], 'body' => (string) json_encode($body)];
    }
}
