<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$state = file_get_contents($root . '/src/Repository/ImageStateRepository.php');
$worker = file_get_contents($root . '/src/Image/ImageWorker.php');

$checks = [
    [$worker, 'touchNotModified($row, (int) $prior[\'id_image\'])', '304 worker path must refresh persisted state before marking the queue done'],
    [$state, 'UPDATE `%s` s INNER JOIN `%s` i', '304 state refresh must require a live product image row'],
    [$state, 'INNER JOIN `%s` ish ON ish.id_image=s.id_image AND ish.id_shop=s.id_shop', '304 state refresh must require the target-shop image association'],
    [$state, 'FOR UPDATE', '304 state refresh must lock and verify the live state before commit'],
    [$state, 'Image state revalidation lost its live PrestaShop image association', 'missing/replaced 304 state must fail closed'],
];

foreach ($checks as [$haystack, $needle, $label]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

$touchPos = strpos((string) $worker, 'touchNotModified($row, (int) $prior[\'id_image\'])');
$donePos = strpos((string) $worker, '$this->queue->done($idQueue, $token)', $touchPos === false ? 0 : $touchPos);
if ($touchPos === false || $donePos === false || $touchPos > $donePos) {
    fwrite(STDERR, "FAIL: 304 state verification must happen before queue completion\n");
    exit(1);
}

echo "Image 304 race contract: OK\n";
