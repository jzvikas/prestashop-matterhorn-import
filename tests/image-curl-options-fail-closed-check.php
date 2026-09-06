<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/src/Image/SafeImageDownloader.php';
$source = is_file($path) ? file_get_contents($path) : false;
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: safe image downloader source unavailable\n");
    exit(1);
}

$setup = strpos($source, 'if (!curl_setopt_array($ch, $options))');
$exec = strpos($source, '$ok = curl_exec($ch);');
$close = strpos($source, 'curl_close($ch);', $setup === false ? 0 : $setup);
$unlink = strpos($source, '@unlink($tmp);', $setup === false ? 0 : $setup);
$error = strpos($source, 'Could not apply secure image HTTP client options');

if ($setup === false || $exec === false || $setup >= $exec) {
    fwrite(STDERR, "FAIL: image downloader must fail closed when secure cURL option setup fails before network execution\n");
    exit(1);
}
if ($close === false || $close > $exec || $unlink === false || $unlink > $exec || $error === false || $error > $exec) {
    fwrite(STDERR, "FAIL: failed cURL option setup must close resources, remove temp file and raise an explicit error\n");
    exit(1);
}
foreach (['CURLOPT_FOLLOWLOCATION => false','CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS',"CURLOPT_PROXY => ''",'CURLOPT_SSL_VERIFYPEER => true','CURLOPT_SSL_VERIFYHOST => 2','CURLOPT_RESOLVE'] as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: missing secure cURL option: {$needle}\n");
        exit(1);
    }
}

echo "Image cURL option fail-closed contract: OK\n";