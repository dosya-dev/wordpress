<?php
namespace Dosya\Backup\Wp\Admin;

/**
 * Server-side rules for a restore request from the admin page. No WordPress calls: the caller sanitises the fields and
 * translates the error codes.
 */
final class RestoreRequest
{
    public const SCOPES = ['full', 'db', 'files'];

    /**
     * @param array<string,mixed> $dialogVm RestoreDialog::build() output for the requested backup
     * @param array<string,string> $post sanitised request fields: confirm, scope, new_url, confirm_domain
     * @param string $domain this site's domain
     * @return array<string,string>|string restore options (scope, optional new_url), or an error code: 'confirm' or 'domain'
     */
    public static function validate(array $dialogVm, array $post, string $domain)
    {
        if (($post['confirm'] ?? '') !== '1') {
            return 'confirm';
        }
        $scope = (string) ($post['scope'] ?? '');
        $scope = in_array($scope, self::SCOPES, true) ? $scope : 'full';
        // the safety copy holds the database only, so a files-only restore runs without one and needs the typed domain too
        if (!empty($dialogVm['type_to_confirm']) || $scope === 'files') {
            $typed = strtolower(trim((string) ($post['confirm_domain'] ?? '')));
            $want = strtolower(trim($domain));
            if ($want === '' || $typed !== $want) {
                return 'domain';
            }
        }
        $opts = ['scope' => $scope];
        $url = trim((string) ($post['new_url'] ?? ''));
        if (!empty($dialogVm['show_address']) && $url !== '') {
            $opts['new_url'] = $url;
        }
        return $opts;
    }

    /**
     * RestoreDialog::build() input for a stored backup. A backup whose sidecar has no origin (made before the sidecar existed)
     * takes the address from its manifest, so it still gets the right variant.
     *
     * @param array<string,mixed> $row LocalStore::list() row
     * @param array<string,mixed>|null $remoteInfo RemoteStore::info() for the backup
     * @param string|null $manifestHome the manifest's site.home, or null when unknown
     * @return array<string,mixed>
     */
    public static function dialogInput(array $row, ?array $remoteInfo, ?string $manifestHome): array
    {
        $origin = isset($row['origin']) && is_string($row['origin']) && trim($row['origin']) !== '' ? $row['origin'] : null;
        if ($origin === null && $manifestHome !== null && trim($manifestHome) !== '') {
            $origin = $manifestHome;
        }
        $remoteState = $remoteInfo === null ? 'none' : (($remoteInfo['verified'] ?? null) === true ? 'verified' : 'uploaded');
        return [
            'created' => (int) ($row['created'] ?? 0),
            'scope' => self::scope((string) ($row['scope'] ?? 'full')),
            'kind' => (string) ($row['kind'] ?? 'full'),
            'size' => (int) ($row['size'] ?? 0),
            'note' => (string) ($row['note'] ?? ''),
            'origin' => $origin,
            'remote_state' => $remoteState,
            'is_uploaded' => !empty($row['uploaded']),
        ];
    }

    /** A stored backup's scope as one of SCOPES ('database' is the older spelling of 'db'). */
    public static function scope(string $scope): string
    {
        if ($scope === 'database') {
            return 'db';
        }
        return in_array($scope, self::SCOPES, true) ? $scope : 'full';
    }
}
