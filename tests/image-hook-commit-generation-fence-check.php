<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$worker = (string) file_get_contents($root . '/src/Image/ImageWorker.php');
$queue = (string) file_get_contents($root . '/src/Repository/ImageQueueRepository.php');

if ($worker === '' || $queue === '') {
    fwrite(STDERR, "FAIL: image generation fence sources unavailable\n");
    exit(1);
}

foreach ([
    '$expectedRunId = (int) ($row[\'id_run\'] ?? 0);' => 'claimed image generation must be captured',
    '$expectedRunId = $this->assertQueueGeneration($row, $expectedRunId, false);' => 'worker must adopt a newer generation only before physical attach/state persistence',
    '$this->assertQueueGeneration($row, $expectedRunId, true);' => 'hook-commit recovery must require the exact generation used for attach',
    'Image queue generation advanced after PrestaShop hook commit' => 'generation advancement must fail closed after an external commit',
    "'generation_requeued'=>\$generationRequeued" => 'worker metrics must expose newer-generation requeue recovery',
] as $needle => $label) {
    if (!str_contains($worker, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

foreach ([
    'public function fail(int $id, string $token, string $error, bool $retryable = true, int $expectedRunId = 0): bool' => 'image queue failure API must accept an expected generation',
    '$runFence = $expectedRunId > 0 ? \' AND id_run=\' . $expectedRunId : \'\';' => 'image queue failure update must fence id_run',
    'requeueNewerGeneration($id, $token, $expectedRunId)' => 'newer image generation must be immediately requeued when a stale worker loses the fence',
    "status='pending',attempts=0,available_at=NULL,last_error=NULL,locked_by=NULL,locked_until=NULL" => 'newer generation requeue must reset stale lease and retry budget',
    'AND id_run>%d' => 'newer-generation recovery must be monotonic',
] as $needle => $label) {
    if (!str_contains($queue, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

$hookBranch = strpos($worker, 'if (!$this->transactionIsActive($db))');
$relock = $hookBranch === false ? false : strpos($worker, '$row = $this->queue->lockOwned($idQueue, $token);', $hookBranch);
$generationFence = $relock === false ? false : strpos($worker, '$this->assertQueueGeneration($row, $expectedRunId, true);', $relock);
$mappingFence = $generationFence === false ? false : strpos($worker, '$this->assertLockedMappingOwnership($row);', $generationFence);
$stateSave = $mappingFence === false ? false : strpos($worker, '$this->state->save($row, $idImage, $download);', $mappingFence);
if ($hookBranch === false || $relock === false || $generationFence === false || $mappingFence === false || $stateSave === false) {
    fwrite(STDERR, "FAIL: hook-commit recovery ordering must be relock -> exact generation fence -> mapping fence -> state save\n");
    exit(1);
}

$failureCall = strpos($worker, '$failureApplied = $this->queue->fail(');
$failureRunFence = $failureCall === false ? false : strpos($worker, '$expectedRunId', $failureCall);
if ($failureCall === false || $failureRunFence === false) {
    fwrite(STDERR, "FAIL: image failure transition must pass the processed run generation into the queue fence\n");
    exit(1);
}

echo "Image hook-commit generation fence contract: OK\n";
