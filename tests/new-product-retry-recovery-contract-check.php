<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workerPath = $root . '/src/NewProduct/NewProductWorker.php';
$queuePath = $root . '/src/Repository/NewProductQueueRepository.php';

if (!is_file($workerPath) || !is_file($queuePath)) {
    fwrite(STDERR, "FAIL: new-product retry recovery sources are missing\n");
    exit(1);
}

$worker = (string) file_get_contents($workerPath);
$queue = (string) file_get_contents($queuePath);

if (!str_contains($queue, "SET status='pending',attempts=0")) {
    fwrite(STDERR, "FAIL: contract assumes operator retry grants a fresh attempt budget by resetting attempts\n");
    exit(1);
}

$mappingPos = strpos($worker, '$idProduct = $this->mapping->findProductId(');
$recoveryPos = strpos($worker, '$idProduct = $this->createRecovery->findRecoverable(');
$createPos = strpos($worker, '$idProduct = $this->writer->create(');
if ($mappingPos === false || $recoveryPos === false || $createPos === false || !($mappingPos < $recoveryPos && $recoveryPos < $createPos)) {
    fwrite(STDERR, "FAIL: unmapped new-product flow must attempt interrupted-create recovery before Product creation\n");
    exit(1);
}

$unmappedStart = strpos($worker, '} else {', $mappingPos);
$restorePos = strpos($worker, '$this->transactionGuard->restoreAfterExternalCommit();', $createPos);
if ($unmappedStart === false || $restorePos === false || $unmappedStart >= $restorePos) {
    fwrite(STDERR, "FAIL: could not isolate unmapped new-product persistence flow\n");
    exit(1);
}
$unmappedFlow = substr($worker, $unmappedStart, $restorePos - $unmappedStart);

if (str_contains($unmappedFlow, "['attempts']") || str_contains($unmappedFlow, '> 1')) {
    fwrite(STDERR, "FAIL: interrupted-create recovery must not be gated by the resettable queue attempt counter\n");
    exit(1);
}
if (!str_contains($unmappedFlow, "run['started_at']") || !str_contains($unmappedFlow, 'New-product recovery cannot resolve source run start time')) {
    fwrite(STDERR, "FAIL: recovery must remain fenced to the source run start time\n");
    exit(1);
}
if (!str_contains($unmappedFlow, '$stats[\'recovered\']++')) {
    fwrite(STDERR, "FAIL: recovered interrupted creates must remain observable\n");
    exit(1);
}

echo "New-product retry recovery contract: OK\n";
