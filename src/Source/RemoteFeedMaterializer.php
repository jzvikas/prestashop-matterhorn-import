<?php
namespace Lp\MatterhornImport\Source;

final class RemoteFeedMaterializer
{
    private const CONNECT_TIMEOUT = 15;
    private const TRANSFER_TIMEOUT = 600;
    private const MAX_REDIRECTS = 5;
    private const MAX_BYTES = 8589934592; // 8 GiB hard safety ceiling
    private const MAX_DOWNLOAD_ATTEMPTS = 2;

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
        $lastIncomplete = null;

        for ($attempt = 1; $attempt <= self::MAX_DOWNLOAD_ATTEMPTS; ++$attempt) {
            try {
                return $this->downloadAttempt(
                    $url,
                    $target,
                    $metadataPath,
                    $attempt === 1
                );
            } catch (\RuntimeException $e) {
                if (!str_starts_with($e->getMessage(), 'Matterhorn source download is incomplete:')) {
                    throw $e;
                }
                $lastIncomplete = $e;
            }
        }

        throw new \RuntimeException(
            'Matterhorn source download is incomplete after ' . self::MAX_DOWNLOAD_ATTEMPTS .
            ' attempts: ' . ($lastIncomplete?->getMessage() ?? 'unknown validation failure'),
            0,
            $lastIncomplete
        );
    }

    private function downloadAttempt(
        string $url,
        string $target,
        string $metadataPath,
        bool $allowConditional
    ): string {
        $metadata = $this->readMetadata($metadataPath);
        $sameSource = (string) ($metadata['url'] ?? '') === $url;

        $cacheUsable = $sameSource
            && is_file($target)
            && is_readable($target);

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
        if ($allowConditional && $cacheUsable) {
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
            CURLOPT_USERAGENT => 'MatterhornImport/0.1.8 PrestaShop',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $trimmed = trim($line);

                // New HTTP response (redirect/final response): do not retain stale headers.
                if (preg_match('#^HTTP/\S+\s+\d{3}\b#i', $trimmed) === 1) {
                    $responseHeaders = [];
                    return $length;
                }

                if ($trimmed === '' || !str_contains($trimmed, ':')) {
                    return $length;
                }

                [$name, $value] = array_map('trim', explode(':', $trimmed, 2));
                $responseHeaders[strtolower($name)] = $value;

                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $chunk) use ($handle, &$downloaded): int {
                $length = strlen($chunk);
                if ($downloaded + $length > self::MAX_BYTES) {
                    return 0;
                }

                $written = fwrite($handle, $chunk);
                if ($written === false) {
                    return 0;
                }

                $downloaded += $written;
                return $written;
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
                throw new \RuntimeException(
                    'Matterhorn source download failed: ' . ($error !== '' ? $error : 'unknown cURL error')
                );
            }

            if ($status === 304) {
                @unlink($temp);
                if (!$cacheUsable) {
                    throw new \RuntimeException(
                        'Matterhorn source download is incomplete: server returned 304 for an invalid local cache.'
                    );
                }
                return $target;
            }

            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('Matterhorn source returned HTTP ' . $status . '.');
            }

            clearstatcache(true, $temp);
            $fileBytes = is_file($temp) ? (int) filesize($temp) : 0;
            if ($downloaded <= 0 || $fileBytes <= 0) {
                throw new \RuntimeException(
                    'Matterhorn source download is incomplete: downloaded file is empty.'
                );
            }
            if ($downloaded > self::MAX_BYTES || $fileBytes > self::MAX_BYTES) {
                throw new \RuntimeException('Matterhorn source exceeds the 8 GiB safety limit.');
            }
            if ($fileBytes !== $downloaded) {
                throw new \RuntimeException(
                    'Matterhorn source download is incomplete: written byte count does not match downloaded byte count.'
                );
            }

            $contentLength = trim((string) ($responseHeaders['content-length'] ?? ''));
            if ($contentLength !== '' && ctype_digit($contentLength)) {
                $expectedBytes = (int) $contentLength;
                if ($expectedBytes > 0 && $expectedBytes !== $downloaded) {
                    throw new \RuntimeException(sprintf(
                        'Matterhorn source download is incomplete: HTTP Content-Length is %d bytes but %d bytes were received.',
                        $expectedBytes,
                        $downloaded
                    ));
                }
            }

            if (!@rename($temp, $target)) {
                throw new \RuntimeException('Could not atomically publish downloaded Matterhorn source.');
            }
            @chmod($target, 0640);

            clearstatcache(true, $target);
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
        $json = json_encode(
            $metadata,
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        );
        $temp = $path . '.tmp.' . bin2hex(random_bytes(6));

        if (@file_put_contents($temp, $json, LOCK_EX) === false || !@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Could not persist Matterhorn source cache metadata.');
        }

        @chmod($path, 0640);
    }
}
