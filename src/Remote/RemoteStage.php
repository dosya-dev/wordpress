<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI).
namespace Dosya\Backup\Remote;

use Dosya\Backup\Connect\DosyaClient;
use Dosya\Backup\Connect\HttpException;
use Dosya\Backup\Support\Budget;

/**
 * Sends one sealed local backup to dosya: register -> volumes -> manifests ->
 * complete -> done. Each step() does one unit of work (one request, or one
 * RemoteUploader step) and returns, so the job layer writes the cursor after
 * every sub-step. Every server call is idempotent (register and complete on
 * the server, uploads through RemoteUploader's resume), so re-running an older
 * cursor after a crash is safe.
 *
 * A failure is not retried here: the open upload session is aborted (best
 * effort) and the exception propagates, so the job ends failed and the local
 * backup stays.
 */
final class RemoteStage
{
    public const MANIFEST = 'manifest.json.gz';
    public const PUBLIC_MANIFEST = 'manifest.public.json';
    /** The most refs POST /api/wp/backups/{id}/complete accepts. */
    public const MAX_REFS = 2000;

    private DosyaClient $api;
    private RemoteUploader $uploader;
    private RemoteStore $store;
    private string $siteId;

    public function __construct(DosyaClient $api, RemoteUploader $uploader, RemoteStore $store, string $siteId)
    {
        $this->api = $api;
        $this->uploader = $uploader;
        $this->store = $store;
        $this->siteId = $siteId;
    }

    public function siteId(): string
    {
        return $this->siteId;
    }

    public function store(): RemoteStore
    {
        return $this->store;
    }

    /**
     * The site's backups as dosya lists them, newest first. Throws on any failure (the caller decides).
     * @return list<array<string,mixed>>
     */
    public function listBackups(): array
    {
        $res = $this->api->listBackups($this->siteId);
        $list = $res['backups'] ?? null;
        if (!is_array($list)) {
            throw new \RuntimeException('dosya returned no backup list.');
        }
        return array_values(array_filter($list, 'is_array'));
    }

