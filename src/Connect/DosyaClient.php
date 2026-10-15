<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text and are escaped where they are displayed (JSON, WP-CLI).
namespace Dosya\Backup\Connect;

final class DosyaClient
{
    private const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    /** The header and format PUT /api/upload/file accepts (apps/api src/lib/upload/idempotency.ts). */
    public const IDEMPOTENCY_HEADER = 'X-Dosya-Idempotency-Key';
    public const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private HttpClient $http;
    private string $baseUrl;
    private ?string $siteKey;
    private string $userAgent;

    public function __construct(HttpClient $http, string $baseUrl, ?string $siteKey, string $userAgent = 'dosya-backup/unknown (WordPress)')
    {
        $this->http = $http;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->siteKey = $siteKey !== null && $siteKey !== '' ? $siteKey : null;
        $this->userAgent = $userAgent;
    }

    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'siteKey' => $this->siteKey === null ? 'null' : 'set', 'userAgent' => $this->userAgent];
    }

    /** @return array<string,mixed> */
    public function exchange(string $code, string $verifier): array
    {
        return $this->json('POST', '/api/wp/connect/exchange', ['code' => $code, 'code_verifier' => $verifier], false);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function heartbeat(string $siteId, array $body): array
    {
        return $this->json('POST', '/api/wp/sites/' . rawurlencode($siteId) . '/heartbeat', $body);
    }

    /** @return array<string,mixed> */
    public function listBackups(string $siteId): array
    {
        return $this->json('GET', '/api/wp/sites/' . rawurlencode($siteId) . '/backups');
    }

    /** @return array<string,mixed> */
    public function disconnect(string $siteId): array
    {
        return $this->json('POST', '/api/wp/sites/' . rawurlencode($siteId) . '/disconnect', []);
    }

    /** @return array<string,mixed> */
    public function registerBackup(string $backupId, string $kind, int $startedAt): array
    {
        return $this->json('POST', '/api/wp/backups', ['backup_id' => $backupId, 'kind' => $kind, 'started_at' => $startedAt]);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function completeBackup(string $backupId, array $body): array
    {
        return $this->json('POST', '/api/wp/backups/' . rawurlencode($backupId) . '/complete', $body);
    }

    /** @return array<string,mixed> */
    public function uploadInit(string $workspaceId, string $folderId, string $name, int $size): array
    {
        return $this->json('POST', '/api/upload/init', [
            'workspace_id' => $workspaceId,
            'folder_id' => $folderId,
            'file_name' => $name,
            'file_size' => $size,
            'force_multipart' => true,
        ]);
    }

    /** Streams bytes [$offset, $offset+$length) of $path as part $n (1-based, at most 10 MiB). @return array<string,mixed> */
    public function uploadPart(string $sessionId, int $n, string $path, int $offset, int $length): array
    {
        return $this->json(
            'PUT',
            '/api/upload/' . rawurlencode($sessionId) . '/part/' . $n,
            null,
            true,
            ['Content-Type' => 'application/octet-stream'],
            ['path' => $path, 'offset' => $offset, 'length' => $length],
            300
        );
    }

    /** @return array<string,mixed> */
    public function uploadStatus(string $sessionId): array
    {
        return $this->json('GET', '/api/upload/' . rawurlencode($sessionId) . '/status');
    }

    /** @return array<string,mixed> */
    public function uploadComplete(string $sessionId): array
    {
        return $this->json('POST', '/api/upload/' . rawurlencode($sessionId) . '/complete', null);
    }

    /** Idempotent: a session that is already gone (404) is not an error. */
    public function uploadAbort(string $sessionId): void
    {
        try {
            $this->json('DELETE', '/api/upload/' . rawurlencode($sessionId));
        } catch (HttpException $e) {
            if ($e->status !== 404) {
                throw $e;
            }
        }
    }

    /**
     * One request for a small file (the server caps the body at 50 MB; the HTTP layer at one part).
     * With $idempotencyKey (a UUID v4) a repeat of a request whose answer was lost replays the stored
     * answer (200, same file id) for 24 hours instead of storing a second version.
     * @return array<string,mixed>
     */
    public function uploadFileOneShot(string $workspaceId, string $folderId, string $name, string $path, string $sha256Hex, ?string $idempotencyKey = null): array
    {
        $size = @filesize($path);
        if ($size === false) {
            throw new \InvalidArgumentException('cannot read upload file');
        }
        $extra = [];
        if ($idempotencyKey !== null) {
            if (preg_match(self::UUID_V4, $idempotencyKey) !== 1) {
                throw new \InvalidArgumentException('idempotency key must be a lowercase UUID v4');
            }
            $extra[self::IDEMPOTENCY_HEADER] = $idempotencyKey;
        }
        if ($size === 0) {
            $extra['Content-Length'] = '0';
        }
        $query = http_build_query(['workspace_id' => $workspaceId, 'file_name' => $name, 'folder_id' => $folderId], '', '&', PHP_QUERY_RFC3986);
        return $this->json(
            'PUT',
            '/api/upload/file?' . $query,
            null,
            true,
            array_merge(['Content-Type' => 'application/octet-stream', 'X-Dosya-Sha256' => strtolower($sha256Hex)], $extra),
            ['path' => $path, 'offset' => 0, 'length' => (int) $size],
            300
        );
    }

    /**
     * @param array<string,mixed>|null $body null sends no body
     * @param array<string,string> $extraHeaders
     * @param array{path:string,offset:int,length:int}|null $bodyFile
     * @return array<string,mixed>
     */
    private function json(string $method, string $path, ?array $body = null, bool $auth = true, array $extraHeaders = [], ?array $bodyFile = null, int $timeout = 60): array
    {
        if ($auth && $this->siteKey === null) {
            throw new \LogicException('dosya client has no site key');
        }
        $headers = ['Accept' => 'application/json', 'User-Agent' => $this->userAgent];
        if ($auth && $this->siteKey !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->siteKey;
        }
        $req = ['method' => $method, 'url' => $this->baseUrl . $path, 'timeout' => $timeout];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $req['body'] = $body === [] ? '{}' : json_encode($body, self::JSON);
        }
        if ($bodyFile !== null) {
            $req['bodyFile'] = $bodyFile;
        }
        $req['headers'] = array_merge($headers, $extraHeaders);

        $res = $this->http->send($req);
        $status = (int) $res['status'];
        $raw = (string) $res['body'];
        $decoded = null;
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $decoded = null;
            }
        }
        if ($status < 200 || $status >= 300) {
            $code = 'http_' . $status;
            $message = '';
            if (is_array($decoded)) {
                $hasReason = isset($decoded['reason']) && is_string($decoded['reason']) && $decoded['reason'] !== '';
                if ($hasReason) {
                    $code = $decoded['reason'];
                }
                if (isset($decoded['error']) && is_string($decoded['error']) && $decoded['error'] !== '') {
                    // a sentence is the server's explanation (kept next to a machine reason); a bare token is the code
                    if (preg_match('/\s/', $decoded['error']) === 1) {
                        $message = $decoded['error'];
                    } elseif (!$hasReason) {
                        $code = $decoded['error'];
                    }
                }
                if ($message === '' && isset($decoded['message']) && is_string($decoded['message'])) {
                    $message = $decoded['message'];
                }
            }
            $retryAfter = null;
            $h = $res['headers']['retry-after'] ?? null;
            if ($h !== null && ctype_digit((string) $h)) {
                $retryAfter = (int) $h;
            }
            throw new HttpException($status, $code, $message !== '' ? $message : $method . ' ' . strtok($path, '?') . ' failed: ' . $status . ' ' . $code, $retryAfter);
        }
        if (!is_array($decoded)) {
            if ($raw === '') {
                return [];
            }
            throw new HttpException($status, 'bad_response', $method . ' ' . strtok($path, '?') . ' returned a non-JSON body');
        }
        return $decoded;
    }
}
