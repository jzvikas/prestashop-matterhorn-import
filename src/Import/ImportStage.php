<?php
namespace Lp\MatterhornImport\Import;

use Lp\MatterhornImport\Combination\CombinationAttributeResolver;
use Lp\MatterhornImport\Combination\CombinationSynchronizer;
use Lp\MatterhornImport\Contract\ProductWriterInterface;
use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Feature\FeatureSynchronizer;
use Lp\MatterhornImport\Product\InterruptedCreateRecovery;
use Lp\MatterhornImport\Repository\ErrorRepository;
use Lp\MatterhornImport\Repository\ImageQueueRepository;
use Lp\MatterhornImport\Repository\MappingRepository;
use Lp\MatterhornImport\Repository\RunRepository;
use Lp\MatterhornImport\Repository\SnapshotRepository;
use Lp\MatterhornImport\SpecificPrice\SpecificPriceSynchronizer;
use Lp\MatterhornImport\Util\DatabaseSafety;
use Lp\MatterhornImport\Util\ExecutionBudget;
use Lp\MatterhornImport\Util\ItemTransactionGuard;
use Lp\MatterhornImport\Util\RunFailureRecorder;
use Lp\MatterhornImport\Util\TransientDatabaseFailure;

final class ImportStage
{
    private const SAVEPOINT = 'matterhorn_import_item';

    public function __construct(
        private ProductWriterInterface $writer,
        private InterruptedCreateRecovery $createRecovery,
        private FeatureSynchronizer $features,
        private CombinationAttributeResolver $combinationAttributes,
        private CombinationSynchronizer $combinations,
        private SpecificPriceSynchronizer $specificPrices,
        private RunRepository $runs,
        private SnapshotRepository $snapshots,
        private MappingRepository $mapping,
        private ImageQueueRepository $images,
        private ErrorRepository $errors,
        private DatabaseSafety $safety,
        private ItemTransactionGuard $transactionGuard,
        private RunFailureRecorder $failureRecorder,
        private ExecutionBudget $budget
    ) {}

