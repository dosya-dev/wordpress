<?php
/**
 * A stand-alone fake of the dosya API for the plugin's `remote` e2e mode.
 * Run: php -S 0.0.0.0:9000 server.php   (data lives in $FAKE_DOSYA_DATA, default /data)
 *
 * It implements the routes the WordPress plugin calls, with the real routes' shapes:
 *   POST /api/wp/connect/exchange             PKCE exchange, 201 site key payload, 410 on a bad/used/old code
 *   POST /api/wp/backups                      register (201 new, 200 repeat), creates backups/<id>/volumes
 *   POST /api/wp/backups/{id}/complete        validates files, folders, sizes, digests, refs; writes a lock
 *   GET  /api/wp/sites/{id}/backups           newest first
 *   POST /api/wp/sites/{id}/heartbeat
 *   POST /api/wp/sites/{id}/disconnect        marks the site disconnected and deletes its key
 *   POST /api/upload/init, PUT /api/upload/{sid}/part/{n}, GET /api/upload/{sid}/status,
 *   POST /api/upload/{sid}/complete, DELETE /api/upload/{sid}, PUT /api/upload/file (one-shot)
 * and test-only knobs:
 *   POST /fake/consent      {code_challenge, state, site_url, name?} -> {code, state}
 *   GET  /fake/dump[?hash=1] the whole state (hash=1 adds each stored object's on-disk sha256)
 *   POST /fake/reset        forgets everything
 *   POST /fake/forget-part  {session_id?, part} drops a stored part (and its receive-log entry) to force a resume;
 *                           {part, when: "status"} arms it instead: the part is dropped by the next status call of an
 *                           open session that holds it (the uploader's resume at the start of a step), before answering
 *   POST /fake/fail-next    {route, status?} the next request to that route answers 503 (or status) once
 *   POST /fake/delay        {route, ms, count?} requests to that route sleep ms first (count times, default always; 0 clears)
 * Any /api/* request outside the wp_site allowlist (apps/api/src/lib/wp/allowlist.ts) gets the real 403
 * wp_site_scope answer, except the unauthenticated exchange.
 *
 * Object bodies are written to <data>/<file_id> and session parts to <data>/parts/<sid>/<n>, never held
 * in memory; metadata is <data>/state.json. Site keys are stored only as sha256 hashes and never logged.
 */

declare(strict_types=1);

const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
const PART_SIZE = 10 * 1024 * 1024;
const MULTIPART_THRESHOLD = 50 * 1024 * 1024;
const ONE_SHOT_MAX = 50 * 1024 * 1024;
const CODE_TTL = 900;
const MAX_VOLUMES = 2000;
const MAX_REFS = 2000;
const BACKUP_ID_RE = '/^[0-9a-f]{32}$/';
const FILE_ID_RE = '/^[A-Za-z0-9_-]{1,64}$/';
const UUID_V4_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
const GONE = 'Invalid or expired code. Connect the site again.';

final class Reply extends Exception
{
    public int $status;
    /** @var array<string,mixed> */
    public array $body;

    /** @param array<string,mixed> $body */
    public function __construct(int $status, array $body)
    {
        parent::__construct('reply ' . $status);
        $this->status = $status;
        $this->body = $body;
    }
}

/** @param array<string,mixed> $extra */
function err(string $message, int $status = 400, array $extra = []): Reply
{
    return new Reply($status, ['ok' => false, 'error' => $message] + $extra);
}

function dataDir(): string
{
    $d = getenv('FAKE_DOSYA_DATA') ?: '/data';
    if (!is_dir($d . '/parts')) {
        mkdir($d . '/parts', 0777, true);
    }
    return $d;
}

/** @return array<string,mixed> */
function emptyState(): array
{
    return [
        'seq' => 0, 'consents' => [], 'sites' => [], 'keys' => [], 'folders' => [], 'files' => [],
        'sessions' => [], 'idempotency' => [], 'backups' => [], 'locks' => [],
        'received' => [], 'forgotten' => [], 'armed_forget' => null, 'fail_next' => [], 'delay' => [], 'log' => [],
    ];
}

function nextId(array &$s, string $prefix): string
{
    $s['seq']++;
    return $prefix . $s['seq'] . '_' . bin2hex(random_bytes(4));
}

/** @return array<string,mixed> */
function body(): array
{
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }
    try {
        $b = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw err('Invalid request body');
    }
    if (!is_array($b) || array_is_list($b) && $b !== []) {
        throw err('Invalid request body');
    }
    return $b;
}

function header_(string $name): ?string
{
    $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$k]) ? (string) $_SERVER[$k] : null;
}

/** Streams the request body to $dest; returns [bytes, sha256]. A $max overflow deletes the file and answers 413. @return array{0:int,1:string} */
function receiveBody(string $dest, int $max, string $what): array
{
    $in = fopen('php://input', 'rb');
    $out = fopen($dest, 'wb');
    $h = hash_init('sha256');
    $n = 0;
    while (!feof($in)) {
        $chunk = fread($in, 1 << 20);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $n += strlen($chunk);
        if ($n > $max) {
            fclose($in);
            fclose($out);
            unlink($dest);
            throw err($what . ' exceeds the ' . intdiv($max, 1048576) . ' MB limit', 413);
        }
        hash_update($h, $chunk);
        fwrite($out, $chunk);
    }
    fclose($in);
    fclose($out);
    return [$n, hash_final($h)];
}

