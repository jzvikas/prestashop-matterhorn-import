<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'src/Image/AttachedImage.php',
    'src/Image/ImageFailureClassifier.php',
    'src/Image/PrestaImageProcessor.php',
    'src/Image/ImageWorker.php',
    'src/Image/ImageReconciler.php',
    'src/Repository/ImageQueueRepository.php',
    'src/Repository/ImageOrphanRepository.php',
    'src/Command/ImagesCommand.php',
    'src/Command/ImagesReconcileCommand.php',
];
foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) {
        fwrite(STDERR, "Missing image pipeline file: {$file}\n");
        exit(1);
    }
}

$worker = file_get_contents($root . '/src/Image/ImageWorker.php');
$queue = file_get_contents($root . '/src/Repository/ImageQueueRepository.php');
$processor = file_get_contents($root . '/src/Image/PrestaImageProcessor.php');
$reconciler = file_get_contents($root . '/src/Image/ImageReconciler.php');
$orphans = file_get_contents($root . '/src/Repository/ImageOrphanRepository.php');
$gc = file_get_contents($root . '/src/Gc/GcService.php');
$snapshots = file_get_contents($root . '/src/Repository/SnapshotRepository.php');
$services = file_get_contents($root . '/config/services.yml');
$imagesCommand = file_get_contents($root . '/src/Command/ImagesCommand.php');
$reconcileCommand = file_get_contents($root . '/src/Command/ImagesReconcileCommand.php');

$checks = [
    [$worker, 'assertTransactionalCore()', 'worker transactional safety'],
    [$worker, 'renew($idQueue, $token)', 'lease renewal fencing'],
    [$worker, '$this->queue->lockOwned($idQueue, $token)', 'latest desired queue row must be locked/reloaded before image state commit'],
    [$worker, 'The hook commit released our queue row lock', 'hook-commit path must explicitly reacquire latest queue row'],
    [$worker, 'findByContentHash', 'content deduplication'],
    [$worker, 'GET_LOCK', 'content dedup lock'],
    [$worker, 'failureClassifier->isRetryable', 'retry classification'],
    [$worker, '$this->orphans->record(', 'durable externally committed image orphan marker'],
    [$worker, "'orphan_recorded'", 'orphan recovery metric'],
    [$queue, 'function lockOwned', 'queue must expose row-level lease lock'],
    [$queue, 'FOR UPDATE', 'queue desired metadata must be fenced by row lock'],
    [$queue, 'id_run=VALUES(id_run)', 'newer run must supersede queued desired run metadata'],
    [$queue, 'position=VALUES(position)', 'newer run must supersede desired image position'],
    [$queue, "status=IF(status='processing','processing','pending')", 'processing lease must survive desired-run supersession while non-processing rows are requeued'],
    [$processor, 'associateTo([$shopId], $productId)', 'shop image association'],
    [$processor, 'ImageType::getImagesTypes', 'thumbnail generation'],
    [$processor, 'count($shopRows) !== 1', 'multishop destructive-delete guard'],
    [$processor, 'syncProductPlacement', 'image placement reconciliation'],
    [$reconciler, 'Only the latest shop/source run may reconcile images', 'latest-run guard'],
    [$reconciler, 'unresolvedForRun', 'unresolved queue guard'],
    [$reconciler, 'statesForProduct', 'module-owned image-state reconciliation'],
    [$reconciler, "last_seen_run_id'] !== $runId", 'reconciliation must require current-run desired image state'],
    [$orphans, 'available_at', 'orphan recovery backoff'],
    [$orphans, 'function defer', 'orphan retry deferral'],
    [$gc, 'drainImageOrphans', 'GC orphan recovery lane'],
    [$gc, 'NOT EXISTS', 'queue GC orphan retention guard'],
    [$gc, 'image_orphans_deferred', 'unsafe orphan deletion deferral'],
    [$snapshots, 'function imageManifestRows', 'bounded image manifest pagination'],
    [$snapshots, 'MAX_FETCH_PAYLOAD_BYTES', 'payload window bound'],
    [$imagesCommand, "parent::__construct('matterhornimport:images')", 'image worker command name'],
    [$imagesCommand, "'orphan_record_failed'", 'image CLI orphan marker failure visibility'],
    [$reconcileCommand, "parent::__construct('matterhornimport:images:reconcile')", 'image reconcile command name'],
    [$services, 'Lp\\MatterhornImport\\Command\\ImagesCommand:', 'image worker service registration'],
    [$services, 'Lp\\MatterhornImport\\Command\\ImagesReconcileCommand:', 'image reconcile service registration'],
];

foreach ($checks as [$haystack, $needle, $label]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "Image pipeline worker contract: OK\n";
