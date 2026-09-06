<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'src/Image/ImageRevalidationScheduler.php',
    'src/Repository/ImageStateRepository.php',
    'src/Repository/SnapshotRepository.php',
    'src/Repository/ImageQueueRepository.php',
    'src/Command/ImagesRevalidateCommand.php',
    'src/Installer.php',
    'sql/install.sql',
    'config/services.yml',
];
foreach ($files as $file) {
    if (!is_file($root . '/' . $file)) { fwrite(STDERR, "Missing image revalidation file: {$file}\n"); exit(1); }
}

$scheduler = (string) file_get_contents($root . '/src/Image/ImageRevalidationScheduler.php');
$state = (string) file_get_contents($root . '/src/Repository/ImageStateRepository.php');
$snapshots = (string) file_get_contents($root . '/src/Repository/SnapshotRepository.php');
$command = (string) file_get_contents($root . '/src/Command/ImagesRevalidateCommand.php');
$installer = (string) file_get_contents($root . '/src/Installer.php');
$install = (string) file_get_contents($root . '/sql/install.sql');
$services = (string) file_get_contents($root . '/config/services.yml');

$checks = [
    [$scheduler, "image_reconcile_status", 'latest run must be reconciled before stale revalidation'],
    [$scheduler, "completed image reconciliation", 'reconciliation completion failure message'],
    [$scheduler, '$this->lock->acquire($shopId, $source, 0)', 'scheduler shares import lock'],
    [$scheduler, '$this->state->staleSourceKeys', 'bounded stale-state discovery'],
    [$scheduler, '$this->snapshots->imageManifestSourceKeysForSourceKeys', 'manifest availability preflight'],
    [$scheduler, '$this->snapshots->imageManifestRowsForSourceKeys', 'latest manifest lookup'],
    [$scheduler, 'Stale image states have no matching latest-run snapshot manifest', 'missing snapshot manifest must fail closed'],
    [$scheduler, 'Stale image state is missing from latest-run snapshot manifest', 'missing source key must fail closed'],
    [$scheduler, 'array_fill_keys($availableKeys, true)', 'availability lookup must fence every stale source key before payload deferral'],
    [$scheduler, 'strcmp($key, $lastReturnedKey) > 0', 'only keys beyond bounded payload cursor may defer after availability preflight'],
    [$scheduler, '$this->queue->enqueueBatch', 'existing secure image queue reuse'],
    [$scheduler, 'payload_window_deferred', 'bounded payload-window visibility'],
    [$state, 'MAX_STALE_SCAN_ROWS = 50000', 'hard stale-state DB row scan cap'],
    [$state, '$scanLimit = min(self::MAX_STALE_SCAN_ROWS', 'stale-state scan cap must be applied'],
    [$state, 'updated_at<=DATE_SUB(NOW(),INTERVAL %d HOUR)', 'age-based stale selection'],
    [$state, 'm.out_of_feed=0', 'revalidation excludes out-of-feed products'],
    [$state, 'q.id_shop=s.id_shop AND q.source=s.source AND q.source_key=s.source_key AND q.id_product=s.id_product', 'unresolved-job fence must match the complete source owner identity'],
    [$state, "q.status<>'done'", 'revalidation avoids unresolved image work for the same source owner'],
    [$state, 'ORDER BY s.updated_at ASC,s.source_key ASC LIMIT %d', 'stale scan must stop on ordered index range'],
    [$snapshots, 'function imageManifestSourceKeysForSourceKeys', 'bounded manifest availability lookup'],
    [$snapshots, 'if (count($keys) > 5000)', 'availability lookup hard key cap'],
    [$snapshots, 'array_chunk($keys, 500)', 'availability lookup must chunk SQL IN lists'],
    [$snapshots, 'function imageManifestRowsForSourceKeys', 'bounded keyed manifest lookup'],
    [$snapshots, 'MAX_FETCH_PAYLOAD_BYTES', 'manifest payload memory bound'],
    [$command, "parent::__construct('matterhornimport:images:revalidate')", 'image revalidate command name'],
    [$command, "'age-hours'", 'image revalidate age option'],
    [$command, "'limit'", 'image revalidate product limit'],
    [$services, 'Lp\\MatterhornImport\\Command\\ImagesRevalidateCommand:', 'image revalidate service registration'],
    [$installer, "'idx_revalidate' => '(`id_shop`,`source`,`updated_at`,`source_key`)'", 'revalidation performance index ensure'],
    [$install, 'KEY `idx_revalidate` (`id_shop`,`source`,`updated_at`,`source_key`)', 'fresh revalidation performance index'],
];

foreach ($checks as [$haystack, $needle, $label]) {
    if (!str_contains($haystack, $needle)) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
$availabilityPos = strpos($scheduler, '$this->snapshots->imageManifestSourceKeysForSourceKeys');
$payloadPos = strpos($scheduler, '$this->snapshots->imageManifestRowsForSourceKeys');
if ($availabilityPos === false || $payloadPos === false || $availabilityPos >= $payloadPos) {
    fwrite(STDERR, "FAIL: manifest availability must be proven before bounded payload lookup\n");
    exit(1);
}
if (str_contains($state, 'GROUP BY s.source_key')) {
    fwrite(STDERR, "FAIL: stale-state hot query must not GROUP BY the entire stale range\n");
    exit(1);
}

echo "Image revalidation contract: OK\n";