/** The site that owns the bearer key, or a 401. @return array<string,mixed> */
function authSite(array &$s): array
{
    $auth = header_('Authorization') ?? '';
    if (preg_match('/^Bearer\s+(\S+)$/', $auth, $m) !== 1) {
        throw err('Not authenticated', 401);
    }
    $siteId = $s['keys'][hash('sha256', $m[1])] ?? null;
    if ($siteId === null || !isset($s['sites'][$siteId])) {
        throw err('Not authenticated', 401);
    }
    return $s['sites'][$siteId];
}

function newFolder(array &$s, ?string $parent, string $name, string $siteId): string
{
    foreach ($s['folders'] as $id => $f) {
        if ($f['parent_id'] === $parent && $f['name'] === $name && $f['site_id'] === $siteId) {
            return (string) $id;
        }
    }
    $id = nextId($s, 'fld_');
    $s['folders'][$id] = ['id' => $id, 'parent_id' => $parent, 'name' => $name, 'site_id' => $siteId];
    return $id;
}

/** True when $folderId is $ancestor or below it. */
function within(array $s, string $folderId, string $ancestor): bool
{
    for ($id = $folderId, $i = 0; $id !== null && $i < 64; $i++) {
        if ($id === $ancestor) {
            return true;
        }
        $id = $s['folders'][$id]['parent_id'] ?? null;
    }
    return false;
}

/** The folder a write may land in: this site's, live, and not inside a locked backup folder. */
function writableFolder(array $s, array $site, string $workspaceId, string $folderId): void
{
    if ($workspaceId !== $site['workspace_id']) {
        throw err('This key cannot write to that workspace', 403);
    }
    if (!isset($s['folders'][$folderId]) || !within($s, $folderId, $site['root_folder_id'])) {
        throw err('Folder not found', 404);
    }
    foreach ($s['locks'] as $lock) {
        if ($lock['locked_until'] > time() && within($s, $folderId, $lock['folder_id'])) {
            // lib/backup-lock.ts backupLockResponse (the message carries the JS toISOString date)
            throw new Reply(423, [
                'error' => 'backup_locked',
                'message' => 'This backup is locked until ' . gmdate('Y-m-d\\TH:i:s.000\\Z', $lock['locked_until']) . ' to protect it from deletion.',
                'backup_id' => $lock['backup_id'], 'locked_until' => $lock['locked_until'],
            ]);
        }
    }
}

/** @return array<string,mixed> */
function storedFile(array &$s, string $id, string $folderId, string $name, int $size, string $sha): array
{
    $s['files'][$id] = ['id' => $id, 'folder_id' => $folderId, 'name' => $name, 'size_bytes' => $size, 'sha256' => $sha, 'created_at' => time()];
    return ['id' => $id, 'name' => $name, 'size_bytes' => $size];
}

/* ---------------- handlers ---------------- */

function fakeConsent(array &$s): array
{
    $b = body();
    $challenge = (string) ($b['code_challenge'] ?? '');
    if (preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) !== 1) {
        throw err('code_challenge must be an S256 challenge');
    }
    $code = 'wpc_' . substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(48))), 0, 32);
    $s['consents'][hash('sha256', $code)] = [
        'challenge' => $challenge, 'state' => (string) ($b['state'] ?? ''), 'site_url' => (string) ($b['site_url'] ?? ''),
        'home_url' => (string) ($b['home_url'] ?? ($b['site_url'] ?? '')), 'name' => (string) ($b['name'] ?? 'WordPress site'),
        'created_at' => time(), 'used_at' => null,
    ];
    return [200, ['code' => $code, 'state' => (string) ($b['state'] ?? '')]];
}

function exchange(array &$s): array
{
    if (header_('Authorization') !== null) {
        throw err('Call this without an Authorization header');
    }
    $b = body();
    $code = is_string($b['code'] ?? null) ? $b['code'] : '';
    $verifier = is_string($b['code_verifier'] ?? null) ? $b['code_verifier'] : '';
    if (preg_match('/^wpc_[0-9A-Za-z]{32}$/', $code) !== 1) {
        throw err('code is missing or malformed');
    }
    if ($verifier === '') {
        throw err('code_verifier is required');
    }
    $k = hash('sha256', $code);
    $c = $s['consents'][$k] ?? null;
    if ($c === null || $c['used_at'] !== null || time() - $c['created_at'] >= CODE_TTL) {
        throw err(GONE, 410);
    }
    $digest = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1 || !hash_equals($c['challenge'], $digest)) {
        throw err(GONE, 410);
    }
    $s['consents'][$k]['used_at'] = time();

    $siteId = nextId($s, 'wps_');
    $root = newFolder($s, null, 'WordPress ' . $c['name'], $siteId);
    $key = 'dos_' . bin2hex(random_bytes(16));
    $s['keys'][hash('sha256', $key)] = $siteId;
    $s['sites'][$siteId] = [
        'id' => $siteId, 'workspace_id' => 'ws_fake', 'root_folder_id' => $root, 'status' => 'active',
        'site_url' => $c['site_url'], 'home_url' => $c['home_url'], 'name' => $c['name'],
        'retention_days' => 7, 'immutability_hours' => 24, 'created_at' => time(), 'heartbeat_at' => null,
        'disconnected_at' => null,
    ];
    return [201, [
        'ok' => true, 'site_key' => $key, 'site_id' => $siteId, 'workspace_id' => 'ws_fake', 'root_folder_id' => $root,
        'signing_keys' => [], 'caps' => ['sites_max' => null, 'retention_days_max' => 30, 'immutability_hours_max' => 168],
        'schedule' => null, 'retention_days' => 7, 'immutability_hours' => 24,
    ]];
}

