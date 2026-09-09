<?php
namespace Lp\MatterhornImport\Image;

final class SafeImageDownloader
{
    private const MAX_URL_BYTES = 16384;
    private const MAX_BYTES = 26214400;
    private const MAX_PIXELS = 80000000;
    private const MAX_REDIRECTS = 5;

    public function download(string $url, ?string $etag = null, ?string $lastModified = null): ?DownloadedImage
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is required for Matterhorn image downloads');
        }

        $currentUrl = trim($url);
        $visited = [];

        for ($redirects = 0; ; $redirects++) {
            if ($redirects > self::MAX_REDIRECTS) {
                throw new \RuntimeException('Image redirect limit exceeded');
            }

            $fingerprint = hash('sha256', $currentUrl);
            if (isset($visited[$fingerprint])) {
                throw new \RuntimeException('Image redirect loop detected');
            }
            $visited[$fingerprint] = true;

            $result = $this->downloadOnce($currentUrl, $etag, $lastModified);
            if ($result['redirect'] === null) {
                return $result['image'];
            }

            $nextUrl = $this->resolveRedirectUrl($currentUrl, $result['redirect']);
            $fromScheme = strtolower((string) (parse_url($currentUrl, PHP_URL_SCHEME) ?: ''));
            $toScheme = strtolower((string) (parse_url($nextUrl, PHP_URL_SCHEME) ?: ''));
            if ($fromScheme === 'https' && $toScheme === 'http') {
                throw new \RuntimeException('Image redirect protocol downgrade blocked');
            }

            // Every redirect hop is revalidated from scratch by downloadOnce(), including DNS/IP
            // fencing. This keeps redirect support SSRF-safe instead of relying on CURLOPT_FOLLOWLOCATION.
            $currentUrl = $nextUrl;
        }
    }

    /** @return array{image:?DownloadedImage,redirect:?string} */
    private function downloadOnce(string $url, ?string $etag, ?string $lastModified): array
    {
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
        $responseCode = 0;
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
            CURLOPT_FAILONERROR => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => 'MatterhornImport/0.1',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders, &$declaredTooLarge, &$responseCode): int {
                $length = strlen($line);
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];
                    if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $match) === 1) {
                        $responseCode = (int) $match[1];
                    }
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
                if (in_array($name, ['etag', 'last-modified', 'location'], true)) {
                    $responseHeaders[$name] = $value;
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use ($fp, &$received, $contentHash, &$responseCode): int {
                $length = strlen($data);
                if (in_array($responseCode, [301, 302, 303, 307, 308], true)) {
                    return $length;
                }
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
        if (!curl_setopt_array($ch, $options)) {
            curl_close($ch);
            fclose($fp);
            @unlink($tmp);
            throw new \RuntimeException('Could not apply secure image HTTP client options');
        }

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

        if (in_array($code, [301, 302, 303, 307, 308], true)) {
            @unlink($tmp);
            $location = trim((string) ($responseHeaders['location'] ?? ''));
            if ($location === '') {
                throw new \RuntimeException('Image redirect response without Location');
            }
            return ['image' => null, 'redirect' => $location];
        }

        if ($code === 304) {
            @unlink($tmp);
            if ($etag === null && $lastModified === null) {
                throw new \RuntimeException('Unexpected image 304 without validators');
            }
            return ['image' => null, 'redirect' => null];
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

        return [
            'image' => new DownloadedImage(
                $tmp,
                $mime,
                $width,
                $height,
                $received,
                hash_final($contentHash),
                $this->headerValue($responseHeaders, 'etag'),
                $this->headerValue($responseHeaders, 'last-modified')
            ),
            'redirect' => null,
        ];
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
        if (strlen($url) > self::MAX_URL_BYTES) {
            throw new \InvalidArgumentException(
                'Image URL exceeds operational limit of ' . self::MAX_URL_BYTES . ' bytes'
            );
        }
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

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $location = trim($location);
        if ($location === '' || strlen($location) > self::MAX_URL_BYTES) {
            throw new \RuntimeException('Invalid image redirect location');
        }

        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if (!$base || !isset($base['scheme'], $base['host'])) {
            throw new \RuntimeException('Invalid image redirect location');
        }

        $scheme = strtolower((string) $base['scheme']);
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        $host = (string) $base['host'];
        $authorityHost = str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host;
        $authority = $scheme . '://' . $authorityHost;
        if (isset($base['port'])) {
            $authority .= ':' . (int) $base['port'];
        }

        if (str_starts_with($location, '?')) {
            $path = (string) ($base['path'] ?? '/');
            return $authority . ($path === '' ? '/' : $path) . $location;
        }
        if (str_starts_with($location, '#')) {
            $path = (string) ($base['path'] ?? '/');
            $query = isset($base['query']) ? '?' . $base['query'] : '';
            return $authority . ($path === '' ? '/' : $path) . $query . $location;
        }

        $fragment = '';
        $fragmentPos = strpos($location, '#');
        if ($fragmentPos !== false) {
            $fragment = substr($location, $fragmentPos);
            $location = substr($location, 0, $fragmentPos);
        }
        $query = '';
        $queryPos = strpos($location, '?');
        if ($queryPos !== false) {
            $query = substr($location, $queryPos);
            $location = substr($location, 0, $queryPos);
        }

        if (str_starts_with($location, '/')) {
            $path = $location;
        } else {
            $basePath = (string) ($base['path'] ?? '/');
            $slash = strrpos($basePath, '/');
            $directory = $slash === false ? '/' : substr($basePath, 0, $slash + 1);
            $path = $directory . $location;
        }

        return $authority . $this->normalizePath($path) . $query . $fragment;
    }

    private function normalizePath(string $path): string
    {
        $segments = explode('/', $path);
        $normalized = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($normalized);
                continue;
            }
            $normalized[] = $segment;
        }
        return '/' . implode('/', $normalized);
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
