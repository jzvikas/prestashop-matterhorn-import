<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$downloader = (string) file_get_contents($root . '/src/Image/SafeImageDownloader.php');
$worker = (string) file_get_contents($root . '/src/Image/ImageWorker.php');
$processor = (string) file_get_contents($root . '/src/Image/PrestaImageProcessor.php');
$queue = (string) file_get_contents($root . '/src/Repository/ImageQueueRepository.php');
$state = (string) file_get_contents($root . '/src/Repository/ImageStateRepository.php');
$command = (string) file_get_contents($root . '/src/Command/ImagesCommand.php');
$checks = [
    [$downloader, 'CURLOPT_FOLLOWLOCATION => false'],
    [$downloader, 'CURLOPT_RESOLVE'],
    [$downloader, 'FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE'],
    [$downloader, 'MAX_BYTES = 26214400'],
    [$downloader, 'MAX_PIXELS = 80000000'],
    [$downloader, "['image/jpeg', 'image/png', 'image/webp']"],
    [$worker, 'contentLockName'],
    [$worker, 'hook_commit_recoveries'],
    [$worker, 'findByContentHash'],
    [$worker, 'touchNotModified'],
    [$processor, 'count($shopRows) !== 1'],
    [$queue, 'locked_until>NOW()'],
    [$queue, 'CASE attempts WHEN 1 THEN 15 WHEN 2 THEN 30'],
    [$state, 'li_matterhornim_99dfbf_image_state'],
    [$command, 'matterhornimport:images'],
];
foreach ($checks as [$haystack, $needle]) {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException('Image worker contract missing: ' . $needle);
    }
}
echo "Image worker contract: OK\n";