function register(array &$s): array
{
    $site = authSite($s);
    if ($site['status'] === 'disconnected') {
        throw err('This site is disconnected', 409, ['reason' => 'site_not_active']);
    }
    $b = body();
    $id = $b['backup_id'] ?? null;
    if (!is_string($id) || preg_match(BACKUP_ID_RE, $id) !== 1) {
        throw err('backup_id must be 32 lowercase hex characters');
    }
    if (!in_array($b['kind'] ?? null, ['full', 'incremental'], true)) {
        throw err('kind must be "full" or "incremental"');
    }
    if (isset($b['started_at']) && !is_int($b['started_at']) && !is_float($b['started_at'])) {
        throw err('started_at must be a number');
    }
    if (isset($s['backups'][$id])) {
        $e = $s['backups'][$id];
        if ($e['site_id'] !== $site['id']) {
            throw err('backup_id is already in use', 409, ['reason' => 'backup_id_taken']);
        }
        return [200, ['ok' => true, 'backup_id' => $id, 'folder_id' => $e['folder_id'], 'volumes_folder_id' => $e['volumes_folder_id']]];
    }
    $backups = newFolder($s, $site['root_folder_id'], 'backups', $site['id']);
    $folder = newFolder($s, $backups, $id, $site['id']);
    $volumes = newFolder($s, $folder, 'volumes', $site['id']);
    $s['backups'][$id] = [
        'id' => $id, 'site_id' => $site['id'], 'kind' => $b['kind'], 'state' => 'running', 'folder_id' => $folder,
        'volumes_folder_id' => $volumes, 'created_at' => time(), 'started_at' => (int) ($b['started_at'] ?? time()),
        'completed_at' => null, 'locked_until' => null, 'expires_at' => null, 'bytes_new' => null, 'bytes_total' => null,
        'volumes' => [], 'refs' => [], 'manifest_file_id' => null, 'public_manifest_file_id' => null, 'seq' => $s['seq'],
    ];
    return [201, ['ok' => true, 'backup_id' => $id, 'folder_id' => $folder, 'volumes_folder_id' => $volumes]];
}

/** @return array{0:int,1:array<string,mixed>} */
function doneReply(array $b): array
{
    return [200, ['ok' => true, 'state' => $b['state'] === 'verified' ? 'verified' : 'uploaded', 'locked_until' => $b['locked_until'], 'expires_at' => $b['expires_at']]];
}

