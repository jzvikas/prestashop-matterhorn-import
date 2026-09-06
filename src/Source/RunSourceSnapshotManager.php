<?php
namespace Lp\MatterhornImport\Source;

final class RunSourceSnapshotManager
{
    private const META_FILE = 'source.meta.json';
    private const SOURCE_FILE = 'source.xml';

    /**
     * @return array{path:string,fingerprint:string,bytes:int}
     */
    public function create(
        int $runId,
        int $shopId,
        string $sourcePath,
        string $fingerprint,
        bool $hardLinkSafe
    ): array {
        $this->assertIdentity($runId, $shopId);
        if (!preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
            throw new \InvalidArgumentException('Matterhorn run-source fingerprint must be SHA-256');
        }
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \RuntimeException('Matterhorn source snapshot input is not readable: ' . $sourcePath);
        }

        $directory = $this->directory($runId, $shopId);
        $this->removeDirectory($directory);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create Matterhorn run-source directory');
        }

        $target = $directory . '/' . self::SOURCE_FILE;
        $temp = $target . '.tmp.' . bin2hex(random_bytes(6));
        $published = false;
        try {
            if ($hardLinkSafe && @link($sourcePath, $temp)) {
                // RemoteFeedMaterializer publishes its cache by atomic rename, so this
                // hard link remains pinned to the exact inode used by this import run.
            } elseif (!@copy($sourcePath, $temp)) {
                throw new \RuntimeException('Could not freeze Matterhorn source for import run');
            }
            if (!@rename($temp, $target)) {
                throw new \RuntimeException('Could not publish frozen Matterhorn run source');
            }
            $published = true;
            @chmod($target, 0640);

            clearstatcache(true, $target);
            $bytes = filesize($target);
            if ($bytes === false || (int) $bytes <= 0) {
                throw new \RuntimeException('Frozen Matterhorn run source is empty');
            }

            $this->writeJson($directory . '/' . self::META_FILE, [
                'fingerprint' => $fingerprint,
                'bytes' => (int) $bytes,
                'created_at' => gmdate('c'),
            ]);

            return [
                'path' => $target,
                'fingerprint' => $fingerprint,
                'bytes' => (int) $bytes,
            ];
        } catch (\Throwable $exception) {
            if (!$published) {
                @unlink($temp);
            }
            $this->removeDirectory($directory);
            throw $exception;
        }
    }

    /**
     * @return array{path:string,fingerprint:string,bytes:int}|null
     */
    public function load(int $runId, int $shopId): ?array
    {
        $this->assertIdentity($runId, $shopId);
        $directory = $this->directory($runId, $shopId);
        $path = $directory . '/' . self::SOURCE_FILE;
        $meta = $this->readJson($directory . '/' . self::META_FILE);
        if (!is_file($path) || !is_readable($path) || $meta === []) {
            return null;
        }

        $fingerprint = (string) ($meta['fingerprint'] ?? '');
        $bytes = (int) ($meta['bytes'] ?? 0);
        clearstatcache(true, $path);
        $actual = filesize($path);
        if (
            !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)
            || $bytes <= 0
            || $actual === false
            || (int) $actual !== $bytes
        ) {
            throw new \RuntimeException('Frozen Matterhorn run source metadata is invalid');
        }

        return ['path' => $path, 'fingerprint' => $fingerprint, 'bytes' => $bytes];
    }

    public function release(int $runId, int $shopId): void
    {
        $this->assertIdentity($runId, $shopId);
        $this->removeDirectory($this->directory($runId, $shopId));
    }

    private function directory(int $runId, int $shopId): string
    {
        return _PS_MODULE_DIR_ . 'matterhornimport/var/run-source/shop-' . $shopId . '/run-' . $runId;
    }

    private function assertIdentity(int $runId, int $shopId): void
    {
        if ($runId <= 0 || $shopId <= 0) {
            throw new \InvalidArgumentException('Matterhorn run source requires positive run/shop IDs');
        }
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new \RuntimeException('Matterhorn run-source directory disappeared');
        }
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $temp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($temp, $json, LOCK_EX) === false || !@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Could not persist Matterhorn run-source metadata');
        }
        @chmod($path, 0640);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach ([self::META_FILE, self::SOURCE_FILE] as $file) {
            @unlink($directory . '/' . $file);
        }
        foreach (glob($directory . '/*.tmp.*') ?: [] as $temp) {
            @unlink($temp);
        }
        @rmdir($directory);
    }
}
