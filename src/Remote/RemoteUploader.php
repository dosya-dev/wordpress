<?php
namespace Dosya\Backup\Remote;

use Dosya\Backup\Connect\DosyaClient;
use Dosya\Backup\Connect\HttpException;
use Dosya\Backup\Support\Budget;

/**
 * Pushes one sealed local file to dosya, resumably. Files up to $oneShotMax go
 * in a single request; larger ones through an upload session (always
 * multipart, parts streamed from disk). On every resume the server's part list
 * wins over the local counter, so a crash between a 200 and the cursor write
 * never re-sends a part and a part the server lost is sent again.
 *
 * The cursor is plain scalars/arrays so the caller can JSON-encode it between
 * steps. A retryable HttpException propagates and the cursor the caller holds
 * is unchanged; the job layer decides when to retry.
 */
final class RemoteUploader
{
    private DosyaClient $api;
    private string $workspaceId;
    private int $oneShotMax;

    public function __construct(DosyaClient $api, string $workspaceId, int $oneShotMax = 10485760)
    {
        $this->api = $api;
        $this->workspaceId = $workspaceId;
        $this->oneShotMax = $oneShotMax;
    }

    /** @return array<string,mixed> */
    /** $idempotencyKey (UUID v4) makes a replayed one-shot upload return the first upload's file instead of a second version. */
    public function start(string $localPath, string $folderId, string $name, ?string $sha256 = null, ?string $idempotencyKey = null): array
    {
        $size = @filesize($localPath);
        if ($size === false) {
            throw new \InvalidArgumentException('cannot read upload file');
        }
        return [
            'path' => $localPath,
            'folder' => $folderId,
            'name' => $name,
            'size' => (int) $size,
            'mode' => $size <= $this->oneShotMax ? 'oneshot' : 'session',
            'session' => null,
            'next_part' => 1,
            'hash_state' => null,
            'done' => false,
            'file_id' => null,
            'sha256' => null,
            'sha256_known' => $sha256 !== null && $sha256 !== '' ? strtolower($sha256) : null,
            'idem' => $idempotencyKey,
        ];
    }

    /**
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    public function step(array $c, Budget $b): array
    {
        if (!empty($c['done'])) {
            return $c;
        }
        if ($c['mode'] === 'oneshot') {
            return $this->oneShot($c);
        }

        // A step that opens a session returns at once, so the caller persists the session id before
        // any part can fail; a retry then resumes it instead of opening (and leaking) another.
        if ($c['session'] === null) {
            return $this->open($c);
        }

        $justCompleted = false;
        if ($c['file_id'] === null) {
            $have = [];
            $c = $this->syncWithServer($c, $have);
            $total = (int) $c['session']['total_parts'];
            while ($c['file_id'] === null) {
                if ((int) $c['next_part'] > $total) {
                    $c = $this->complete($c);
                    $justCompleted = true;
                    break;
                }
                $c = $this->sendPart($c);
                $c['next_part'] = $this->nextMissing($have, (int) $c['next_part'], $total);
                if ($b->exhausted()) {
                    return $c;
                }
            }
        }

        if ($c['file_id'] !== null && $c['sha256'] === null) {
            if (!empty($c['sha256_known'])) {
                $c['sha256'] = $c['sha256_known'];
            } else {
                // hash_file cannot be interrupted: after a completion that used up the budget, leave
                // it to the next step, which starts it on a fresh budget.
                if ($justCompleted && $b->exhausted()) {
                    return $c;
                }
                $c['sha256'] = $this->hash($c['path']);
            }
        }
        $c['done'] = $c['file_id'] !== null && $c['sha256'] !== null;
        return $c;
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function oneShot(array $c): array
    {
        $sha = !empty($c['sha256_known']) ? (string) $c['sha256_known'] : $this->hash($c['path']);
        $res = $this->api->uploadFileOneShot($this->workspaceId, $c['folder'], $c['name'], $c['path'], $sha, $c['idem'] ?? null);
        $file = isset($res['file']) && is_array($res['file']) ? $res['file'] : [];
        if (!isset($file['id']) || !is_string($file['id']) || $file['id'] === '') {
            throw new \RuntimeException('dosya accepted the upload but returned no file id');
        }
        $this->assertSize($c, $file);
        $c['file_id'] = $file['id'];
        $c['sha256'] = $sha;
        $c['done'] = true;
        return $c;
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function open(array $c): array
    {
        $res = $this->api->uploadInit($this->workspaceId, $c['folder'], $c['name'], (int) $c['size']);
        $info = isset($res['resumable']) && is_array($res['resumable']) ? $res['resumable'] : $res;
        $id = $res['session_id'] ?? null;
        if (!is_string($id) || $id === '' || empty($info['part_size']) || empty($info['total_parts'])) {
            throw new \RuntimeException('dosya did not open a multipart upload session');
        }
        $c['session'] = ['id' => $id, 'part_size' => (int) $info['part_size'], 'total_parts' => (int) $info['total_parts']];
        $c['next_part'] = 1;
        return $c;
    }

    /**
     * The server's list of received parts decides what is sent next.
     * @param array<string,mixed> $c @param array<int,bool> $have filled with the parts the server holds
     * @return array<string,mixed>
     */
    private function syncWithServer(array $c, array &$have): array
    {
        $st = $this->api->uploadStatus($c['session']['id']);
        if (($st['status'] ?? null) === 'complete') {
            if (empty($st['file_id']) || !is_string($st['file_id'])) {
                throw new \RuntimeException('dosya reports the upload complete without a file id');
            }
            $this->assertStatusSize($c, $st);
            $c['file_id'] = $st['file_id'];
            return $c;
        }
        foreach (isset($st['uploaded_parts']) && is_array($st['uploaded_parts']) ? $st['uploaded_parts'] : [] as $p) {
            $have[(int) $p] = true;
        }
        $c['next_part'] = $this->nextMissing($have, 1, (int) $c['session']['total_parts']);
        return $c;
    }