function complete(array &$s, string $id): array
{
    $site = authSite($s);
    $bk = preg_match(BACKUP_ID_RE, $id) === 1 ? ($s['backups'][$id] ?? null) : null;
    if ($bk === null || $bk['site_id'] !== $site['id']) {
        throw err('Backup not found', 404);
    }
    if ($bk['state'] === 'uploaded' || $bk['state'] === 'verified') {
        return doneReply($bk);
    }
    if ($bk['state'] !== 'running') {
        throw err('This backup is not running', 409, ['reason' => 'not_running']);
    }
    if ($site['status'] === 'disconnected') {
        throw err('This site is disconnected', 409, ['reason' => 'site_not_active']);
    }
    $b = body();
    $isCount = static function ($v): bool { return is_int($v) && $v >= 0; };
    if ((is_array($b['volumes'] ?? null) && count($b['volumes']) > MAX_VOLUMES) || (is_array($b['refs'] ?? null) && count($b['refs']) > MAX_REFS)) {
        throw err('A backup can have at most ' . MAX_VOLUMES . ' volumes and ' . MAX_REFS . ' refs', 413, ['reason' => 'too_many_volumes']);
    }
    if (!is_array($b['volumes'] ?? null) || $b['volumes'] === [] || !array_is_list($b['volumes'])) {
        throw err('volumes must be a non-empty array');
    }
    $volumes = [];
    foreach ($b['volumes'] as $v) {
        if (!is_array($v) || array_is_list($v) && $v !== []) {
            throw err('each volume must be an object');
        }
        $no = $v['volume_no'] ?? null;
        if (!is_int($no) || $no < 1) {
            throw err('volume_no must be a positive integer');
        }
        if (isset($volumes[$no])) {
            throw err('volume ' . $no . ' appears twice');
        }
        if (!is_string($v['file_id'] ?? null) || preg_match(FILE_ID_RE, $v['file_id']) !== 1) {
            throw err('volume ' . $no . ': file_id is invalid');
        }
        if (!$isCount($v['size'] ?? null)) {
            throw err('volume ' . $no . ': size must be an integer >= 0');
        }
        if (!is_string($v['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $v['sha256']) !== 1) {
            throw err('volume ' . $no . ': sha256 must be 64 lowercase hex characters');
        }
        $volumes[$no] = ['volume_no' => $no, 'file_id' => $v['file_id'], 'size' => $v['size'], 'sha256' => $v['sha256']];
    }
    $refs = [];
    $rawRefs = $b['refs'] ?? [];
    if (!is_array($rawRefs) || !array_is_list($rawRefs)) {
        throw err('refs must be an array');
    }
    foreach ($rawRefs as $r) {
        if (!is_array($r) || array_is_list($r) && $r !== []) {
            throw err('each ref must be an object');
        }
        if (!is_string($r['backup_id'] ?? null) || preg_match(BACKUP_ID_RE, $r['backup_id']) !== 1) {
            throw err('ref backup_id must be 32 lowercase hex characters');
        }
        if (!is_int($r['volume_no'] ?? null) || $r['volume_no'] < 1) {
            throw err('ref volume_no must be a positive integer');
        }
        $refs[] = ['backup_id' => $r['backup_id'], 'volume_no' => $r['volume_no']];
    }
    foreach (['manifest_file_id', 'public_manifest_file_id'] as $k) {
        if (!is_string($b[$k] ?? null) || preg_match(FILE_ID_RE, $b[$k]) !== 1) {
            throw err($k . ' is invalid');
        }
    }
    if (!$isCount($b['bytes_new'] ?? null) || !$isCount($b['bytes_total'] ?? null)) {
        throw err('bytes_new and bytes_total must be integers >= 0');
    }
    if ($b['bytes_new'] > $b['bytes_total']) {
        throw err('bytes_new cannot exceed bytes_total');
    }
    $all = array_merge(array_column($volumes, 'file_id'), [$b['manifest_file_id'], $b['public_manifest_file_id']]);
    foreach (array_count_values($all) as $fid => $n) {
        if ($n > 1) {
            throw err('file ' . $fid . ' is used more than once', 400, ['reason' => 'bad_file']);
        }
    }
    $bad = static function (string $fid, string $why): Reply { return err('file ' . $fid . ': ' . $why, 400, ['reason' => 'bad_file']); };
    foreach ($all as $fid) {
        if (!isset($s['files'][$fid])) {
            throw $bad($fid, 'is not a live file of this workspace');
        }
    }
    foreach ($volumes as $v) {
        $f = $s['files'][$v['file_id']];
        if (!within($s, $f['folder_id'], $bk['volumes_folder_id'])) {
            throw $bad($v['file_id'], "is not inside the backup's volumes folder");
        }
        if ($f['size_bytes'] !== $v['size']) {
            throw $bad($v['file_id'], 'volume ' . $v['volume_no'] . ' size does not match the stored file');
        }
        // fake-only: the real server cannot hash parts it never held together; the fake checks what it stored
        if (hash_file('sha256', dataDir() . '/' . $v['file_id']) !== $v['sha256']) {
            throw $bad($v['file_id'], 'volume ' . $v['volume_no'] . ' digest does not match the stored bytes');
        }
    }
    foreach ([$b['manifest_file_id'], $b['public_manifest_file_id']] as $fid) {
        $f = $s['files'][$fid];
        if (within($s, $f['folder_id'], $bk['volumes_folder_id'])) {
            throw $bad($fid, 'a manifest cannot live in the volumes folder');
        }
        if (!within($s, $f['folder_id'], $bk['folder_id'])) {
            throw $bad($fid, "is not inside the backup's folder");
        }
    }
    foreach ($refs as $r) {
        $t = $r['backup_id'] === $id ? null : ($s['backups'][$r['backup_id']] ?? null);
        if ($t === null || $t['site_id'] !== $site['id'] || !in_array($t['state'], ['uploaded', 'verified'], true)) {
            throw err('A ref points at a backup that is not an uploaded backup of this site', 400, ['reason' => 'bad_ref']);
        }
    }
    $now = time();
    $bk['state'] = 'uploaded';
    $bk['completed_at'] = $now;
    $bk['locked_until'] = $now + $site['immutability_hours'] * 3600;
    $bk['expires_at'] = $now + $site['retention_days'] * 86400;
    $bk['bytes_new'] = $b['bytes_new'];
    $bk['bytes_total'] = $b['bytes_total'];
    $bk['volumes'] = array_values($volumes);
    $bk['refs'] = $refs;
    $bk['manifest_file_id'] = $b['manifest_file_id'];
    $bk['public_manifest_file_id'] = $b['public_manifest_file_id'];
    $s['backups'][$id] = $bk;
    $s['locks'][$bk['folder_id']] = ['folder_id' => $bk['folder_id'], 'backup_id' => $id, 'locked_until' => $bk['locked_until']];
    return doneReply($bk);
}

/** The site the URL names, which must be the key's own site. @return array<string,mixed> */
function ownSite(array &$s, string $siteId): array
{
    $site = authSite($s);
    if ($site['id'] !== $siteId) {
        throw err('This key belongs to a different site', 403);
    }
    return $site;
}

function listBackups(array &$s, string $siteId): array
{
    ownSite($s, $siteId);
    $out = [];
    foreach ($s['backups'] as $b) {
        if ($b['site_id'] === $siteId) {
            $out[] = $b;
        }
    }
    usort($out, static function (array $a, array $b): int { return [$b['created_at'], $b['seq']] <=> [$a['created_at'], $a['seq']]; });
    return [200, ['backups' => array_map(static function (array $b): array {
        return [
            'id' => $b['id'], 'kind' => $b['kind'], 'state' => $b['state'], 'bytes_new' => $b['bytes_new'], 'bytes_total' => $b['bytes_total'],
            'volume_count' => count($b['volumes']), 'locked_until' => $b['locked_until'], 'expires_at' => $b['expires_at'],
            'created_at' => $b['created_at'], 'completed_at' => $b['completed_at'],
        ];
    }, array_slice($out, 0, 200))]];
}

function heartbeat(array &$s, string $siteId): array
{
    $site = ownSite($s, $siteId);
    if ($site['status'] === 'disconnected') {
        throw err('This site is disconnected', 409, ['reason' => 'site_not_active']);
    }
    body();
    $s['sites'][$siteId]['heartbeat_at'] = time();
    return [200, ['ok' => true, 'status' => $site['status'], 'schedule' => null,
        'caps' => ['retention_days' => $site['retention_days'], 'immutability_hours' => $site['immutability_hours']], 'signing_keys' => []]];
}

function disconnect(array &$s, string $siteId): array
{
    ownSite($s, $siteId);
    $s['sites'][$siteId]['status'] = 'disconnected';
    $s['sites'][$siteId]['disconnected_at'] = time();
    foreach ($s['keys'] as $hash => $sid) {
        if ($sid === $siteId) {
            unset($s['keys'][$hash]);
        }
    }
    return [200, ['ok' => true]];
}

function uploadInit(array &$s): array
{
    $site = authSite($s);
    $b = body();
    if (isset($b['force_multipart']) && !is_bool($b['force_multipart'])) {
        throw err('force_multipart must be a boolean');
    }
    $size = $b['file_size'] ?? null;
    if (!is_int($size) || $size < 0) {
        throw err('file_size must not be negative');
    }
    $force = ($b['force_multipart'] ?? false) === true;
    if ($force && $size === 0) {
        throw err('force_multipart requires a file_size greater than 0');
    }
    $name = trim((string) ($b['file_name'] ?? ''));
    if ($name === '') {
        throw err('file_name is required');
    }
    writableFolder($s, $site, (string) ($b['workspace_id'] ?? ''), (string) ($b['folder_id'] ?? ''));
    if (!$force && $size <= MULTIPART_THRESHOLD) {
        // the real door answers with a single-request session here; the plugin never asks for one
        throw err('The fake only implements multipart sessions (send force_multipart: true)', 501);
    }
    $sid = nextId($s, 'upl_');
    $total = max(1, (int) ceil($size / PART_SIZE));
    $s['sessions'][$sid] = [
        'id' => $sid, 'site_id' => $site['id'], 'folder_id' => (string) $b['folder_id'], 'name' => $name, 'size_bytes' => $size,
        'part_size' => PART_SIZE, 'total_parts' => $total, 'parts' => [], 'status' => 'pending', 'file_id' => null, 'created_at' => time(),
    ];
    return [201, ['ok' => true, 'session_id' => $sid, 'resumable' => ['part_size' => PART_SIZE, 'total_parts' => $total]]];
}

/** @return array<string,mixed> */
function sessionOf(array &$s, string $sid): array
{
    $site = authSite($s);
    $sess = $s['sessions'][$sid] ?? null;
    if ($sess === null || $sess['site_id'] !== $site['id'] || $sess['status'] === 'aborted') {
        throw err('Upload session not found', 404);
    }
    return $sess;
}

function uploadPart(array &$s, string $sid, string $n): array
{
    $sess = sessionOf($s, $sid);
    if (preg_match('/^[1-9]\d*$/', $n) !== 1) {
        throw err('Invalid part number');
    }
    $no = (int) $n;
    if ($sess['status'] === 'complete') {
        throw err('Upload session is already complete', 409);
    }
    $s['received'][] = ['session_id' => $sid, 'part' => $no, 'already' => isset($sess['parts'][$no]), 'at' => microtime(true)];
    if (isset($sess['parts'][$no])) {
        return [200, ['ok' => true, 'part_number' => $no, 'etag' => $sess['parts'][$no]['etag'], 'already_uploaded' => true]];
    }
    if ($no > $sess['total_parts']) {
        throw err('Part number ' . $no . ' exceeds total parts ' . $sess['total_parts']);
    }
    $dir = dataDir() . '/parts/' . $sid;
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    [$bytes, $sha] = receiveBody($dir . '/' . $no, $sess['part_size'], 'Part');
    if ($bytes === 0) {
        unlink($dir . '/' . $no);
        throw err('Empty part data');
    }
    $s['sessions'][$sid]['parts'][$no] = ['size' => $bytes, 'etag' => substr($sha, 0, 32)];
    $s['sessions'][$sid]['status'] = 'uploading';
    return [201, ['ok' => true, 'part_number' => $no, 'etag' => substr($sha, 0, 32), 'total_parts' => $sess['total_parts']]];
}

function uploadStatus(array &$s, string $sid): array
{
    $sess = sessionOf($s, $sid);
    $arm = $s['armed_forget'];
    if ($arm !== null && $sess['status'] !== 'complete' && isset($sess['parts'][$arm['part']])) {
        $s['armed_forget'] = null;
        dropPart($s, $sid, (int) $arm['part']);
        $sess = $s['sessions'][$sid];
    }
    $nums = array_map('intval', array_keys($sess['parts']));
    sort($nums);
    return [200, [
        'ok' => true, 'session_id' => $sid, 'status' => $sess['status'], 'size_bytes' => $sess['size_bytes'],
        'part_size' => $sess['part_size'], 'total_parts' => $sess['total_parts'],
        'bytes_uploaded' => array_sum(array_column($sess['parts'], 'size')), 'uploaded_parts' => $nums,
        'has_multipart' => true, 'file_id' => $sess['status'] === 'complete' ? $sess['file_id'] : null,
    ]];
}

function uploadComplete(array &$s, string $sid): array
{
    $sess = sessionOf($s, $sid);
    if ($sess['status'] === 'complete') {
        throw err('Upload session is already complete', 409);
    }
    $count = count($sess['parts']);
    if ($count === 0) {
        throw err('No parts have been uploaded');
    }
    if ($count < $sess['total_parts']) {
        throw err('Only ' . $count . ' of ' . $sess['total_parts'] . ' parts uploaded. Upload remaining parts before completing.');
    }
    $fid = nextId($s, 'file_');
    $dest = dataDir() . '/' . $fid;
    $out = fopen($dest, 'wb');
    $h = hash_init('sha256');
    $parts = array_keys($sess['parts']);
    sort($parts);
    foreach ($parts as $no) {
        $in = fopen(dataDir() . '/parts/' . $sid . '/' . $no, 'rb');
        while (!feof($in)) {
            $chunk = (string) fread($in, 1 << 20);
            hash_update($h, $chunk);
            fwrite($out, $chunk);
        }
        fclose($in);
    }
    fclose($out);
    rmTree(dataDir() . '/parts/' . $sid);
    $size = (int) filesize($dest);
    $file = storedFile($s, $fid, $sess['folder_id'], $sess['name'], $size, hash_final($h));
    $s['sessions'][$sid]['status'] = 'complete';
    $s['sessions'][$sid]['file_id'] = $fid;
    return [201, ['ok' => true, 'file' => $file + ['version' => 1, 'created_at' => time(), 'content_hash' => null]]];
}

function uploadAbort(array &$s, string $sid): array
{
    $site = authSite($s);
    $sess = $s['sessions'][$sid] ?? null;
    if ($sess === null || $sess['site_id'] !== $site['id'] || $sess['status'] === 'aborted') {
        return [200, ['ok' => true, 'already_gone' => true]];
    }
    if ($sess['status'] === 'complete') {
        throw err('Upload session is already complete', 409);
    }
    rmTree(dataDir() . '/parts/' . $sid);
    $s['sessions'][$sid]['parts'] = [];
    $s['sessions'][$sid]['status'] = 'aborted';
    return [200, ['ok' => true]];
}

function uploadFile(array &$s): array
{
    $site = authSite($s);
    $key = strtolower(trim((string) (header_('X-Dosya-Idempotency-Key') ?? '')));
    if ($key !== '' && preg_match(UUID_V4_RE, $key) !== 1) {
        throw err('X-Dosya-Idempotency-Key must be a UUID v4');
    }
    $idem = $key !== '' ? $site['id'] . ':' . $key : '';
    if ($idem !== '' && isset($s['idempotency'][$idem])) {
        return [200, $s['idempotency'][$idem]];
    }
    $name = trim((string) ($_GET['file_name'] ?? ''));
    if ($name === '') {
        throw err('file_name is required');
    }
    $folder = (string) ($_GET['folder_id'] ?? '');
    writableFolder($s, $site, (string) ($_GET['workspace_id'] ?? ''), $folder);
    $fid = nextId($s, 'file_');
    $dest = dataDir() . '/' . $fid;
    [$bytes, $sha] = receiveBody($dest, ONE_SHOT_MAX, 'File');
    $given = strtolower((string) (header_('X-Dosya-Sha256') ?? ''));
    if ($given !== '' && $given !== $sha) {
        unlink($dest);
        // lib/upload/body-pipeline.ts HASH_MISMATCH
        throw err('hash_mismatch', 400, ['expected' => $given, 'received' => $sha]);
    }
    $reply = ['ok' => true, 'file' => storedFile($s, $fid, $folder, $name, $bytes, $sha) + ['version' => 1, 'content_hash' => $sha]];
    if ($idem !== '') {
        $s['idempotency'][$idem] = $reply;
    }
    return [201, $reply];
}

/* ---------------- knobs ---------------- */

function dump(array &$s): array
{
    $out = $s;
    if (($_GET['hash'] ?? '') === '1') {
        foreach ($out['files'] as $id => $f) {
            $p = dataDir() . '/' . $id;
            $out['files'][$id]['disk_sha256'] = is_file($p) ? hash_file('sha256', $p) : null;
            $out['files'][$id]['disk_size'] = is_file($p) ? filesize($p) : null;
        }
    }
    return [200, $out];
}

function reset_(array &$s): array
{
    rmTree(dataDir(), true);
    $s = emptyState();
    return [200, ['ok' => true]];
}

function forgetPart(array &$s): array
{
    $b = body();
    $part = (int) ($b['part'] ?? 0);
    if ($part < 1) {
        throw err('part must be a positive integer');
    }
    if (($b['when'] ?? null) === 'status') {
        $s['armed_forget'] = ['part' => $part, 'when' => 'status'];
        return [200, ['ok' => true, 'armed' => $s['armed_forget']]];
    }
    $sid = (string) ($b['session_id'] ?? '');
    if ($sid === '') {
        foreach ($s['sessions'] as $id => $sess) {
            if ($sess['status'] !== 'complete' && $sess['status'] !== 'aborted' && isset($sess['parts'][$part])) {
                $sid = (string) $id;
            }
        }
    }
    if (!isset($s['sessions'][$sid]['parts'][$part])) {
        throw err('No open session holds part ' . $part, 404);
    }
    dropPart($s, $sid, $part);
    return [200, ['ok' => true, 'session_id' => $sid, 'part' => $part]];
}

/** The server "never got" the part: its bytes go and its receive-log entries move to the forgotten list. */
function dropPart(array &$s, string $sid, int $part): void
{
    unset($s['sessions'][$sid]['parts'][$part]);
    @unlink(dataDir() . '/parts/' . $sid . '/' . $part);
    foreach ($s['received'] as $i => $r) {
        if ($r['session_id'] === $sid && $r['part'] === $part) {
            $s['forgotten'][] = $r;
            unset($s['received'][$i]);
        }
    }
    $s['received'] = array_values($s['received']);
}

function failNext(array &$s): array
{
    $b = body();
    $route = (string) ($b['route'] ?? '');
    if (!in_array($route, ROUTE_NAMES, true)) {
        throw err('route must be one of ' . implode(', ', ROUTE_NAMES));
    }
    $s['fail_next'][$route] = (int) ($b['status'] ?? 503);
    return [200, ['ok' => true]];
}

function delay(array &$s): array
{
    $b = body();
    $route = (string) ($b['route'] ?? '');
    if (!in_array($route, ROUTE_NAMES, true)) {
        throw err('route must be one of ' . implode(', ', ROUTE_NAMES));
    }
    $ms = max(0, (int) ($b['ms'] ?? 0));
    if ($ms === 0) {
        unset($s['delay'][$route]);
    } else {
        $s['delay'][$route] = ['ms' => $ms, 'left' => isset($b['count']) ? max(1, (int) $b['count']) : null];
    }
    return [200, ['ok' => true]];
}

/** Deletes $dir and everything in it; with $keepRoot only its contents. */
function rmTree(string $dir, bool $keepRoot = false): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    if (!$keepRoot) {
        rmdir($dir);
    }
}

/* ---------------- router ---------------- */

/** [method, path regex, route name, handler]; route names are what fail-next and delay take. */
const ROUTES = [
    ['POST', '#^/api/wp/connect/exchange$#', 'exchange', 'exchange'],
    ['POST', '#^/api/wp/backups$#', 'register', 'register'],
    ['POST', '#^/api/wp/backups/([^/]+)/complete$#', 'complete', 'complete'],
    ['GET', '#^/api/wp/sites/([^/]+)/backups$#', 'list', 'listBackups'],
    ['POST', '#^/api/wp/sites/([^/]+)/heartbeat$#', 'heartbeat', 'heartbeat'],
    ['POST', '#^/api/wp/sites/([^/]+)/disconnect$#', 'disconnect', 'disconnect'],
    ['POST', '#^/api/upload/init$#', 'init', 'uploadInit'],
    ['PUT', '#^/api/upload/file$#', 'file', 'uploadFile'],
    ['PUT', '#^/api/upload/([^/]+)/part/([^/]+)$#', 'part', 'uploadPart'],
    ['GET', '#^/api/upload/([^/]+)/status$#', 'status', 'uploadStatus'],
    ['POST', '#^/api/upload/([^/]+)/complete$#', 'upload-complete', 'uploadComplete'],
    ['DELETE', '#^/api/upload/([^/]+)$#', 'abort', 'uploadAbort'],
    ['POST', '#^/fake/consent$#', 'fake', 'fakeConsent'],
    ['GET', '#^/fake/dump$#', 'fake', 'dump'],
    ['POST', '#^/fake/reset$#', 'fake', 'reset_'],
    ['POST', '#^/fake/forget-part$#', 'fake', 'forgetPart'],
    ['POST', '#^/fake/fail-next$#', 'fake', 'failNext'],
    ['POST', '#^/fake/delay$#', 'fake', 'delay'],
    ['GET', '#^/fake/health$#', 'fake', 'health'],
];
/** A copy of apps/api/src/lib/wp/allowlist.ts WP_SITE_ROUTES (+ STATIC_SIBLINGS): the only /api paths a wp_site key may call. */
const WP_SITE_ALLOWLIST = [
    ['POST', '#^/api/upload/init$#'],
    ['PUT', '#^/api/upload/upl_[A-Za-z0-9_-]+$#'],
    ['PUT', '#^/api/upload/upl_[A-Za-z0-9_-]+/part/[1-9]\\d*$#'],
    ['GET', '#^/api/upload/upl_[A-Za-z0-9_-]+/status$#'],
    ['POST', '#^/api/upload/upl_[A-Za-z0-9_-]+/complete$#'],
    ['DELETE', '#^/api/upload/upl_[A-Za-z0-9_-]+$#'],
    ['PUT', '#^/api/upload/file$#'],
    ['GET', '#^/api/files/[A-Za-z0-9_-]+$#'],
    ['GET', '#^/api/files/[A-Za-z0-9_-]+/download$#'],
    ['GET', '#^/api/files$#'],
    ['GET', '#^/api/folders/[A-Za-z0-9_-]+$#'],
    ['POST', '#^/api/wp/sites/[A-Za-z0-9_-]+/heartbeat$#'],
    ['GET', '#^/api/wp/sites/[A-Za-z0-9_-]+/backups$#'],
    ['POST', '#^/api/wp/sites/[A-Za-z0-9_-]+/disconnect$#'],
    ['POST', '#^/api/wp/backups$#'],
    ['POST', '#^/api/wp/backups/[A-Za-z0-9_-]+/complete$#'],
];
const STATIC_SIBLINGS = ['batch-delete', 'download-archive', 'download-zip', 'share-bundle', 'batch', 'children', 'search', 'tree'];

function wpSiteRouteAllowed(string $method, string $path): bool
{
    if (preg_match('#^/api/(?:files|folders)/([^/]+)$#', $path, $m) === 1 && in_array($m[1], STATIC_SIBLINGS, true)) {
        return false;
    }
    foreach (WP_SITE_ALLOWLIST as [$mm, $re]) {
        if ($mm === $method && preg_match($re, $path) === 1) {
            return true;
        }
    }
    return false;
}

const ROUTE_NAMES = ['exchange', 'register', 'complete', 'list', 'heartbeat', 'disconnect', 'init', 'file', 'part', 'status', 'upload-complete', 'abort'];

function health(array &$s): array
{
    return [200, ['ok' => true]];
}

function send(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body, FLAGS);
}

