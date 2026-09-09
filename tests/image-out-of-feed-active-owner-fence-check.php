<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$mapping = (string) file_get_contents($root . '/src/Repository/MappingRepository.php');
$queue = (string) file_get_contents($root . '/src/Repository/ImageQueueRepository.php');
$worker = (string) file_get_contents($root . '/src/Image/ImageWorker.php');
$reconciler = (string) file_get_contents($root . '/src/Image/ImageReconciler.php');

$checks = [
    [$mapping, 'public function ownsActiveProduct(', 'active mapping read API'],
    [$mapping, 'public function lockActiveProductOwnership(', 'active mapping row-lock API'],
    [$mapping, 'AND out_of_feed=0', 'active mapping must exclude out-of-feed rows'],
    [$queue, "private const MAPPING_TABLE = 'li_matterhornim_99dfbf_mapping';", 'queue active mapping table contract'],
    [$queue, 'private function claimRows(', 'active-first queue claim helper'],
    [$queue, 'm.id_shop=q.id_shop AND m.source=q.source AND m.source_key=q.source_key AND m.id_product=q.id_product AND m.out_of_feed=0', 'queue exact active-owner claim fence'],
    [$worker, '$this->mapping->ownsActiveProduct(', 'worker pre-download active ownership fence'],
    [$worker, '$this->mapping->lockActiveProductOwnership(', 'worker locked persistence active ownership fence'],
    [$worker, 'active mapping no longer owns queued product', 'worker out-of-feed supersede reason'],
    [$reconciler, 'private function unresolvedForActiveMappings(', 'active reconciliation queue fence'],
    [$reconciler, 'm.id_shop=q.id_shop AND m.source=q.source', 'queue/mapping shop/source identity join'],
    [$reconciler, 'm.source_key=q.source_key AND m.id_product=q.id_product AND m.out_of_feed=0', 'queue/mapping exact active-owner join'],
    [$reconciler, "q.status<>'done'", 'only unresolved active jobs block reconciliation'],
];
foreach ($checks as [$haystack, $needle, $label]) {
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

$claimStart = strpos($queue, 'public function claim(string $worker, string $source');
$renewStart = strpos($queue, 'public function renew(int $id, string $token): bool');
if ($claimStart === false || $renewStart === false || $claimStart >= $renewStart) {
    fwrite(STDERR, "FAIL: image active-first claim method boundaries missing\n");
    exit(1);
}
$claim = substr($queue, $claimStart, $renewStart - $claimStart);
$activeClaim = strpos($claim, '$this->claimRows($token, $source, $limit, $shopId, true)');
$fallbackClaim = strpos($claim, '$this->claimRows($token, $source, $limit, $shopId, false)');
if ($activeClaim === false || $fallbackClaim === false || $activeClaim >= $fallbackClaim) {
    fwrite(STDERR, "FAIL: active image rows must be claimed before stale cleanup fallback\n");
    exit(1);
}
if (!str_contains($claim, 'AND EXISTS (SELECT 1 FROM `%s%s` m')) {
    fwrite(STDERR, "FAIL: image claim must fence active rows atomically inside the claim UPDATE\n");
    exit(1);
}
if (!str_contains($claim, "UPDATE `%s%s` q SET status='processing'")) {
    fwrite(STDERR, "FAIL: active image claim UPDATE must expose queue alias for exact-owner correlation\n");
    exit(1);
}

$methodStart = strpos($reconciler, 'private function unresolvedForActiveMappings(');
$methodEnd = $methodStart === false ? false : strpos($reconciler, "\n    /**", $methodStart + 1);
$method = $methodStart === false
    ? ''
    : substr($reconciler, $methodStart, $methodEnd === false ? null : $methodEnd - $methodStart);
if ($method === '' || !preg_match('/getValue\(\s*.*?,\s*false\s*\)\s*;/s', $method)) {
    fwrite(STDERR, "FAIL: active queue readiness lookup must bypass PrestaShop query cache\n");
    exit(1);
}

$initialFence = strpos($worker, 'if (!$this->mappingMatches($row))');
$download = strpos($worker, '$this->downloader->download(');
if ($initialFence === false || $download === false || $initialFence >= $download) {
    fwrite(STDERR, "FAIL: active ownership must be checked before image download\n");
    exit(1);
}

$lockedFence = strpos($worker, '$this->assertLockedMappingOwnership($row)');
$touch304 = strpos($worker, '$this->state->touchNotModified(');
$stateSave = strpos($worker, '$this->state->save(');
if ($lockedFence === false || $touch304 === false || $stateSave === false || $lockedFence >= $touch304 || $lockedFence >= $stateSave) {
    fwrite(STDERR, "FAIL: locked active ownership must precede every image-state persistence path\n");
    exit(1);
}

if (str_contains($reconciler, '$this->queue->unresolvedForSource(')
    || str_contains($reconciler, '$this->queue->unresolvedForRun(')
) {
    fwrite(STDERR, "FAIL: out-of-feed retained queue rows must not block authoritative reconciliation\n");
    exit(1);
}

$assertReadyStart = strpos($reconciler, 'private function assertReady(');
$activeMethodStart = strpos($reconciler, 'private function unresolvedForActiveMappings(');
if ($assertReadyStart === false || $activeMethodStart === false || $assertReadyStart >= $activeMethodStart) {
    fwrite(STDERR, "FAIL: image reconciliation readiness method boundaries missing\n");
    exit(1);
}
$assertReadyBody = substr($reconciler, $assertReadyStart, $activeMethodStart - $assertReadyStart);
if (!str_contains($assertReadyBody, '$this->unresolvedForActiveMappings($shopId, $source, $runId)')
    || !str_contains($assertReadyBody, '$this->unresolvedForActiveMappings($shopId, $source)')
) {
    fwrite(STDERR, "FAIL: assertReady must fence current-run and cross-run active unresolved jobs\n");
    exit(1);
}

$firstReadyCall = strpos($reconciler, '$this->assertReady($runId, $shopId, $source);');
$secondReadyCall = $firstReadyCall === false
    ? false
    : strpos($reconciler, '$this->assertReady($runId, $shopId, $source);', $firstReadyCall + 1);
$reconcileStart = strpos($reconciler, '$this->runs->imageReconcileStart($runId)');
if ($firstReadyCall === false || $secondReadyCall === false || $reconcileStart === false
    || $firstReadyCall >= $reconcileStart || $secondReadyCall >= $reconcileStart
) {
    fwrite(STDERR, "FAIL: active unresolved queue readiness must be rechecked before reconciliation starts\n");
    exit(1);
}

echo "Image out-of-feed active-owner fence contract: OK\n";
