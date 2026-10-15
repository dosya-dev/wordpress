<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- upload slices are streamed from the plugin's own files (WP_Filesystem cannot read a byte range); exception messages are plain text, escaped where displayed.
namespace Dosya\Backup\Wp;

use Dosya\Backup\Connect\HttpClient;
use Dosya\Backup\Connect\HttpException;

final class WpHttpClient implements HttpClient
{
    public const MAX_BODY_FILE = 10485760;

    public function send(array $req): array
    {
        $args = [
            'method' => $req['method'],
            'timeout' => (int) ($req['timeout'] ?? 60),
            'sslverify' => true,
            'redirection' => 0,
            'headers' => $req['headers'] ?? [],
        ];
        if (!empty($req['bodyFile'])) {
            $args['body'] = self::readSlice($req['bodyFile']['path'], (int) $req['bodyFile']['offset'], (int) $req['bodyFile']['length']);
        } elseif (isset($req['body'])) {
            $args['body'] = $req['body'];
        }
        $res = wp_remote_request($req['url'], $args);
        if (is_wp_error($res)) {
            throw new HttpException(0, 'network_error', 'network error: ' . $res->get_error_message());
        }
        $headers = [];
        $h = wp_remote_retrieve_headers($res);
        if (is_object($h) && method_exists($h, 'getAll')) {
            $h = $h->getAll();
        }
        foreach ((array) $h as $k => $v) {
            $headers[strtolower((string) $k)] = is_array($v) ? implode(', ', $v) : (string) $v;
        }
        return [
            'status' => (int) wp_remote_retrieve_response_code($res),
            'headers' => $headers,
            'body' => (string) wp_remote_retrieve_body($res),
        ];
    }

    public static function readSlice(string $path, int $offset, int $length): string
    {
        if ($length < 0 || $offset < 0 || $length > self::MAX_BODY_FILE) {
            throw new \InvalidArgumentException('upload slice out of range');
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('cannot open upload file');
        }
        try {
            if ($offset > 0 && fseek($fh, $offset) !== 0) {
                throw new \RuntimeException('cannot seek upload file');
            }
            $out = '';
            while (strlen($out) < $length) {
                $chunk = fread($fh, $length - strlen($out));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $out .= $chunk;
            }
        } finally {
            fclose($fh);
        }
        if (strlen($out) !== $length) {
            throw new \RuntimeException('upload file shorter than the requested slice');
        }
        return $out;
    }
}
