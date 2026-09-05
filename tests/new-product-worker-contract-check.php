<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'src/Repository/NewProductQueueRepository.php',
    'src/Repository/SpecificPriceStateRepository.php',
    'src/SpecificPrice/SpecificPriceSynchronizer.php',
    'src/NewProduct/NewProductWorker.php',
    'src/Command/NewProductsEnqueueCommand.php',
    'src/Command/NewProductsCommand.php',
];
foreach ($files as $file) {
    if (!is_file($root . '/' . $file)) { fwrite(STDERR, "Missing new-product file: {$file}\n"); exit(1); }
}

$queue = file_get_contents($root . '/src/Repository/NewProductQueueRepository.php');
$worker = file_get_contents($root . '/src/NewProduct/NewProductWorker.php');
$enqueue = file_get_contents($root . '/src/Command/NewProductsEnqueueCommand.php');
$command = file_get_contents($root . '/src/Command/NewProductsCommand.php');
$services = file_get_contents($root . '/config/services.yml');
$specific = file_get_contents($root . '/src/SpecificPrice/SpecificPriceSynchronizer.php');

$checks = [
    [$queue, "private const TABLE = 'li_matterhornim_99dfbf_new_product_queue'", 'module-owned new-product queue'],
    [$queue, 'locked_until', 'lease fencing'],
    [$queue, 'GREATEST(id_run,VALUES(id_run))', 'newer queue generation ownership'],
    [$queue, "payload=IF(VALUES(id_run)>=id_run", 'newer payload handoff'],
    [$queue, 'expectedRunId', 'generation-aware finalizer fencing'],
    [$queue, 'requeueNewerGeneration', 'newer generation requeue'],
    [$queue, 'id_run>%d', 'newer run comparison'],
    [$queue, 'TIMESTAMPADD(SECOND', 'retry backoff'],
    [$worker, 'InterruptedCreateRecovery', 'interrupted-create recovery'],
    [$worker, 'assertTransactionalCore()', 'transactional DB safety'],
    [$worker, 'lock->acquire', 'shop/source lock'],
    [$worker, '$expectedRunId = (int) $job', 'worker generation capture'],
    [$worker, 'existing_updated', 'latest generation must update existing mapping'],
    [$worker, 'generation_requeued', 'generation handoff metrics'],
    [$worker, 'writer->update($idProduct, $product, $jobShop)', 'existing product receives latest payload'],
    [$worker, 'done($idQueue, $token, $idProduct, $expectedRunId)', 'completion generation fence'],
    [$worker, 'fail($idQueue, $token, $e->getMessage(), $retryable, $expectedRunId)', 'failure generation fence'],
    [$worker, 'transactionIsActive', 'hook transaction recovery'],
    [$worker, 'combinationAttributes->resolve', 'Size/combo attribute resolution'],
    [$worker, 'images->enqueue', 'separate image pipeline'],
    [$worker, 'TransientDatabaseFailure::isRetryable', 'transient retry classification'],
    [$specific, "array_key_exists('specific_prices'", 'specific-price no-op unless explicitly supplied'],
    [$enqueue, "parent::__construct('matterhornimport:new-products:enqueue')", 'enqueue command name'],
    [$enqueue, "remove_status'] !== 'pending'", 'enqueue/remove safety gate'],
    [$command, "parent::__construct('matterhornimport:new-products')", 'worker command name'],
    [$command, "'generation_requeued'=>0", 'CLI generation requeue visibility'],
    [$command, "'existing_updated'=>0", 'CLI latest-payload update visibility'],
    [$services, 'Lp\\MatterhornImport\\Command\\NewProductsEnqueueCommand:', 'enqueue command service registration'],
    [$services, 'Lp\\MatterhornImport\\Command\\NewProductsCommand:', 'worker command service registration'],
];

foreach ($checks as [$haystack, $needle, $label]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "New-product worker contract: OK\n";