    public function run(int $runId, int $shopId, string $source, int $batch = 500, int $maxItems = 0, int $timeLimitSeconds = 0): bool
    {
        $this->safety->assertTransactionalCore();
        $run = $this->runs->assertContext($runId, $shopId, $source);
        $this->assertRunnable($run);
        $this->budget->start($maxItems, $timeLimitSeconds);
        $recoverInterrupted = in_array((string) $run['import_status'], ['running','failed'], true);
        $runStartedAt = (string) ($run['started_at'] ?? '');

        try {
            $this->runs->resume($runId);
            $this->runs->resetStageFailureCounter($runId, 'import');
            $this->runs->stage($runId, 'import', 'running');
            $cursor = '';
            $failures = 0;
            $batch = max(1, min(2000, $batch));
            $paused = false;

            while (!$this->budget->shouldStop() && ($rows = $this->snapshots->newRows($runId, $shopId, $source, $cursor, $batch)) !== []) {
                $db = \Db::getInstance();
                if (!$db->execute('START TRANSACTION')) { throw new \RuntimeException('Could not start IMPORT batch transaction'); }
                $done = 0;
                $batchFailures = 0;
                try {
                    foreach ($rows as $row) {
                        if ($this->budget->shouldStop()) { $paused = true; break; }
                        $cursor = (string) $row['source_key'];
                        $this->beginItemSavepoint($db);
                        $product = null;
                        try {
                            $product = ProductData::fromJson((string) $row['payload']);
                            $productId = $recoverInterrupted ? $this->createRecovery->findRecoverable($shopId, $source, $product, $runStartedAt) : 0;
                            if ($productId > 0) { $this->writer->update($productId, $product, $shopId); }
                            else { $productId = $this->writer->create($product, $shopId); }
                            $this->transactionGuard->restoreAfterExternalCommit();

                            $this->features->sync($runId, $shopId, $source, $productId, $product);
                            $this->transactionGuard->restoreAfterExternalCommit();

                            $resolved = $this->combinationAttributes->resolve($product, $shopId, $source);
                            $this->combinations->sync($runId, $shopId, $source, $productId, $resolved);
                            $this->transactionGuard->restoreAfterExternalCommit();

                            $this->specificPrices->sync($runId, $shopId, $source, $productId, $product);
                            $this->transactionGuard->restoreAfterExternalCommit();

                            $this->mapping->save($shopId, $source, $runId, $productId, $product);
                            $this->images->enqueue($runId, $shopId, $source, $product->sourceKey, $productId, $product->images);
                            if (!$db->execute('RELEASE SAVEPOINT ' . self::SAVEPOINT)) {
                                throw new \RuntimeException('Could not release IMPORT item savepoint: ' . $db->getMsgError());
                            }
                            $this->transactionGuard->disarm();
                            $done++;
                            $this->budget->markItem();
                        } catch (\Throwable $itemError) {
                            if (TransientDatabaseFailure::isRetryable($itemError)) { throw $itemError; }
                            $this->rollbackItemSavepoint($db, $itemError);
                            $batchFailures++;
                            $this->budget->markItem();
                            $this->errors->add($runId, 'import', $product?->sourceKey ?? $cursor, $itemError);
                        }
                    }
                    if ($done > 0) { $this->runs->increment($runId, 'import_done', $done); }
                    if ($batchFailures > 0) { $this->runs->increment($runId, 'import_failed', $batchFailures); }
                    if (!$db->execute('COMMIT')) { throw new \RuntimeException('Could not commit IMPORT batch'); }
                } catch (\Throwable $batchError) {
                    $this->transactionGuard->disarm();
                    $db->execute('ROLLBACK');
                    throw $batchError;
                }
                $failures += $batchFailures;
                if ($paused || $this->budget->shouldStop()) { $paused = true; break; }
            }

            if ($failures > 0) { throw new \RuntimeException('IMPORT completed with ' . $failures . ' failed item(s); retry required'); }
            if ($paused || $this->budget->shouldStop()) {
                $this->runs->stage($runId, 'import', 'paused');
                $this->runs->finish($runId, 'paused');
                return false;
            }
            $this->runs->stage($runId, 'import', 'completed');
            return true;
        } catch (\Throwable $e) {
            $this->transactionGuard->disarm();
            $this->failureRecorder->record($runId, 'import', $e);
            throw $e;
        }
    }

    private function beginItemSavepoint(\Db $db): void
    {
        if (!$this->transactionIsActive($db) && !$db->execute('START TRANSACTION')) {
            throw new \RuntimeException('Could not restore IMPORT transaction');
        }
        if (!$db->execute('SAVEPOINT ' . self::SAVEPOINT)) {
            throw new \RuntimeException('Could not create IMPORT item savepoint: ' . $db->getMsgError());
        }
        $this->transactionGuard->arm($db, self::SAVEPOINT);
    }

    private function rollbackItemSavepoint(\Db $db, \Throwable $cause): void
    {
        try {
            if (!$this->transactionIsActive($db)) { return; }
            if (!$db->execute('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT)) {
                $db->execute('ROLLBACK');
                throw new \RuntimeException('Could not roll back IMPORT item savepoint: ' . $db->getMsgError(), 0, $cause);
            }
            if (!$db->execute('RELEASE SAVEPOINT ' . self::SAVEPOINT)) {
                throw new \RuntimeException('Could not release rolled-back IMPORT savepoint', 0, $cause);
            }
        } finally {
            $this->transactionGuard->disarm();
        }
    }

    private function transactionIsActive(\Db $db): bool
    {
        $value = $db->getValue('SELECT @@session.in_transaction', false);
        if ($value === false) { throw new \RuntimeException('Could not inspect IMPORT transaction state: ' . $db->getMsgError()); }
        return (int) $value === 1;
    }

    private function assertRunnable(array $run): void
    {
        if ((string) $run['read_status'] !== 'completed') { throw new \RuntimeException('READ must complete before IMPORT'); }
        if ((string) $run['import_status'] === 'completed') { throw new \RuntimeException('IMPORT is already completed for this run'); }
        if ((string) $run['update_status'] !== 'pending' || (string) $run['remove_status'] !== 'pending') { throw new \RuntimeException('IMPORT retry blocked because downstream stages already started'); }
    }
}