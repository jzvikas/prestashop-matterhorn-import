<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'src/Repository/NewProductQueueRepository.php',
    'src/Repository/RunRepository.php',
    'src/Repository/SpecificPriceStateRepository.php',
    'src/SpecificPrice/SpecificPriceSynchronizer.php',
    'src/NewProduct/NewProductWorker.php',
    'src/Command/NewProductsEnqueueCommand.php',
    'src/Command/NewProductsCommand.php',
    'src/Util/ItemTransactionGuard.php',
];
foreach ($files as $file) {
    if (!is_file($root . '/' . $file)) { fwrite(STDERR, "Missing new-product file: {$file}\n"); exit(1); }
}

$queue = (string) file_get_contents($root . '/src/Repository/NewProductQueueRepository.php');
$runs = (string) file_get_contents($root . '/src/Repository/RunRepository.php');
$worker = (string) file_get_contents($root . '/src/NewProduct/NewProductWorker.php');
$enqueue = (string) file_get_contents($root . '/src/Command/NewProductsEnqueueCommand.php');
$command = (string) file_get_contents($root . '/src/Command/NewProductsCommand.php');
$services = (string) file_get_contents($root . '/config/services.yml');
$specific = (string) file_get_contents($root . '/src/SpecificPrice/SpecificPriceSynchronizer.php');
$guard = (string) file_get_contents($root . '/src/Util/ItemTransactionGuard.php');

$checks = [
    [$queue, "private const TABLE = 'li_matterhornim_99dfbf_new_product_queue'", 'module-owned new-product queue'],
    [$queue, 'locked_until', 'lease fencing'],
    [$queue, 'public function claim(string $worker, string $source', 'new-product claims must require source scope'],
    [$queue, 'scopeWhere = " AND source=', 'new-product claim predicate must include source'],
    [$queue, 'public function retryFailed(string $source', 'new-product retry must require source scope'],
    [$queue, "WHERE status='failed' AND source='", 'new-product retry update must recheck source at write time'],
    [$queue, 'public function lockOwned(int $id, string $token)', 'new-product worker must lock/reload the claimed row'],
    [$queue, 'FOR UPDATE', 'new-product generation/payload fence must use a row lock'],
    [$queue, 'public function supersede(int $id, string $token, int $expectedRunId', 'stale generation must have an exact supersede path'],
    [$queue, 'id_run=%d AND status=\'processing\'', 'stale supersede must fence exact run generation'],
    [$queue, 'Affected_Rows() !== 1', 'stale supersede must verify exact ownership at write time'],
    [$queue, 'GREATEST(id_run,VALUES(id_run))', 'newer queue generation ownership'],
    [$queue, "payload=IF(VALUES(id_run)>=id_run", 'newer payload handoff'],
    [$queue, 'expectedRunId', 'generation-aware finalizer fencing'],
    [$queue, 'requeueNewerGeneration', 'newer generation requeue'],
    [$queue, 'id_run>%d', 'newer run comparison'],
    [$queue, 'TIMESTAMPADD(SECOND', 'retry backoff'],
    [$runs, 'public function latestCompletedReadId', 'worker needs the latest authoritative completed READ generation'],
    [$runs, "read_status='completed' ORDER BY id_run DESC LIMIT 1", 'latest READ lookup must ignore incomplete newer generations'],
    [$worker, 'InterruptedCreateRecovery', 'interrupted-create recovery'],
    [$worker, 'SourceInterface', 'worker must resolve its active supplier source'],
    [$worker, '$sourceName = trim($this->sourceAdapter->name())', 'worker must resolve active source once per tick'],
    [$worker, '$this->queue->claim($worker, $sourceName, $limit, $shopId)', 'worker claim must be source scoped'],
    [$worker, '$source = $sourceName', 'worker must not trust queue row source as execution identity'],
    [$worker, 'assertTransactionalCore()', 'transactional DB safety'],
    [$worker, 'lock->acquire', 'shop/source lock'],
    [$worker, '$expectedRunId = (int) $job', 'worker generation capture'],
    [$worker, '$lockedJob = $this->queue->lockOwned($idQueue, $token)', 'worker must reload/lock queue state before catalog writes'],
    [$worker, '$expectedRunId = $lockedRunId', 'worker must adopt an already-enqueued newer generation before mutation'],
    [$worker, '$this->runs->assertContext($expectedRunId, $jobShop, $source)', 'locked generation must still belong to exact shop/source run'],
    [$worker, '$latestCompletedReadId = $this->runs->latestCompletedReadId($jobShop, $source)', 'worker must fence superseded completed READ generations'],
    [$worker, "import_status'] ?? 'pending') === 'completed'", 'completed IMPORT must close the new-product worker lane'],
    [$worker, "update_status'] ?? 'pending') !== 'pending'", 'started UPDATE must close the new-product worker lane'],
    [$worker, "remove_status'] ?? 'pending') !== 'pending'", 'started REMOVE must close the new-product worker lane'],
    [$worker, '$this->queue->supersede($idQueue, $token, $expectedRunId, $reason)', 'stale job must be finalized without catalog mutation'],
    [$worker, "ProductData::fromJson((string) (\$lockedJob['payload'] ?? ''))", 'catalog projection must use the row-locked latest payload'],
    [$worker, 'New-product locked payload/source-key mismatch', 'locked payload identity must be checked'],
    [$worker, 'existing_updated', 'latest generation must update existing mapping'],
    [$worker, 'generation_requeued', 'generation handoff metrics'],
    [$worker, 'generation_adopted', 'in-flight newer generation adoption metric'],
    [$worker, 'stale_superseded', 'stale worker suppression metric'],
    [$worker, 'writer->update($idProduct, $product, $jobShop)', 'existing product receives latest payload'],
    [$worker, 'ItemTransactionGuard', 'shared transaction recovery guard'],
    [$worker, '$this->transactionGuard->arm($db)', 'worker transaction guard arm'],
    [$worker, '$this->transactionGuard->restoreAfterExternalCommit()', 'nested hook recovery'],
    [$worker, '$this->transactionGuard->recoveryCount()', 'nested recovery metric'],
    [$worker, 'fail($idQueue, $token, $e->getMessage(), $retryable, $expectedRunId)', 'failure generation fence'],
    [$worker, "getValue('SELECT @@session.in_transaction', false)", 'live transaction-state read'],
    [$worker, 'combinationAttributes->resolve', 'Size/combo attribute resolution'],
    [$worker, 'images->enqueue', 'separate image pipeline'],
    [$worker, 'TransientDatabaseFailure::isRetryable', 'transient retry classification'],
    [$guard, 'private int $recoveryCount = 0', 'guard recovery counter'],
    [$guard, "getValue('SELECT @@session.in_transaction', false)", 'guard live connection-state read'],
    [$specific, "array_key_exists('specific_prices'", 'specific-price no-op unless explicitly supplied'],
    [$enqueue, "parent::__construct('matterhornimport:new-products:enqueue')", 'enqueue command name'],
    [$enqueue, "remove_status'] !== 'pending'", 'enqueue/remove safety gate'],
    [$command, "parent::__construct('matterhornimport:new-products')", 'worker command name'],
    [$command, "'generation_requeued'=>0", 'CLI generation requeue visibility'],
    [$command, "'generation_adopted'=>0", 'CLI generation adoption visibility'],
    [$command, "'stale_superseded'=>0", 'CLI stale suppression visibility'],
    [$command, "'existing_updated'=>0", 'CLI latest-payload update visibility'],
    [$services, 'Lp\\MatterhornImport\\Command\\NewProductsEnqueueCommand:', 'enqueue command service registration'],
    [$services, 'Lp\\MatterhornImport\\Command\\NewProductsCommand:', 'worker command service registration'],
];

