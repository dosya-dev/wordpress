<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename -- the manifest is replaced with an atomic rename, WP_Filesystem has no atomic replace.
namespace Dosya\Backup\Archive;

final class Manifest
{
    public const FORMAT = 1;

    public array $meta = [];
    public array $files = [];
    public array $tables = [];
    public array $views = [];
    public array $triggers = [];
    public array $warnings = [];

    public static function fromEntries(array $meta, iterable $entryRows, array $warnings = []): self
    {
        $m = new self();
        $m->meta = $meta;
        $m->warnings = array_values($warnings);
        $files = [];
        $parts = [];
        foreach ($entryRows as $row) {
            $path = PathCodec::unpack($row);
            $type = (int) $row['t'];
            if ($type === Format::TYPE_DB_SCHEMA || $type === Format::TYPE_DB_PART) {
                if (preg_match('#^db/_views/(.+)\.sql$#', $path, $mm)) {
                    $m->views[$mm[1]] = $row['loc'];
                } elseif (preg_match('#^db/_triggers/(.+)\.sql$#', $path, $mm)) {
                    $m->triggers[$mm[1]] = $row['loc'];
                } elseif (preg_match('#^db/(.+)/schema\.sql$#', $path, $mm)) {
                    $m->tables[$mm[1]]['schema'] = $row['loc'];
                } elseif (preg_match('#^db/(.+)/part-(\d+)\.sql$#', $path, $mm)) {
                    $parts[$mm[1]][(int) $mm[2]] = $row['loc'];
                }
                continue;
            }
            $files[$path] = $row;
        }
        foreach ($parts as $table => $byNo) {
            ksort($byNo);
            $m->tables[$table]['parts'] = array_values($byNo);
        }
        foreach ($m->tables as $table => $t) {
            $m->tables[$table] += ['schema' => null, 'parts' => []];
        }
        $m->files = array_values($files);
        return $m;
    }

    public function toArray(): array
    {
        return ['format' => self::FORMAT, 'meta' => $this->meta, 'files' => $this->files, 'tables' => $this->tables,
            'views' => $this->views, 'triggers' => $this->triggers, 'warnings' => $this->warnings];
    }

    public static function fromArray(array $a): self
    {
        if (($a['format'] ?? null) !== self::FORMAT) {
            throw new FormatException('unsupported manifest format');
        }
        $m = new self();
        foreach (['meta', 'files', 'tables', 'views', 'triggers', 'warnings'] as $k) {
            if (array_key_exists($k, $a) && !is_array($a[$k])) {
                throw new FormatException('manifest is malformed');
            }
            $m->$k = $a[$k] ?? [];
        }
        return $m;
    }

    public function saveGz(string $path): void
    {
        $gz = gzencode(json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 6);
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $gz) !== strlen($gz) || !rename($tmp, $path)) {
            throw new \RuntimeException('cannot write manifest');
        }
    }

    public static function loadGz(string $path): self
    {
        $raw = is_readable($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            throw new FormatException('manifest missing or unreadable');
        }
        $json = @gzdecode($raw);
        if ($json === false) {
            throw new FormatException('manifest is not gzip data');
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FormatException('manifest is not valid JSON');
        }
        if (!is_array($data)) {
            throw new FormatException('manifest is malformed');
        }
        return self::fromArray($data);
    }

    public function publicView(): array
    {
        $own = $this->meta['backup_id'];
        $refs = [];
        $collect = function (?array $loc) use (&$refs, $own): void {
            if ($loc !== null && $loc['b'] !== $own) {
                $refs[$loc['b'] . ':' . $loc['v']] = ['b' => $loc['b'], 'v' => (int) $loc['v']];
            }
        };
        foreach ($this->files as $f) { $collect($f['loc']); }
        foreach ($this->tables as $t) {
            $collect($t['schema']);
            foreach ($t['parts'] as $p) { $collect($p); }
        }
        foreach ($this->views as $loc) { $collect($loc); }
        foreach ($this->triggers as $loc) { $collect($loc); }
        return ['format' => self::FORMAT, 'backup_id' => $own, 'created' => $this->meta['created'], 'kind' => $this->meta['kind'],
            'scope' => $this->meta['scope'] ?? 'full', 'label' => $this->meta['label'] ?? '',
            'volumes' => $this->meta['volumes'], 'refs' => array_values($refs)];
    }
}
