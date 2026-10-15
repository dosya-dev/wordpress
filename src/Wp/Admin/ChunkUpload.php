<?php
namespace Dosya\Backup\Wp\Admin;

use Dosya\Backup\Archive\FileSource;
use Dosya\Backup\Archive\Manifest;
use Dosya\Backup\Archive\VolumeReader;
use Dosya\Backup\Jobs\LocalStore;
use Dosya\Backup\Support\Fs;

/** Receives a downloaded backup folder back, one small chunk at a time, so upload_max_filesize never matters. */
final class ChunkUpload
{
    private const NAME_RE = '/^(manifest\.json\.gz|manifest\.public\.json|v\d{4}\.dosya)\z/';
    private const MAX_FILE = 1099511627776;
    private LocalStore $store;

    public function __construct(LocalStore $store)
    {
        $this->store = $store;
    }

    /** @return array{next:int} */
    public function receive(string $uploadId, string $name, int $offset, int $total, string $chunkPath): array
    {
        if (preg_match(self::NAME_RE, $name) !== 1) {
            throw new \InvalidArgumentException('Unexpected file name: only manifest.json.gz and vNNNN.dosya files belong to a backup.');
        }
        if ($total < 0 || $total > self::MAX_FILE) {
            throw new \InvalidArgumentException('Invalid file size.');
        }
        $this->store->ensure();
        $dir = $this->store->incomingDir($uploadId);
        Fs::ensureDir($dir);
        $path = $dir . '/' . $name;
        clearstatcache(true, $path);
        $have = is_file($path) ? (int) filesize($path) : 0;
        if ($offset !== $have) {
            return ['next' => $have];
        }
        $data = file_get_contents($chunkPath);
        if ($data === false) {
            throw new \RuntimeException('The uploaded chunk could not be read.');
        }
        if ($have + strlen($data) > $total) {
            throw new \InvalidArgumentException('Chunk goes past the end of the file.');
        }
        if (file_put_contents($path, $data, FILE_APPEND) !== strlen($data)) {
            throw new \RuntimeException('Write failed (disk full?)');
        }
        return ['next' => $have + strlen($data)];
    }

    public function finish(string $uploadId): string
    {
        $dir = $this->store->incomingDir($uploadId);
        try {
            return $this->import($dir);
        } catch (\Throwable $e) {
            Fs::rrmdir($dir);
            throw $e;
        }
    }

    private function import(string $dir): string
    {
        $m = Manifest::loadGz($dir . '/manifest.json.gz');
        $id = (string) ($m->meta['backup_id'] ?? '');
        $target = $this->store->backupDir($id);
        if ($m->publicView()['refs'] !== []) {
            throw new \RuntimeException('This backup depends on earlier backups that were not uploaded.');
        }
        clearstatcache();
        foreach ($m->meta['volumes'] as $v) {
            $p = sprintf('%s/v%04d.dosya', $dir, (int) $v['v']);
            if (!is_file($p) || filesize($p) !== (int) $v['size']) {
                throw new \RuntimeException(sprintf('Volume %d is missing or incomplete.', (int) $v['v']));
            }
            if (!hash_equals((string) $v['sha256'], (string) hash_file('sha256', $p))) {
                throw new \RuntimeException(sprintf('Volume %d is damaged (checksum mismatch).', (int) $v['v']));
            }
            (new VolumeReader(new FileSource($p)))->index();
        }
        if (!is_dir($target)) {
            try {
                Fs::ensureDir($target . '/volumes');
                foreach ($m->meta['volumes'] as $v) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic move inside the backup store
                    if (!rename(sprintf('%s/v%04d.dosya', $dir, (int) $v['v']), $this->store->volumePath($id, (int) $v['v']))) {
                        throw new \RuntimeException('Could not move the uploaded backup into place.');
                    }
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic move inside the backup store
                if (!rename($dir . '/manifest.json.gz', $this->store->manifestPath($id))) {
                    throw new \RuntimeException('Could not move the uploaded backup into place.');
                }
                $pub = json_encode($m->publicView(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (file_put_contents($this->store->publicManifestPath($id), $pub) !== strlen($pub)) {
                    throw new \RuntimeException('Could not move the uploaded backup into place.');
                }
            } catch (\Throwable $e) {
                Fs::rrmdir($target);
                throw $e;
            }
        }
        if (!is_file($this->store->localMetaPath($id))) {
            $this->store->writeLocalMeta($id, '', (string) ($m->meta['site']['home'] ?? ''), true);
        }
        Fs::rrmdir($dir);
        return $id;
    }
}
