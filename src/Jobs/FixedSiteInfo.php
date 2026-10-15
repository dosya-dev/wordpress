<?php
namespace Dosya\Backup\Jobs;

final class FixedSiteInfo implements SiteInfo
{
    private array $v;

    public function __construct(array $v)
    {
        $this->v = $v + ['multisite' => false, 'wp' => 'unknown', 'siteurl' => $v['home'] ?? ''];
    }

    public function homeUrl(): string { return (string) $this->v['home']; }
    public function siteUrl(): string { return (string) $this->v['siteurl']; }
    public function absPath(): string { return rtrim((string) $this->v['abspath'], '/') . '/'; }
    public function contentDir(): string { return rtrim((string) $this->v['content_dir'], '/'); }
    public function tablePrefix(): string { return (string) $this->v['prefix']; }
    public function isMultisite(): bool { return (bool) $this->v['multisite']; }
    public function wpVersion(): string { return (string) $this->v['wp']; }
}
