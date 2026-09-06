<?php
namespace Lp\MatterhornImport\Source;

final class RemoteFeedMaterializer
{
    private const CONNECT_TIMEOUT = 15;
    private const TRANSFER_TIMEOUT = 600;
    private const MAX_REDIRECTS = 5;
    private const MAX_BYTES = 8589934592; // 8 GiB hard safety ceiling

    public function __construct(private SourceLocation $locations)
    {
    }

    public function materialize(string $url, int $shopId): string
    {
        $url = $this->locations->validate($url);
        if (!$this->locations->isRemote($url)) {
            throw new \InvalidArgumentException('Remote feed materializer requires an HTTP(S) URL.');
        }
        if ($shopId <= 0) {
            throw new \InvalidArgumentException('Remote feed materializer requires a concrete shop.');
        }

        $directory = _PS_MODULE_DIR_ . 'matterhornimport/var/source-cache/shop-' . $shopId;
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create Matterhorn source cache directory.');
        }

        $target = $directory . '/source.xml';
        $metadataPath = $directory . '/source.meta.json';
        $lockPath = $directory . '/source.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Could not open Matterhorn source cache lock.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Could not lock Matterhorn source cache.');
            }
            return $this->downloadLocked($url, $target, $metadataPath);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function downloadLocked(string $url, string $target, string $metadataPath): string
    {
        $metadata = $this->readMetadata($metadataPath);
        $temp = $target . '.tmp.' . bin2hex(random_bytes(8));
        $handle = @fopen($temp, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('Could not create temporary Matterhorn source file.');
        }

        $responseHeaders = [];
        $downloaded = 0;
        $curl = curl_init();
        if ($curl === false) {
            fclose($handle);
            @unlink($temp);
            throw new \RuntimeException('Could not initialize cURL for Matterhorn source.');
        }

        $requestHeaders = ['Accept: application/xml,text/xml;q=0.9,*/*;q=0.1'];
        if (is_file($target) && is_readable($target)) {
            if (!empty($metadata['etag'])) {
                $requestHeaders[] = 'If-None-Match: ' . $metadata['etag'];
            }
            if (!empty($metadata['last_modified'])) {
                $requestHeaders[] = 'If-Modified-Since: ' . $metadata['last_modified'];
            }
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $handle,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TRANSFER_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'MatterhornImport/0.1.7 PrestaShop',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $line = trim($line);
                if ($line === '' || !str_contains($line, ':')) {
                    return $length;
                }
                [$name, $value] = array_map('trim', explode(':', $line, 2));
                $responseHeaders[strtolower($name)] = $value;
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $chunk) use ($handle, &$downloaded): int {
                $length = strlen($chunk);
                $downloaded += $length;
                if ($downloaded > self::MAX_BYTES) {
                    return 0;
                }
                $written = fwrite($handle, $chunk);
                return $written === false ? 0 : $written;
            },
        ];

        if (defined('CURLOPT_MAXFILESIZE_LARGE')) {
            $options[CURLOPT_MAXFILESIZE_LARGE] = self::MAX_BYTES;
        }

        try {
            if (!curl_setopt_array($curl, $options)) {
                throw new \RuntimeException('Could not configure Matterhorn source download.');
            }
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            fflush($handle);
            fclose($handle);

            if ($ok === false) {
                throw new \RuntimeException('Matterhorn source download failed: ' . ($error !== '' ? $error : 'unknown cURL error'));
            }

            if ($status === 304) {
                @unlink($temp);
                if (!is_file($target) || !is_readable($target)) {
                    throw new \RuntimeException('Matterhorn source returned 304 but no cached file exists.');
                }
                return $target;
            }

            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('Matterhorn source returned HTTP ' . $status . '.');
            }
            if ($downloaded <= 0 || !is_file($temp) || filesize($temp) <= 0) {
                throw new \RuntimeException('Matterhorn source download produced an empty file.');
            }
            if ($downloaded > self::MAX_BYTES) {
                throw new \RuntimeException('Matterhorn source exceeds the 8 GiB safety limit.');
            }

            if (!@rename($temp, $target)) {
                throw new \RuntimeException('Could not atomically publish downloaded Matterhorn source.');
            }
            @chmod($target, 0640);

            $newMetadata = [
                'url' => $url,
                'etag' => (string) ($responseHeaders['etag'] ?? ''),
                'last_modified' => (string) ($responseHeaders['last-modified'] ?? ''),
                'downloaded_at' => gmdate('c'),
                'bytes' => (int) filesize($target),
            ];
            $this->writeMetadata($metadataPath, $newMetadata);

            return $target;
        } finally {
            curl_close($curl);
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /** @return array<string,mixed> */
    private function readMetadata(string $path): array
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

    /** @param array<string,mixed> $metadata */
    private function writeMetadata(string $path, array $metadata): void
    {
        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $temp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($temp, $json, LOCK_EX) === false || !@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Could not persist Matterhorn source cache metadata.');
        }
        @chmod($path, 0640);
    }
}
