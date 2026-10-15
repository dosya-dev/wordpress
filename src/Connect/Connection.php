<?php
namespace Dosya\Backup\Connect;

final class Connection
{
    public const DEFAULT_BASE_URL = 'https://api.dosya.dev';

    public string $baseUrl = self::DEFAULT_BASE_URL;
    public string $siteKey = '';
    public string $siteId = '';
    public string $workspaceId = '';
    public string $rootFolderId = '';
    /** @var list<mixed> */
    public array $signingKeys = [];
    /** @var array<string,mixed> */
    public array $caps = [];
    public ?int $retentionDays = null;
    public ?int $immutabilityHours = null;
    /** False when the key could not be encrypted at rest (no ext-sodium). */
    public bool $encrypted = true;

    /** @param array<string,mixed> $json the 201 body of POST /api/wp/connect/exchange */
    public static function fromExchange(array $json, string $baseUrl = self::DEFAULT_BASE_URL): self
    {
        $c = new self();
        $c->baseUrl = $baseUrl;
        $c->siteKey = (string) ($json['site_key'] ?? '');
        $c->siteId = (string) ($json['site_id'] ?? '');
        $c->workspaceId = (string) ($json['workspace_id'] ?? '');
        $c->rootFolderId = (string) ($json['root_folder_id'] ?? '');
        $c->signingKeys = isset($json['signing_keys']) && is_array($json['signing_keys']) ? array_values($json['signing_keys']) : [];
        $c->caps = isset($json['caps']) && is_array($json['caps']) ? $json['caps'] : [];
        $c->retentionDays = isset($json['retention_days']) ? (int) $json['retention_days'] : null;
        $c->immutabilityHours = isset($json['immutability_hours']) ? (int) $json['immutability_hours'] : null;
        return $c;
    }

    /** @return array<string,mixed> includes the plain site key: never log or echo it */
    public function toArray(): array
    {
        return [
            'base_url' => $this->baseUrl,
            'site_key' => $this->siteKey,
            'site_id' => $this->siteId,
            'workspace_id' => $this->workspaceId,
            'root_folder_id' => $this->rootFolderId,
            'signing_keys' => $this->signingKeys,
            'caps' => $this->caps,
            'retention_days' => $this->retentionDays,
            'immutability_hours' => $this->immutabilityHours,
        ];
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        $c = self::fromExchange($a, (string) ($a['base_url'] ?? self::DEFAULT_BASE_URL));
        if ($c->baseUrl === '') {
            $c->baseUrl = self::DEFAULT_BASE_URL;
        }
        return $c;
    }

    public function __debugInfo(): array
    {
        $a = $this->toArray();
        $a['site_key'] = $this->siteKey === '' ? '' : '***';
        return $a;
    }
}
