<?php
namespace Lp\MatterhornImport\Image;

final class SafeImageDownloader
{
    private const MAX_BYTES = 26214400;
    private const MAX_PIXELS = 80000000;

    public function download(string $url, ?string $etag = null, ?string $lastModified = null): ?DownloadedImage
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is required for Matterhorn image downloads');
        }
        [$host, $port, $ip, $literalIp] = $this->validatedEndpoint($url);
        $tmp = tempnam($this->tempDirectory(), 'matterhorn_img_');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create image temp file');
        }
        $fp = fopen($tmp, 'wb');
        if ($fp === false) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot open image temp file');
        }

        $received = 0;
        $declaredTooLarge = false;
        $responseHeaders = [];
        $contentHash = hash_init('sha256');
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fp);
            @unlink($tmp);
            throw new \RuntimeException('Cannot initialize image HTTP client');
        }

        $headers = [];
        if ($etag !== null && trim($etag) !== '') {
            $headers[] = 'If-None-Match: ' . trim($etag);
        }
        if ($lastModified !== null && trim($lastModified) !== '') {
            $headers[] = 'If-Modified-Since: ' . trim($lastModified);
        }

        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FAILONERROR => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => 'MatterhornImport/0.1',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders, &$declaredTooLarge): int {
                $length = strlen($line);
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];
                    return $length;
                }
                $separator = strpos($line, ':');
                if ($separator === false) {
                    return $length;
                }
                $name = strtolower(trim(substr($line, 0, $separator)));
                $value = trim(substr($line, $separator + 1));
                if ($name === 'content-length' && ctype_digit($value) && (int) $value > self::MAX_BYTES) {
                    $declaredTooLarge = true;
                    return 0;
                }
                if (in_array($name, ['etag', 'last-modified'], true)) {
                    $responseHeaders[$name] = $value;
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use ($fp, &$received, $contentHash): int {
                $length = strlen($data);
                $received += $length;
                if ($received > self::MAX_BYTES) {
                    return 0;
                }
                hash_update($contentHash, $data);
                $written = fwrite($fp, $data);
                return $written === false ? 0 : $written;
            },
        ];
        if (!$literalIp) {
            $resolveIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $resolveIp];
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$this->sameIp($primaryIp, $ip)) {
            @unlink($tmp);
            throw new \RuntimeException('Image connection endpoint changed after validation');
        }
        if ($code === 304) {
            @unlink($tmp);
            if ($etag === null && $lastModified === null) {
                throw new \RuntimeException('Unexpected image 304 without validators');
            }
            return null;
        }
        if ($declaredTooLarge || $received > self::MAX_BYTES) {
            @unlink($tmp);
            throw new \RuntimeException('Image exceeds maximum download size');
        }
        if (!$ok || $code < 200 || $code >= 300) {
            @unlink($tmp);
            throw new \RuntimeException('Image HTTP failure ' . $code . ' ' . $error);
        }
        if ($received <= 0) {
            @unlink($tmp);
            throw new \RuntimeException('Image HTTP response body is empty');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            @unlink($tmp);
            throw new \RuntimeException('Unsupported image MIME ' . $mime . ' (' . $contentType . ')');
        }
        $size = @getimagesize($tmp);
        $width = is_array($size) ? (int) ($size[0] ?? 0) : 0;
        $height = is_array($size) ? (int) ($size[1] ?? 0) : 0;
        if ($width <= 0 || $height <= 0 || $width * $height > self::MAX_PIXELS) {
            @unlink($tmp);
            throw new \RuntimeException('Invalid or oversized image dimensions');
        }

        return new DownloadedImage(
            $tmp,
            $mime,
            $width,
            $height,
            $received,
            hash_final($contentHash),
            $this->headerValue($responseHeaders, 'etag'),
            $this->headerValue($responseHeaders, 'last-modified')
        );
    }

    private function tempDirectory(): string
    {
        if (defined('_PS_CACHE_DIR_') && is_dir(_PS_CACHE_DIR_) && is_writable(_PS_CACHE_DIR_)) {
            return _PS_CACHE_DIR_;
        }
        return sys_get_temp_dir();
    }

    /** @return array{0:string,1:int,2:string,3:bool} */
    private function validatedEndpoint(string $url): array
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only HTTP(S) image URLs allowed');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Credentials in image URLs are not allowed');
        }
        $host = trim((string) $parts['host'], '[]');
        $literalIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$literalIp && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new \InvalidArgumentException('Invalid image host');
        }
        $ips = [];
        if ($literalIp) {
            $ips[] = $host;
        } else {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip'])) {
                        $ips[] = (string) $record['ip'];
                    }
                    if (isset($record['ipv6'])) {
                        $ips[] = (string) $record['ipv6'];
                    }
                }
            }
        }
        $public = [];
        foreach (array_values(array_unique($ips)) as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                $public[] = $candidate;
            }
        }
        if ($public === []) {
            throw new \RuntimeException('Private/reserved or unresolved image host blocked');
        }
        $port = (int) ($parts['port'] ?? (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('Invalid image URL port');
        }
        return [$host, $port, $public[0], $literalIp];
    }

    private function sameIp(string $actual, string $expected): bool
    {
        $actualPacked = @inet_pton($actual);
        $expectedPacked = @inet_pton($expected);
        return $actualPacked !== false && $expectedPacked !== false && hash_equals($expectedPacked, $actualPacked);
    }

    private function headerValue(array $headers, string $name): ?string
    {
        $value = trim((string) ($headers[$name] ?? ''));
        return $value === '' ? null : $value;
    }
}