    /**
     * @param list<array{v:int,size:int,sha256:string,path:string}> $volumes sealed volumes with the sealer's digests
     * @param array<string,string> $manifests manifest.json.gz and manifest.public.json => local path
     * @param list<array{b:string,v:int}> $refs volumes of earlier backups this one reads (Manifest::publicView refs)
     * @param int $refBytes the summed size of those referenced volumes (bytes_total = this backup's volumes + these)
     * @return array<string,mixed>
     */
    public static function start(string $backupId, string $kind, int $startedAt, array $volumes, array $manifests, array $refs, int $refBytes = 0): array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $backupId) !== 1) {
            throw new \InvalidArgumentException('Invalid backup id');
        }
        if (!in_array($kind, ['full', 'incremental'], true)) {
            throw new \InvalidArgumentException('kind must be full or incremental');
        }
        $vols = [];
        foreach ($volumes as $v) {
            $vols[] = ['v' => (int) $v['v'], 'size' => (int) $v['size'], 'sha256' => strtolower((string) $v['sha256']), 'path' => (string) $v['path'], 'file_id' => null];
        }
        $paths = [];
        foreach ([self::MANIFEST, self::PUBLIC_MANIFEST] as $name) {
            if (!isset($manifests[$name])) {
                throw new \InvalidArgumentException('missing ' . $name);
            }
            $paths[$name] = (string) $manifests[$name];
        }
        $serverRefs = [];
        foreach ($refs as $r) {
            $serverRefs[] = ['backup_id' => (string) $r['b'], 'volume_no' => (int) $r['v']];
        }
        return [
            'stage' => 'register', 'backup_id' => $backupId, 'kind' => $kind, 'started_at' => $startedAt,
            'folder' => null, 'volumes_folder' => null,
            'vols' => $vols, 'vi' => 0, 'up' => null,
            'manifests' => [self::MANIFEST => null, self::PUBLIC_MANIFEST => null], 'manifest_paths' => $paths,
            'refs' => $serverRefs, 'ref_bytes' => max(0, $refBytes), 'result' => null, 'done' => false,
        ];
    }

    /** For the job's progress: which sub-step runs next and the volume it is on (1-based). @param array<string,mixed> $c @return array{step:string,volume:int,volumes:int} */
    public static function progress(array $c): array
    {
        $n = count($c['vols']);
        $step = $c['stage'] === 'done' ? 'complete' : (string) $c['stage'];
        return ['step' => $step, 'volume' => max(1, min((int) $c['vi'] + 1, $n)), 'volumes' => $n];
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    public function step(array $c, Budget $b): array
    {
        try {
            switch ($c['stage']) {
                case 'register': return $this->register($c);
                case 'volumes': return $this->volumes($c, $b);
                case 'manifests': return $this->manifests($c, $b);
                case 'complete': return $this->complete($c);
                case 'done': return $c;
            }
        } catch (HttpException $e) {
            $this->abortOpenSession($c);
            // a 409 with Retry-After is the server's idempotency "still in progress" answer: try again later
            if ($e->retryable || ($e->status === 409 && $e->retryAfter !== null)) {
                $again = new HttpException($e->status, $e->errorCode, $e->status > 0
                    ? sprintf('dosya upload failed (%d). The backup is kept locally; run it again later.', $e->status)
                    : 'dosya could not be reached. The backup is kept locally; run it again later.', $e->retryAfter);
                $again->retryable = true;
                throw $again;
            }
            throw $e;
        } catch (\RuntimeException $e) {
            $this->abortOpenSession($c);
            throw $e;
        }
        throw new \LogicException('unknown remote stage ' . $c['stage']);
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function register(array $c): array
    {
        $res = $this->api->registerBackup($c['backup_id'], $c['kind'], (int) $c['started_at']);
        $folder = $res['folder_id'] ?? null;
        $vfolder = $res['volumes_folder_id'] ?? null;
        if (!is_string($folder) || $folder === '' || !is_string($vfolder) || $vfolder === '' || $folder === $vfolder) {
            throw new \RuntimeException('dosya registered the backup but returned no folders for it.');
        }
        $c['folder'] = $folder;
        $c['volumes_folder'] = $vfolder;
        $c['stage'] = 'volumes';
        return $c;
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function volumes(array $c, Budget $b): array
    {
        $i = (int) $c['vi'];
        if ($i >= count($c['vols'])) {
            $c['stage'] = 'manifests';
            return $c;
        }
        $v = $c['vols'][$i];
        // the sealer's digest is what dosya records; the verifier detects a volume altered after sealing
        $up = $c['up'] ?? $this->uploader->start($v['path'], $c['volumes_folder'], basename($v['path']), $v['sha256'],
            self::idempotencyKey($c['backup_id'], basename($v['path'])));
        $c['up'] = $this->uploader->step($up, $b);
        if (!empty($c['up']['done'])) {
            $c['vols'][$i]['file_id'] = $c['up']['file_id'];
            $c['vi'] = $i + 1;
            $c['up'] = null;
            if ($c['vi'] >= count($c['vols'])) {
                $c['stage'] = 'manifests';
            }
        }
        return $c;
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function manifests(array $c, Budget $b): array
    {
        $name = null;
        foreach ([self::MANIFEST, self::PUBLIC_MANIFEST] as $n) {
            if ($c['manifests'][$n] === null) {
                $name = $n;
                break;
            }
        }
        if ($name !== null) {
            $up = $c['up'] ?? $this->uploader->start($c['manifest_paths'][$name], $c['folder'], $name, null, self::idempotencyKey($c['backup_id'], $name));
            $c['up'] = $this->uploader->step($up, $b);
            if (empty($c['up']['done'])) {
                return $c;
            }
            $c['manifests'][$name] = $c['up']['file_id'];
            $c['up'] = null;
        }
        if ($c['manifests'][self::MANIFEST] !== null && $c['manifests'][self::PUBLIC_MANIFEST] !== null) {
            $c['stage'] = 'complete';
        }
        return $c;
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function complete(array $c): array
    {
        $volumes = [];
        $bytesNew = 0;
        foreach ($c['vols'] as $v) {
            $volumes[] = ['volume_no' => (int) $v['v'], 'file_id' => (string) $v['file_id'], 'size' => (int) $v['size'], 'sha256' => (string) $v['sha256']];
            $bytesNew += (int) $v['size'];
        }
        // an incremental backup also counts the earlier volumes it reads
        $bytesTotal = $bytesNew + (int) ($c['ref_bytes'] ?? 0);
        $res = $this->api->completeBackup($c['backup_id'], [
            'volumes' => $volumes,
            'refs' => $c['refs'],
            'manifest_file_id' => $c['manifests'][self::MANIFEST],
            'public_manifest_file_id' => $c['manifests'][self::PUBLIC_MANIFEST],
            'bytes_new' => $bytesNew,
            'bytes_total' => $bytesTotal,
        ]);
        // a repeat after the server verified the backup answers verified: that is done too
        if (!in_array($res['state'] ?? null, ['uploaded', 'verified'], true)) {
            throw new \RuntimeException('dosya did not confirm the backup upload.');
        }
        $c['result'] = [
            'locked_until' => isset($res['locked_until']) && is_int($res['locked_until']) ? $res['locked_until'] : null,
            'expires_at' => isset($res['expires_at']) && is_int($res['expires_at']) ? $res['expires_at'] : null,
            'bytes_new' => $bytesNew,
            'bytes_total' => $bytesTotal,
        ];
        // verified only when dosya says so; the admin page shows "Verified restorable" from this flag alone
        $this->store->recordUploaded($c['backup_id'], (int) $c['started_at'], $c['manifest_paths'][self::MANIFEST], $c['result'] + [
            'folder_id' => $c['folder'], 'kind' => $c['kind'], 'verified' => ($res['state'] ?? '') === 'verified',
        ]);
        $c['stage'] = 'done';
        $c['done'] = true;
        return $c;
    }

    /**
     * Stable per (backup, file name), shaped as the UUID v4 the one-shot door requires, so a step
     * replayed after a crash gets the first upload's file back instead of a second version.
     */
    public static function idempotencyKey(string $backupId, string $name): string
    {
        $h = hash('sha256', $backupId . '/' . $name);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-'
            . dechex(8 + (hexdec($h[16]) & 3)) . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    }

    /** The id of an upload session the stage opened and has not finished, or null. @param array<string,mixed> $c */
    public static function openSession(array $c): ?string
    {
        $sid = $c['up']['session']['id'] ?? null;
        return is_string($sid) && $sid !== '' && empty($c['up']['file_id']) ? $sid : null;
    }

    /** Best effort: aborts the stage's open upload session; errors are swallowed (the server reaps sessions). @param array<string,mixed> $c */
    public static function abortOpen(DosyaClient $api, array $c): void
    {
        $sid = self::openSession($c);
        if ($sid === null) {
            return;
        }
        try {
            $api->uploadAbort($sid);
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /** @param array<string,mixed> $c */
    private function abortOpenSession(array $c): void
    {
        self::abortOpen($this->api, $c);
    }
}