function main(): void
{
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    // the middleware's wp_site confinement: anything else under /api is refused before any route runs
    if (str_starts_with($path, '/api/') && !($method === 'POST' && $path === '/api/wp/connect/exchange') && !wpSiteRouteAllowed($method, $path)) {
        send(403, ['ok' => false, 'error' => 'wp_site_scope', 'message' => 'This key belongs to a WordPress site and can only back up into its own folder.']);
        return;
    }
    $route = null;
    $args = [];
    foreach (ROUTES as $r) {
        if ($r[0] === $method && preg_match($r[1], $path, $m) === 1) {
            $route = $r;
            $args = array_map('rawurldecode', array_slice($m, 1));
            break;
        }
    }
    if ($route === null) {
        send(str_starts_with($path, '/api/') ? 501 : 404, ['ok' => false, 'error' => str_starts_with($path, '/api/') ? 'Not implemented by the fake' : 'Not found']);
        return;
    }

    // one request at a time touches the state (php -S is single-worker, but be safe)
    $lock = fopen(dataDir() . '/state.lock', 'c');
    flock($lock, LOCK_EX);
    $file = dataDir() . '/state.json';
    $s = is_file($file) ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : emptyState();
    $name = $route[2];
    try {
        if (isset($s['delay'][$name])) {
            $d = $s['delay'][$name];
            if ($d['left'] !== null && --$s['delay'][$name]['left'] <= 0) {
                unset($s['delay'][$name]);
            }
            set_time_limit(0);
            usleep($d['ms'] * 1000);
        }
        if (isset($s['fail_next'][$name])) {
            $status = (int) $s['fail_next'][$name];
            unset($s['fail_next'][$name]);
            throw err('Service temporarily unavailable (fake fail-next)', $status);
        }
        [$status, $body] = ($route[3])($s, ...$args);
    } catch (Reply $e) {
        [$status, $body] = [$e->status, $e->body];
    } catch (Throwable $e) {
        [$status, $body] = [500, ['error' => 'fake server error: ' . $e->getMessage()]];
    }
    if ($name !== 'fake') {
        // method, path and status only: never headers or bodies (the site key travels in a header)
        $s['log'][] = ['method' => $method, 'path' => $path, 'status' => $status];
        $s['log'] = array_slice($s['log'], -1000);
    }
    if ($route[3] !== 'dump' && $route[3] !== 'health') {
        file_put_contents($file . '.tmp', json_encode($s, FLAGS));
        rename($file . '.tmp', $file);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    send($status, $body);
}

main();
