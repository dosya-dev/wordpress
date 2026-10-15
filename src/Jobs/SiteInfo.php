<?php
namespace Dosya\Backup\Jobs;

interface SiteInfo
{
    public function homeUrl(): string;
    public function siteUrl(): string;
    public function absPath(): string;
    public function contentDir(): string;
    public function tablePrefix(): string;
    public function isMultisite(): bool;
    public function wpVersion(): string;
}