foreach ($checks as [$haystack, $needle, $label]) {
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

if (!preg_match(
    '/public function latestCompletedReadId\([^}]+?getValue\(\s*[^;]*?read_status=\'completed\'[^;]*?,\s*false\s*\)\s*;/s',
    $runs
)) {
    fwrite(STDERR, "FAIL: latest completed READ lookup must bypass Db query cache\n");
    exit(1);
}

if (!preg_match(
    '/\$this->queue->done\(\s*\$idQueue\s*,\s*\$token\s*,\s*\$idProduct\s*,\s*\$expectedRunId\s*\)/s',
    $worker
)) {
    fwrite(STDERR, "FAIL: completion generation fence\n");
    exit(1);
}

$lockPos = strpos($worker, '$lockedJob = $this->queue->lockOwned($idQueue, $token)');
$payloadPos = strpos($worker, 'ProductData::fromJson(');
$updatePos = strpos($worker, '$this->writer->update(');
$createPos = strpos($worker, '$this->writer->create(');
if ($lockPos === false || $payloadPos === false || $updatePos === false || $createPos === false || $lockPos >= $payloadPos || $lockPos >= $updatePos || $lockPos >= $createPos) {
    fwrite(STDERR, "FAIL: queue generation/payload row lock must precede parsing and every catalog writer path\n");
    exit(1);
}

$latestReadPos = strpos($worker, '$latestCompletedReadId = $this->runs->latestCompletedReadId');
$supersedePos = strpos($worker, '$this->queue->supersede(');
if ($latestReadPos === false || $supersedePos === false || $latestReadPos >= $supersedePos || $supersedePos >= $payloadPos) {
    fwrite(STDERR, "FAIL: stale/newer completed READ must supersede the job before payload parsing/catalog mutation\n");
    exit(1);
}

$donePos = strpos($worker, '$finalizedGeneration = $this->queue->done(');
$commitPos = strpos($worker, "execute('COMMIT')");
if ($donePos === false || $commitPos === false || $donePos >= $commitPos) {
    fwrite(STDERR, "FAIL: queue generation finalization must be inside the worker transaction before COMMIT\n");
    exit(1);
}

$mappingPos = strpos($worker, '$this->mapping->save(');
$imagePos = strpos($worker, '$this->images->enqueue(');
if ($mappingPos === false || $imagePos === false || $mappingPos >= $donePos || $imagePos >= $donePos) {
    fwrite(STDERR, "FAIL: mapping/image durability must precede queue finalization in one transaction\n");
    exit(1);
}

echo "New-product worker contract: OK\n";
