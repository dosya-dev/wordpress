<?php
namespace Dosya\Backup\Wp\Admin\ViewModel;

/**
 * View model for the connection card on the dosya tab. Pure: no WordPress calls.
 *
 * Input: key_state ('none'|'ok'|'unreadable'|'revoked'), connection (?array: retention_days,
 * immutability_hours, folder, account, workspace, each ?), storage (?array used,total bytes),
 * last_upload (?array created,verified), avg_backup (?int bytes), plan_line (string),
 * now (int), tz (string), fmt (?callable for dates).
 *
 * Output: state (none|connected|nearly_full|unreadable|revoked), tone, headline, sub,
 * facts [{label,value}], meter (?{used,total,percent,projection,storage_text}),
 * primary (?{label,kind connect|review_excludes}), secondary [{label,kind open|disconnect}],
 * consent (?string), plan (?string), fix (?string), disconnect_confirm (?string).
 */
final class ConnectCard
{
    public static function build(array $in, callable $t): array
    {
        $key = (string) ($in['key_state'] ?? 'none');
        $conn = is_array($in['connection'] ?? null) ? $in['connection'] : null;
        $storage = is_array($in['storage'] ?? null) ? $in['storage'] : null;
        $now = (int) ($in['now'] ?? 0);
        $tz = (string) ($in['tz'] ?? 'UTC');
        $fmt = isset($in['fmt']) && is_callable($in['fmt']) ? $in['fmt'] : null;

        $card = [
            'state' => 'none', 'tone' => 'neutral', 'headline' => '', 'sub' => '', 'facts' => [], 'meter' => null,
            'primary' => null, 'secondary' => [], 'consent' => null, 'plan' => null, 'fix' => null, 'disconnect_confirm' => null,
        ];
        $connect = ['label' => $t('Connect again'), 'kind' => 'connect'];

        if ($key === 'unreadable') {
            $card['state'] = 'unreadable';
            $card['tone'] = 'error';
            $card['headline'] = $t('Connection to dosya lost');
            $card['sub'] = $t("Your site's security keys changed, so the saved connection cannot be read. Backups already in dosya are safe. Connect again to resume uploads.");
            $card['primary'] = $connect;
            return $card;
        }
        if ($key === 'revoked') {
            $card['state'] = 'revoked';
            $card['tone'] = 'error';
            $card['headline'] = $t('This site was disconnected from dosya.dev');
            $card['sub'] = $t('Connect again to resume uploads.');
            $card['primary'] = $connect;
            return $card;
        }
        if ($key !== 'ok') {
            $card['headline'] = $t('Store copies in dosya');
            $card['sub'] = $t('Keep a second copy of every backup off this server. If this server is ever lost, you can restore from dosya.');
            $card['consent'] = $t('You will approve this site on dosya.dev. dosya receives the site address, its name, and the backups you make. Nothing else.');
            $card['plan'] = (string) ($in['plan_line'] ?? '');
            $card['primary'] = ['label' => $t('Connect to dosya'), 'kind' => 'connect'];
            return $card;
        }

        $used = $storage !== null ? (int) $storage['used'] : 0;
        $total = $storage !== null ? (int) $storage['total'] : 0;
        $full = $storage !== null && $total > 0 && $used * 10 >= $total * 9;

        $card['state'] = $full ? 'nearly_full' : 'connected';
        $card['tone'] = $full ? 'caution' : 'success';
        $card['disconnect_confirm'] = $t('Disconnect this site from dosya? Backups already stored in dosya are kept.');
        $card['facts'] = self::facts($conn, $in['last_upload'] ?? null, $now, $tz, $fmt, $t);

        if ($storage !== null && $total > 0) {
            $avg = (int) ($in['avg_backup'] ?? 0);
            $projection = null;
            if ($avg > 0 && $total > $used) {
                $projection = sprintf($t('about %d more backups at the current size'), intdiv($total - $used, $avg));
            }
            $card['meter'] = [
                'used' => $used, 'total' => $total, 'percent' => (int) round($used / $total * 100), 'projection' => $projection,
                'storage_text' => sprintf($t('%1$s of %2$s used'), Format::bytes($used), Format::bytes($total)),
            ];
        }

        $open = ['label' => $t('Open in dosya ↗'), 'kind' => 'open'];
        if ($full) {
            $card['headline'] = $t('Storage in dosya is almost full');
            $card['sub'] = sprintf($t('%1$s of %2$s. When it is full, the next upload is skipped and the backup stays on this server only.'), Format::bytes($used), Format::bytes($total));
            $card['fix'] = $t('Free the space first: exclude the uploads folder under Schedule, or shorten retention on dosya.dev. If you need more, plans start at 50 GB on dosya.dev.');
            $card['primary'] = ['label' => $t('Review excludes'), 'kind' => 'review_excludes'];
            $card['secondary'] = [$open];
            return $card;
        }

        $card['headline'] = $t('Connected to dosya');
        $account = $conn['account'] ?? null;
        if ($account !== null && $account !== '') {
            $workspace = $conn['workspace'] ?? null;
            $card['sub'] = $workspace !== null && $workspace !== ''
                ? sprintf($t('as %1$s · workspace %2$s'), $account, $workspace)
                : sprintf($t('as %s'), $account);
        }
        $card['secondary'] = [$open, ['label' => $t('Disconnect'), 'kind' => 'disconnect']];
        return $card;
    }

    private static function facts(?array $conn, $last, int $now, string $tz, ?callable $fmt, callable $t): array
    {
        $facts = [];
        if ($conn !== null && isset($conn['folder']) && $conn['folder'] !== '') {
            $facts[] = ['label' => $t('Folder'), 'value' => (string) $conn['folder']];
        }
        if (is_array($last) && isset($last['created'])) {
            $when = Format::when((int) $last['created'], $now, $tz, $t, true, $fmt);
            $facts[] = ['label' => $t('Last upload'), 'value' => sprintf(
                !empty($last['verified']) ? $t('%s, verified') : $t('%s, not yet verified'),
                $when
            )];
        }
        if ($conn !== null && isset($conn['retention_days'])) {
            $facts[] = ['label' => $t('Retention'), 'value' => sprintf($t('%d days'), (int) $conn['retention_days'])];
        }
        if ($conn !== null && isset($conn['immutability_hours'])) {
            $facts[] = ['label' => $t('Protection'), 'value' => sprintf($t('%d h after each upload'), (int) $conn['immutability_hours'])];
        }
        return $facts;
    }
}
