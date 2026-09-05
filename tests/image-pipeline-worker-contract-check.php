<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'src/Image/AttachedImage.php',
    'src/Image/ImageFailureClassifier.php',
    'src/Image/SafeImageDownloader.php',
    'src/Image/PrestaImageProcessor.php',
    'src/Image/ImageWorker.php',
    'src/Image/ImageReconciler.php',
    'src/Repository/ImageQueueRepository.php',
    'src/Repository/ImageOrphanRepository.php',
    'src/Repository/RunRepository.php',
    'src/Command/ImagesCommand.php',
    'src/Command/ImagesReconcileCommand.php',
];
foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) { fwrite(STDERR, "Missing image pipeline file: {$file}\n"); exit(1); }
}

$worker = file_get_contents($root . '/src/Image/ImageWorker.php');
$downloader = file_get_contents($root . '/src/Image/SafeImageDownloader.php');
$queue = file_get_contents($root . '/src/Repository/ImageQueueRepository.php');
$processor = file_get_contents($root . '/src/Image/PrestaImageProcessor.php');
$reconciler = file_get_contents($root . '/src/Image/ImageReconciler.php');
$runs = file_get_contents($root . '/src/Repository/RunRepository.php');
$orphans = file_get_contents($root . '/src/Repository/ImageOrphanRepository.php');
$gc = file_get_contents($root . '/src/Gc/GcService.php');
$snapshots = file_get_contents($root . '/src/Repository/SnapshotRepository.php');
$services = file_get_contents($root . '/config/services.yml');
$imagesCommand = file_get_contents($root . '/src/Command/ImagesCommand.php');
$reconcileCommand = file_get_contents($root . '/src/Command/ImagesReconcileCommand.php');

$checks = [
    [$worker, 'assertTransactionalCore()', 'worker transactional safety'],
    [$worker, 'SourceInterface', 'image worker must resolve active supplier source'],
    [$worker, 'sourceAdapter->name()', 'image worker must resolve source identity at tick start'],
    [$worker, 'queue->claim($worker, $sourceName, $limit, $shopId)', 'image worker claim must be source scoped'],
    [$worker, 'renew($idQueue, $token)', 'lease renewal fencing'],
    [$worker, '$this->queue->lockOwned($idQueue, $token)', 'latest desired queue row must be locked/reloaded before image state commit'],
    [$worker, 'The hook commit released our queue row lock', 'hook-commit path must explicitly reacquire latest queue row'],
    [$worker, 'findByContentHash', 'content deduplication'],
    [$worker, 'GET_LOCK', 'content dedup lock'],
    [$worker, 'failureClassifier->isRetryable', 'retry classification'],
    [$worker, '$this->orphans->record(', 'durable externally committed image orphan marker'],
    [$worker, "'orphan_recorded'", 'orphan recovery metric'],
    [$downloader, 'private const MAX_URL_BYTES = 16384;', 'image URL operational bound'],
    [$downloader, 'strlen($url) > self::MAX_URL_BYTES', 'image URL length guard'],
    [$downloader, 'Image URL exceeds operational limit', 'image URL bound error clarity'],
    [$queue, 'public function claim(string $worker, string $source', 'image queue claims must require source scope'],
    [$queue, 'scopeWhere = " AND source=', 'image claim predicate must include source'],
    [$queue, 'public function retryFailed(string $source', 'image retry must require source scope'],
    [$queue, "WHERE status='failed' AND source='", 'image retry update must recheck source at write time'],
    [$queue, 'function lockOwned', 'queue must expose row-level lease lock'],
    [$queue, 'FOR UPDATE', 'queue desired metadata must be fenced by row lock'],
    [$queue, '$accept = "(VALUES(id_run)>id_run OR (VALUES(id_run)=id_run AND VALUES(source)=source AND VALUES(source_key)=source_key))"', 'same-generation image handoff must not change owner identity'],
    [$queue, '$sameOwner = "(VALUES(source)=source AND VALUES(source_key)=source_key)"', 'image queue lease preservation must use exact source owner identity'],
    [$queue, "locked_by=IF(%s,IF(status='processing' AND %s,locked_by,NULL),locked_by)", 'foreign owner handoff must revoke active worker token'],
    [$queue, "locked_until=IF(%s,IF(status='processing' AND %s,locked_until,NULL),locked_until)", 'foreign owner handoff must revoke active worker lease'],
    [$queue, "status=IF(%s,IF(status='processing' AND %s,'processing','pending'),status)", 'only same-owner newer image generation may preserve processing status'],
    [$queue, 'position=IF(%s,VALUES(position),position)', 'older image generation must not replace desired position'],
    [$queue, 'is_cover=IF(%s,VALUES(is_cover),is_cover)', 'older image generation must not replace desired cover state'],
    [$queue, 'source=IF(%s,VALUES(source),source)', 'accepted image handoff updates source only after lease fencing'],
    [$queue, 'source_key=IF(%s,VALUES(source_key),source_key)', 'accepted image handoff updates source key only after lease fencing'],
    [$queue, 'id_run=GREATEST(id_run,VALUES(id_run))', 'image desired generation must be monotonic'],
    [$queue, 'a newer owner handoff revokes the old worker', 'owner handoff lease revocation must be documented'],
    [$queue, 'function unresolvedForSource', 'reconciliation must fence the entire shop/source queue'],
    [$processor, 'ImageManager::checkImageMemoryLimit($download->path)', 'image resize must honor PrestaShop memory limit guard'],
    [$processor, 'Image exceeds PrestaShop resize memory limit', 'image memory guard error clarity'],
    [$processor, 'associateTo([$shopId], $productId)', 'shop image association'],
    [$processor, 'ImageType::getImagesTypes', 'thumbnail generation'],
    [$processor, 'count($shopRows) !== 1', 'multishop destructive-delete guard'],
    [$processor, 'syncProductPlacement', 'image placement reconciliation'],
    [$reconciler, 'DELETE target FROM', 'multishop image detach must use atomic delete'],
    [$reconciler, 'INNER JOIN `%s` other', 'multishop image detach must guard another shop in same statement'],
    [$reconciler, 'other.id_image=target.id_image AND other.id_shop<>target.id_shop', 'multishop detach other-shop predicate'],
    [$reconciler, 'target.id_product=%d AND target.id_shop=%d', 'multishop detach must target exact product/shop'],
    [$reconciler, 'Only the latest shop/source run may reconcile images', 'latest-run guard'],
    [$reconciler, 'unresolvedForRun', 'current-run unresolved queue guard'],
    [$reconciler, 'unresolvedForSource', 'cross-run source queue guard'],
    [$reconciler, 'image_reconcile_checkpoint', 'resume from persisted checkpoint'],
    [$reconciler, 'imageReconcileCheckpoint($runId, $sourceKey)', 'checkpoint after successful product reconciliation'],
    [$reconciler, '$this->budget->shouldStop()', 'bounded reconciliation stop checks'],
    [$reconciler, '$this->budget->markItem()', 'bounded reconciliation progress accounting'],
    [$reconciler, "imageReconcileFinish(\$runId, \$paused ? 'paused' : 'completed')", 'reconciliation completion state'],
    [$reconciler, "imageReconcileFinish(\$runId, 'failed')", 'reconciliation failure state'],
    [$reconciler, "\$this->errors->add(\$runId, 'image', \$currentSourceKey, \$e)", 'source-scoped reconciliation error logging'],
    [$reconciler, 'statesForProduct', 'module-owned image-state reconciliation'],
    [$reconciler, "last_seen_run_id'] ?? 0) <= 0", 'reconciliation must accept live unchanged state from an earlier run'],
    [$runs, 'function imageReconcileStart', 'run repository reconciliation start state'],
    [$runs, 'function imageReconcileCheckpoint', 'run repository reconciliation checkpoint'],
    [$runs, 'image_reconcile_done=image_reconcile_done+1', 'cumulative reconciliation progress'],
    [$runs, 'function imageReconcileFinish', 'run repository reconciliation finish state'],
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
    [$reconcileCommand, "addOption('max-items'", 'bounded reconcile max-items CLI'],
    [$reconcileCommand, "addOption('time-limit'", 'bounded reconcile time-limit CLI'],
    [$services, 'Lp\\MatterhornImport\\Command\\ImagesCommand:', 'image worker service registration'],
    [$services, 'Lp\\MatterhornImport\\Command\\ImagesReconcileCommand:', 'image reconcile service registration'],
];

