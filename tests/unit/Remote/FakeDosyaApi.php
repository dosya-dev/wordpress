<?php
namespace Dosya\Backup\Tests\unit\Remote;

/**
 * FakeDosyaUploads plus the backup routes: POST /api/wp/backups,
 * POST /api/wp/backups/{id}/complete (with the server's checks on where the
 * files live, their sizes, the byte counts and the refs) and
 * GET /api/wp/sites/{id}/backups (newest first). The fake serves one site.
 */
final class FakeDosyaApi extends FakeDosyaUploads
{
    /** @var array<string,array<string,mixed>> backup id => state */
    public array $backups = [];
    public int $registerCalls = 0;
    public int $completeCalls = 0;
    /** @var list<array<string,mixed>> */
    public array $completeBodies = [];
    /** Set to a reason (e.g. site_not_active) to refuse registration with a 409. */
    public ?string $refuseRegister = null;
    private int $folderSeq = 0;
    public int $listCalls = 0;

    /** Marks a stored backup verified (the verifier ran); complete then answers state verified. */
    public function markVerified(string $id): void
    {
        $this->backups[$id]['state'] = 'verified';
    }

    protected function route(string $method, string $path, array $req): ?array
    {
        if ($method === 'POST' && $path === '/api/wp/backups') {
            return $this->register(json_decode((string) $req['body'], true));
        }
        if ($method === 'POST' && preg_match('#^/api/wp/backups/([^/]+)/complete$#', $path, $mm) === 1) {
            return $this->complete(rawurldecode($mm[1]), json_decode((string) $req['body'], true));
        }
        if ($method === 'GET' && preg_match('#^/api/wp/sites/[^/]+/backups$#', $path) === 1) {
            return $this->listBackups();
        }
        return null;
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private function listBackups(): array
    {
        $this->listCalls++;
        $out = [];
        foreach (array_reverse($this->backups, true) as $id => $s) {
            $out[] = [
                'id' => (string) $id, 'kind' => $s['kind'], 'state' => $s['state'],
                'bytes_new' => $s['body']['bytes_new'] ?? null, 'bytes_total' => $s['body']['bytes_total'] ?? null,
                'volume_count' => isset($s['body']['volumes']) ? count($s['body']['volumes']) : 0,
                'locked_until' => $s['locked_until'], 'expires_at' => $s['expires_at'],
                'created_at' => $s['created_at'], 'completed_at' => $s['completed_at'] ?? null,
            ];
        }
        return $this->json(200, ['backups' => $out]);
    }

    /** @param mixed $b @return array{status:int,headers:array<string,string>,body:string} */
    private function register($b): array
    {
        $this->registerCalls++;
        if ($this->refuseRegister !== null) {
            return $this->json(409, ['error' => 'This site is not active in dosya. Connect it again.', 'reason' => $this->refuseRegister]);
        }
        $id = is_array($b) ? ($b['backup_id'] ?? null) : null;
        if (!is_string($id) || preg_match('/^[0-9a-f]{32}$/', $id) !== 1 || !in_array($b['kind'] ?? null, ['full', 'incremental'], true)
            || !is_int($b['started_at'] ?? null)) {
            return $this->json(400, ['error' => 'Invalid backup', 'reason' => 'bad_request']);
        }
        if (isset($this->backups[$id])) {
            $s = $this->backups[$id];
            return $this->json(200, ['ok' => true, 'backup_id' => $id, 'folder_id' => $s['folder_id'], 'volumes_folder_id' => $s['volumes_folder_id']]);
        }
        $this->backups[$id] = [
            'state' => 'running', 'kind' => $b['kind'],
            'folder_id' => 'fld_b' . (++$this->folderSeq), 'volumes_folder_id' => 'fld_v' . $this->folderSeq,
            'locked_until' => null, 'expires_at' => null, 'created_at' => (int) $b['started_at'],
        ];
        $s = $this->backups[$id];
        return $this->json(201, ['ok' => true, 'backup_id' => $id, 'folder_id' => $s['folder_id'], 'volumes_folder_id' => $s['volumes_folder_id']]);
    }

    /** @param mixed $b @return array{status:int,headers:array<string,string>,body:string} */
    private function complete(string $id, $b): array
    {
        $this->completeCalls++;
        $this->completeBodies[] = is_array($b) ? $b : [];
        if (!isset($this->backups[$id])) {
            return $this->json(404, ['error' => 'Backup not found']);
        }
        $s = &$this->backups[$id];
        if ($s['state'] === 'uploaded' || $s['state'] === 'verified') {
            return $this->json(200, ['ok' => true, 'state' => $s['state'], 'locked_until' => $s['locked_until'], 'expires_at' => $s['expires_at']]);
        }
        if (!is_array($b) || !is_array($b['volumes'] ?? null) || !is_array($b['refs'] ?? null)) {
            return $this->json(400, ['error' => 'Invalid body', 'reason' => 'bad_request']);
        }
        if (count($b['volumes']) > 2000) {
            return $this->json(413, ['error' => 'Too many volumes', 'reason' => 'too_many_volumes']);
        }
        $seen = [];
        $sum = 0;
        foreach ($b['volumes'] as $v) {
            $fid = $v['file_id'] ?? null;
            if (!is_string($fid) || !$this->hasFile($fid) || isset($seen[$fid])
                || $this->fileMeta[$fid]['folder'] !== $s['volumes_folder_id']
                || (int) ($v['size'] ?? -1) !== $this->fileSize($fid)
                || preg_match('/^[0-9a-f]{64}$/', (string) ($v['sha256'] ?? '')) !== 1) {
                return $this->json(400, ['error' => 'Volume ' . (int) ($v['volume_no'] ?? 0) . ' is not a valid upload', 'reason' => 'bad_file']);
            }
            $seen[$fid] = true;
            $sum += (int) $v['size'];
        }
        foreach (['manifest_file_id', 'public_manifest_file_id'] as $k) {
            $fid = $b[$k] ?? null;
            if (!is_string($fid) || !$this->hasFile($fid) || isset($seen[$fid])
                || $this->fileMeta[$fid]['folder'] !== $s['folder_id']) {
                return $this->json(400, ['error' => 'The manifest is not a valid upload', 'reason' => 'bad_file']);
            }
            $seen[$fid] = true;
        }
        if ($s['kind'] === 'full' && $b['refs'] !== []) {
            return $this->json(400, ['error' => 'A full backup has no references', 'reason' => 'bad_ref']);
        }
        foreach ($b['refs'] as $r) {
            // mirrors the server: a ref names another backup of this site that is stored (uploaded or verified)
            $rid = is_array($r) ? ($r['backup_id'] ?? null) : null;
            if (!is_string($rid) || $rid === $id || !is_int($r['volume_no'] ?? null) || !isset($this->backups[$rid])
                || !in_array($this->backups[$rid]['state'], ['uploaded', 'verified'], true)) {
                return $this->json(400, ['error' => 'A referenced backup is not available', 'reason' => 'bad_ref']);
            }
        }
        if (!is_int($b['bytes_new'] ?? null) || !is_int($b['bytes_total'] ?? null) || $b['bytes_new'] > $b['bytes_total'] || $b['bytes_new'] !== $sum) {
            return $this->json(400, ['error' => 'Byte counts do not add up', 'reason' => 'bad_bytes']);
        }
        $s['state'] = 'uploaded';
        $s['completed_at'] = (int) ($s['created_at'] ?? 0) + 1;
        $s['locked_until'] = 1800000000;
        $s['expires_at'] = 1802592000;
        $s['body'] = $b;
        return $this->json(200, ['ok' => true, 'state' => 'uploaded', 'locked_until' => $s['locked_until'], 'expires_at' => $s['expires_at']]);
    }
}
