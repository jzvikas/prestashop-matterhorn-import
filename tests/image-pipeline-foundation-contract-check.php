<?php
$root = dirname(__DIR__);
$downloader = file_get_contents($root . '/src/Image/SafeImageDownloader.php');
$queue = file_get_contents($root . '/src/Repository/ImageQueueRepository.php');
$state = file_get_contents($root . '/src/Repository/ImageStateRepository.php');

$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};

$assert(str_contains($downloader, 'CURLOPT_FOLLOWLOCATION => false'), 'image downloader must forbid redirects');
$assert(str_contains($downloader, "CURLOPT_PROXY => ''"), 'image downloader must disable proxy inheritance');
$assert(str_contains($downloader, 'FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE'), 'image downloader must block private/reserved IPs');
$assert(str_contains($downloader, 'CURLOPT_RESOLVE'), 'image downloader must pin validated DNS resolution');
$assert(str_contains($downloader, 'CURLINFO_PRIMARY_IP'), 'image downloader must verify connected IP');
$assert(str_contains($downloader, 'MAX_BYTES = 26214400'), 'image downloader must retain byte limit');
$assert(str_contains($downloader, 'MAX_PIXELS = 80000000'), 'image downloader must retain pixel limit');
$assert(str_contains($queue, 'public function renew('), 'image queue must support lease renewal');
$assert(str_contains($queue, "locked_by='%s' AND locked_until>NOW()"), 'image queue completion/failure must be lease fenced');
$assert(str_contains($queue, 'enqueueBatch'), 'image queue must support bounded batch enqueue');
$assert(str_contains($queue, 'li_matterhornim_99dfbf_image_queue'), 'image queue must use Matterhorn table identity');
$assert(str_contains($state, 'li_matterhornim_99dfbf_image_state'), 'image state must use Matterhorn table identity');
$assert(str_contains($state, 'INNER JOIN `%simage_shop`'), 'image state reuse must be shop scoped');
$assert(!str_contains($downloader, 'file_get_contents('), 'image downloading must remain streaming/curl based');

echo "Image pipeline foundation contract checks: OK\n";
