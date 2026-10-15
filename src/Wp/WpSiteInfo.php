<?php
namespace Dosya\Backup\Wp;

use Dosya\Backup\Jobs\SiteInfo;

final class WpSiteInfo implements SiteInfo
{
    public function homeUrl(): string { return (string) get_option('home'); }
    public function siteUrl(): string { return (string) get_option('siteurl'); }
    public function absPath(): string { return ABSPATH; }
    public function contentDir(): string { return WP_CONTENT_DIR; }
    public function tablePrefix(): string { return (string) $GLOBALS['wpdb']->base_prefix; }
    public function isMultisite(): bool { return is_multisite(); }
    public function wpVersion(): string { return (string) get_bloginfo('version'); }
}
