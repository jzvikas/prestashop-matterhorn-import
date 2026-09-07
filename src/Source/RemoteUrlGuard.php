<?php
namespace Lp\MatterhornImport\Source;

/**
 * Resolves remote feed hosts before cURL connects and pins the validated address.
 *
 * This closes the usual SSRF gaps around loopback/private DNS answers and DNS
 * rebinding. Redirects must be processed one hop at a time by the caller and
 * passed through this guard again before any network connection is made.
 */
final class RemoteUrlGuard
{
    /** @return array{url:string,resolve:list<string>} */
    public function prepare(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('Remote source URL is invalid.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException('Remote source URL must use HTTP or HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Credentials are not allowed in the remote source URL.');
        }

        $rawHost = trim((string) ($parts['host'] ?? ''));
        $host = trim($rawHost, '[]');
        if ($host === '') {
            throw new \InvalidArgumentException('Remote source URL host is required.');
        }
        if (strcasecmp(rtrim($host, '.'), 'localhost') === 0) {
            throw new \RuntimeException('Remote source host resolves to a non-public address.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Remote source URL port is invalid.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!$this->isPublicIp($host)) {
                throw new \RuntimeException('Remote source host resolves to a non-public address.');
            }

            return ['url' => $url, 'resolve' => []];
        }

        $addresses = $this->resolveHost($host);
        if ($addresses === []) {
            throw new \RuntimeException('Remote source host could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (!$this->isPublicIp($address)) {
                throw new \RuntimeException('Remote source host resolves to a non-public address.');
            }
        }

        sort($addresses, SORT_STRING);
        $pinned = $addresses[0];
        if (str_contains($pinned, ':')) {
            $pinned = '[' . $pinned . ']';
        }

        return [
            'url' => $url,
            'resolve' => [sprintf('%s:%d:%s', $host, $port, $pinned)],
        ];
    }

    public function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /** @return list<string> */
    private function resolveHost(string $host): array
    {
        $addresses = [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
                    if ($address !== '' && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                        $addresses[$address] = true;
                    }
                }
            }
        }

        if ($addresses === []) {
            $ipv4 = @gethostbynamel($host);
            if (is_array($ipv4)) {
                foreach ($ipv4 as $address) {
                    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                        $addresses[$address] = true;
                    }
                }
            }
        }

        return array_keys($addresses);
    }
}
