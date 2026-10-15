<?php
namespace Dosya\Backup\Wp\Admin\ViewModel;

/**
 * Restore confirmation dialog: same-site and migration variants. No WordPress calls.
 */
final class RestoreDialog
{
    /**
     * Normalised identity of an address: [host without www, port, path], or null when unparsable.
     *
     * @return array{0:string,1:string,2:string}|null
     */
    private static function identity(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $u = parse_url(strpos($url, '://') === false ? '//' . $url : $url); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- view models call no WordPress functions; PHP 7.4+ parses consistently
        if (!is_array($u) || !isset($u['host']) || $u['host'] === '') {
            return null;
        }
        $h = strtolower($u['host']);
        if (strncmp($h, 'www.', 4) === 0) {
            $h = substr($h, 4);
        }
        $port = isset($u['port']) ? (int) $u['port'] : 0;
        if ($port === 80 || $port === 443) {
            $port = 0;
        }
        return [$h, $port === 0 ? '' : (string) $port, rtrim((string) ($u['path'] ?? ''), '/')];
    }

    /** Host of an address for display, lower-cased, without a leading "www.". */
    private static function host(string $url): string
    {
        $i = self::identity($url);
        return $i !== null ? $i[0] : trim($url);
    }

    /** Same site means same host (ignoring www), port and path; scheme and trailing slash do not matter. */
    public static function sameSite(?string $origin, string $home): bool
    {
        if ($origin === null || trim($origin) === '') {
            return true;
        }
        $o = self::identity($origin);
        $h = self::identity($home);
        return $o !== null && $h !== null && $o === $h;
    }

    /**
     * @param array<string,mixed> $backup
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    public static function build(array $backup, array $ctx, callable $t): array
    {
        $now = (int) ($ctx['now'] ?? 0);
        $tz = (string) ($ctx['tz'] ?? 'UTC');
        $home = (string) ($ctx['home_url'] ?? '');
        $domain = (string) ($ctx['domain'] ?? '');
        $safetyOn = !empty($ctx['safety_copy']);
        $origin = isset($backup['origin']) && is_string($backup['origin']) ? $backup['origin'] : null;
        $migration = !self::sameSite($origin, $home);
        $originHost = $migration ? self::host((string) $origin) : '';
        $fmt = isset($ctx['fmt']) && is_callable($ctx['fmt']) ? $ctx['fmt'] : null;
        $when = Format::when((int) ($backup['created'] ?? 0), $now, $tz, $t, true, $fmt);

        if ($migration) {
            /* translators: %s: domain of the site the backup was made on. */
            $title = sprintf($t('Move %s onto this site?'), $originHost);
            /* translators: 1: domain of this site, 2: domain of the site the backup was made on. %1$s must appear exactly once and first: the renderer splits the sentence on it. */
            $tpl = $t("This replaces everything on %1\$s with %2\$s's database and files. Links and media paths are rewritten to the new address.");
            $go = $t('Replace this site');
        } else {
            /* translators: %s: date and time of the backup. */
            $title = sprintf($t('Restore the backup from %s?'), $when);
            /* translators: %1$s: domain of this site. It must appear exactly once and first: the renderer splits the sentence on it. */
            $tpl = $t('This replaces the current %1$s with the site as it was then:');
            $go = $t('Restore site');
        }

        $urlChange = null;
        if (!$migration && $origin !== null && trim($origin) !== '' && strcasecmp(rtrim($origin, '/'), rtrim($home, '/')) !== 0) {
            /* translators: 1: address the backup was made on, 2: this site's address. */
            $urlChange = sprintf($t('Links are updated from %1$s to %2$s.'), $origin, $home);
        }

        $split = explode('%1$s', $tpl, 2);
        $parts = [
            'before' => sprintf($split[0], $domain, $originHost),
            'domain' => $domain,
            'after' => sprintf($split[1] ?? '', $domain, $originHost),
        ];
        $consequence = sprintf($tpl, $domain, $originHost);

        $scope = (string) ($backup['scope'] ?? 'full');
        $includes = [];
        if ($scope !== 'files') {
            $includes[] = $t('Database: posts, pages, settings, users, orders');
        }
        if ($scope !== 'db' && $scope !== 'database') {
            $includes[] = $t('Files: themes, plugins, uploads');
        }

        $kind = (string) ($backup['kind'] ?? 'full');
        if ($kind === 'incremental') {
            $type = $t('Incremental backup');
        } elseif ($scope === 'db' || $scope === 'database' || $kind === 'db') {
            $type = $t('Database backup');
        } elseif ($scope === 'files') {
            $type = $t('Files backup');
        } else {
            $type = $t('Full backup');
        }
        $facts = [$type, Format::bytes((int) ($backup['size'] ?? 0))];
        $note = trim((string) ($backup['note'] ?? ''));
        if ($note !== '') {
            $facts[] = $note;
        }
        $rs = (string) ($backup['remote_state'] ?? 'none');
        if ($rs === 'verified') {
            $facts[] = $t('Stored in dosya, verified restorable');
        } elseif ($rs === 'uploaded' || $rs === 'uploading') {
            $facts[] = $t('Stored in dosya, not yet verified');
        } elseif (!empty($backup['is_uploaded'])) {
            $facts[] = $t('Uploaded from another site');
        } else {
            $facts[] = $t('On this server');
        }
        $line = (string) array_shift($facts);
        foreach ($facts as $f) {
            /* translators: 1: text so far, 2: next fact. Joins the facts line. */
            $line = sprintf($t('%1$s · %2$s'), $line, $f);
        }

        return [
            'variant' => $migration ? 'migration' : 'same',
            'title' => $title,
            'facts' => $line,
            'consequence' => $consequence,
            'consequence_parts' => $parts,
            'labels' => ['address' => $t('Site address after restore'), 'confirm' => $t("Type the current site's domain to confirm")],
            'includes' => $includes,
            'safety' => $safetyOn ? $t('A safety copy of the current site is made first. If anything looks wrong afterwards, open the Backups list and restore that safety copy.') : null,
            'show_address' => $migration || $urlChange !== null,
            'url_change' => $urlChange,
            'address_default' => $home,
            'type_to_confirm' => $migration || !$safetyOn,
            'confirm_domain' => $domain,
            'buttons' => ['cancel' => $t('Keep current site'), 'go' => $go],
        ];
    }
}
