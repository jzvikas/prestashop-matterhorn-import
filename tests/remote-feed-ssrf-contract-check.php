<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Source\RemoteUrlGuard;

$guard = new RemoteUrlGuard();

$reject = static function (string $url) use ($guard): void {
    try {
        $guard->prepare($url);
        fwrite(STDERR, "FAIL: non-public remote source must be rejected: {$url}\n");
        exit(1);
    } catch (RuntimeException|InvalidArgumentException $e) {
        if (!str_contains($e->getMessage(), 'non-public') && !str_contains($e->getMessage(), 'Credentials')) {
            fwrite(STDERR, "FAIL: unexpected remote-source rejection: {$e->getMessage()}\n");
            exit(1);
        }
    }
};

foreach ([
    'http://127.0.0.1/feed.xml',
    'http://10.0.0.1/feed.xml',
    'http://169.254.169.254/latest/meta-data/',
    'http://192.168.1.10/feed.xml',
    'http://[::1]/feed.xml',
    'http://[fc00::1]/feed.xml',
    'http://localhost/feed.xml',
    'http://user:pass@93.184.216.34/feed.xml',
] as $url) {
    $reject($url);
}

$public = $guard->prepare('https://93.184.216.34/feed.xml');
if (($public['url'] ?? '') !== 'https://93.184.216.34/feed.xml' || ($public['resolve'] ?? null) !== []) {
    fwrite(STDERR, "FAIL: public IP literal must remain usable without DNS pinning\n");
    exit(1);
}
if (!$guard->isPublicIp('93.184.216.34')) {
    fwrite(STDERR, "FAIL: public IPv4 classification regression\n");
    exit(1);
}
if ($guard->isPublicIp('127.0.0.1') || $guard->isPublicIp('169.254.169.254') || $guard->isPublicIp('::1')) {
    fwrite(STDERR, "FAIL: private/reserved IP classification regression\n");
    exit(1);
}

$materializer = (string) file_get_contents(dirname(__DIR__) . '/src/Source/RemoteFeedMaterializer.php');
foreach ([
    'CURLOPT_FOLLOWLOCATION => false',
    'CURLOPT_PROXY =>',
    'CURLOPT_RESOLVE',
    'CURLINFO_REDIRECT_URL',
    '$this->urlGuard->prepare($currentUrl)',
] as $needle) {
    if (!str_contains($materializer, $needle)) {
        fwrite(STDERR, "FAIL: remote feed SSRF fence missing: {$needle}\n");
        exit(1);
    }
}
if (str_contains($materializer, 'CURLOPT_FOLLOWLOCATION => true')) {
    fwrite(STDERR, "FAIL: cURL must not auto-follow unvalidated remote-feed redirects\n");
    exit(1);
}

echo "Remote feed SSRF contract: OK\n";
