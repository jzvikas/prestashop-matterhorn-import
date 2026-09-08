<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workerPath = $root . '/src/NewProduct/NewProductWorker.php';
$queuePath = $root . '/src/Repository/NewProductQueueRepository.php';

$worker = (string) file_get_contents($workerPath);
$queue = (string) file_get_contents($queuePath);

if ($worker === '' || $queue === '') {
    fwrite(STDERR, "FAIL: new-product worker/queue source missing\n");
    exit(1);
}

// This is a generation-safety contract, not a formatting assertion. The queue is
// explicitly allowed to advance id_run/payload while a claimed row remains in
// processing state. Therefore every PrestaShop-induced transaction restoration must
// immediately reacquire the row lock and verify that the worker still owns the exact
// payload generation it started mutating.
foreach ([
    "payload=IF(VALUES(id_run)>=id_run" => 'queue permits newer payload handoff while processing',
    "status=IF(status='processing','processing'" => 'newer enqueue preserves processing ownership',
    'id_run=GREATEST(id_run,VALUES(id_run))' => 'queue permits generation advance while processing',
] as $needle => $label) {
    if (!str_contains($queue, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

if (!str_contains($worker, 'private function restoreAndFenceGeneration(')) {
    fwrite(STDERR, "FAIL: worker lacks post-hook generation fence helper\n");
    exit(1);
}

if (substr_count($worker, '$this->restoreAndFenceGeneration(') !== 4) {
    fwrite(STDERR, "FAIL: every product/feature/combination/specific-price hook boundary must use the generation fence\n");
    exit(1);
}

if (substr_count($worker, '$this->transactionGuard->restoreAfterExternalCommit()') !== 1) {
    fwrite(STDERR, "FAIL: raw external-commit restoration must be confined to the generation-fence helper\n");
    exit(1);
}

$helperStart = strpos($worker, 'private function restoreAndFenceGeneration(');
$transactionStart = strpos($worker, 'private function transactionIsActive(', $helperStart === false ? 0 : $helperStart);
if ($helperStart === false || $transactionStart === false || $helperStart >= $transactionStart) {
    fwrite(STDERR, "FAIL: generation-fence helper boundaries missing\n");
    exit(1);
}
$helper = substr($worker, $helperStart, $transactionStart - $helperStart);

$required = [
    '$this->transactionGuard->restoreAfterExternalCommit()' => 'detect restored transaction',
    '$this->queue->lockOwned($idQueue, $token)' => 'reacquire queue FOR UPDATE lock',
    "(int) (\$lockedJob['id_run'] ?? 0) !== \$expectedRunId" => 'require exact run generation after lock loss',
    "hash_equals(\$sourceKey, (string) (\$lockedJob['source_key'] ?? ''))" => 'require exact source key after lock loss',
    "hash_equals(\$payloadHash, (string) (\$lockedJob['payload_hash'] ?? ''))" => 'require exact payload hash after lock loss',
    'New-product queue generation advanced after external commit' => 'fail closed on newer generation',
];
foreach ($required as $needle => $label) {
    if (!str_contains($helper, $needle)) {
        fwrite(STDERR, "FAIL: generation fence must {$label}\n");
        exit(1);
    }
}

$firstWriter = strpos($worker, '$this->writer->');
$firstFence = strpos($worker, '$this->restoreAndFenceGeneration(', $firstWriter === false ? 0 : $firstWriter);
$featureWrite = strpos($worker, '$this->features->sync(');
if ($firstWriter === false || $firstFence === false || $featureWrite === false || !($firstWriter < $firstFence && $firstFence < $featureWrite)) {
    fwrite(STDERR, "FAIL: worker must fence immediately after the product write before later domain writes\n");
    exit(1);
}

$mappingWrite = strpos($worker, '$this->mapping->save(');
$lastFence = strrpos($worker, '$this->restoreAndFenceGeneration(');
if ($mappingWrite === false || $lastFence === false || $lastFence >= $mappingWrite) {
    fwrite(STDERR, "FAIL: final hook recovery fence must precede mapping/image durability writes\n");
    exit(1);
}

echo "New-product hook-commit generation fence: OK\n";