    /** @param array<int,bool> $have */
    private function nextMissing(array $have, int $from, int $total): int
    {
        $n = $from;
        while ($n <= $total && isset($have[$n])) {
            $n++;
        }
        return $n;
    }

    /** @param array<string,mixed> $c @param array<string,mixed> $st */
    private function assertStatusSize(array $c, array $st): void
    {
        $got = $st['bytes_uploaded'] ?? ($st['size_bytes'] ?? null);
        if ($got !== null && (int) $got !== (int) $c['size']) {
            throw new \RuntimeException('dosya stored ' . (int) $got . ' bytes for a ' . (int) $c['size'] . ' byte file; the upload is corrupt');
        }
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function sendPart(array $c): array
    {
        $n = (int) $c['next_part'];
        $partSize = (int) $c['session']['part_size'];
        $offset = ($n - 1) * $partSize;
        $length = min($partSize, (int) $c['size'] - $offset);
        $this->api->uploadPart($c['session']['id'], $n, $c['path'], $offset, $length);
        $c['next_part'] = $n + 1;
        return $c;
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function complete(array $c): array
    {
        try {
            $res = $this->api->uploadComplete($c['session']['id']);
        } catch (HttpException $e) {
            if ($e->status !== 409) {
                throw $e;
            }
            // Already complete (a lost response on an earlier attempt): the status carries the file.
            $st = $this->api->uploadStatus($c['session']['id']);
            if (($st['status'] ?? null) !== 'complete' || empty($st['file_id']) || !is_string($st['file_id'])) {
                throw $e;
            }
            $this->assertStatusSize($c, $st);
            $c['file_id'] = $st['file_id'];
            return $c;
        }
        $file = isset($res['file']) && is_array($res['file']) ? $res['file'] : [];
        if (!isset($file['id']) || !is_string($file['id']) || $file['id'] === '') {
            throw new \RuntimeException('dosya completed the upload but returned no file id');
        }
        $this->assertSize($c, $file);
        $c['file_id'] = $file['id'];
        return $c;
    }

    /** @param array<string,mixed> $c @param array<string,mixed> $file */
    private function assertSize(array $c, array $file): void
    {
        if (isset($file['size_bytes']) && (int) $file['size_bytes'] !== (int) $c['size']) {
            throw new \RuntimeException('dosya stored ' . (int) $file['size_bytes'] . ' bytes for a ' . (int) $c['size'] . ' byte file; the upload is corrupt');
        }
    }

    private function hash(string $path): string
    {
        $h = @hash_file('sha256', $path);
        if ($h === false) {
            throw new \RuntimeException('cannot hash upload file');
        }
        return $h;
    }
}
