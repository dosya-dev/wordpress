<?php
namespace Dosya\Backup\Tests\unit\Connect;

use Dosya\Backup\Connect\HttpClient;

final class FakeHttpClient implements HttpClient
{
    /** @var list<array<string,mixed>> */
    public array $requests = [];
    /** @var list<array{method:string,path:string,status:int,body:mixed,headers:array<string,string>,cb:?callable}> */
    private array $script = [];

    /** @param mixed $body array (JSON encoded) or string @param array<string,string> $headers */
    public function on(string $method, string $path, int $status, $body = [], ?callable $cb = null, array $headers = []): self
    {
        $this->script[] = ['method' => $method, 'path' => $path, 'status' => $status, 'body' => $body, 'headers' => $headers, 'cb' => $cb];
        return $this;
    }

    public function send(array $req): array
    {
        $path = (string) parse_url($req['url'], PHP_URL_PATH);
        // The recorded request carries the resolved body bytes for bodyFile requests.
        if (!empty($req['bodyFile'])) {
            $f = $req['bodyFile'];
            $req['body'] = substr((string) file_get_contents($f['path']), $f['offset'], $f['length']);
        }
        $this->requests[] = $req;
        foreach ($this->script as $s) {
            if ($s['method'] === $req['method'] && $s['path'] === $path) {
                if ($s['cb'] !== null) {
                    ($s['cb'])($req);
                }
                $body = is_string($s['body']) ? $s['body'] : json_encode($s['body']);
                return ['status' => $s['status'], 'headers' => $s['headers'], 'body' => $body];
            }
        }
        throw new \LogicException('unscripted request ' . $req['method'] . ' ' . $path);
    }
}