foreach ($checks as [$haystack, $needle, $label]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
if (!is_string($downloader) || strpos($downloader, 'strlen($url) > self::MAX_URL_BYTES') > strpos($downloader, 'parse_url($url)')) {
    fwrite(STDERR, "FAIL: image URL length guard must run before URL/network resolution\n");
    exit(1);
}
if (!is_string($processor)) {
    fwrite(STDERR, "FAIL: image processor source unavailable\n");
    exit(1);
}
$memoryGuard = strpos($processor, 'ImageManager::checkImageMemoryLimit($download->path)');
$imageRow = strpos($processor, '$image = new \\Image();');
$firstResize = strpos($processor, 'ImageManager::resize($download->path');
if ($memoryGuard === false || $imageRow === false || $firstResize === false || $memoryGuard >= $imageRow || $imageRow >= $firstResize) {
    fwrite(STDERR, "FAIL: image memory guard must run before Image row creation and resize\n");
    exit(1);
}
if (!is_string($queue)) {
    fwrite(STDERR, "FAIL: image queue source unavailable\n");
    exit(1);
}
$leaseFence = strpos($queue, "locked_by=IF(%s,IF(status='processing' AND %s,locked_by,NULL),locked_by)");
$sourceAssignment = strpos($queue, 'source=IF(%s,VALUES(source),source)');
$runAssignment = strpos($queue, 'id_run=GREATEST(id_run,VALUES(id_run))');
if ($leaseFence === false || $sourceAssignment === false || $runAssignment === false || $leaseFence >= $sourceAssignment || $sourceAssignment >= $runAssignment) {
    fwrite(STDERR, "FAIL: image owner/generation lease predicates must evaluate before source and id_run assignment\n");
    exit(1);
}
if (str_contains($queue, 'id_run=VALUES(id_run),source=VALUES(source)')) {
    fwrite(STDERR, "FAIL: stale image enqueue must not unconditionally replace desired generation metadata\n");
    exit(1);
}
if (str_contains((string) $reconciler, 'SELECT COUNT(*) FROM `%simage_shop` WHERE id_image=%d')) {
    fwrite(STDERR, "FAIL: image detach must not rely on a racy pre-delete shop count\n");
    exit(1);
}
if (str_contains((string) $reconciler, "last_seen_run_id'] !== \$runId")) {
    fwrite(STDERR, "FAIL: unchanged image states must not require current-run freshness\n");
    exit(1);
}

echo "Image pipeline worker contract: OK\n";
