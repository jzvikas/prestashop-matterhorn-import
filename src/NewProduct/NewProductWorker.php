<?php
namespace Lp\MatterhornImport\NewProduct;

use Lp\MatterhornImport\Combination\CombinationAttributeResolver;
use Lp\MatterhornImport\Combination\CombinationSynchronizer;
use Lp\MatterhornImport\Contract\ProductWriterInterface;
use Lp\MatterhornImport\Contract\SourceInterface;
use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Feature\FeatureSynchronizer;
use Lp\MatterhornImport\Lock\ImportLock;
use Lp\MatterhornImport\Product\InterruptedCreateRecovery;
use Lp\MatterhornImport\Repository\ImageQueueRepository;
use Lp\MatterhornImport\Repository\MappingRepository;
use Lp\MatterhornImport\Repository\NewProductQueueRepository;
use Lp\MatterhornImport\Repository\RunRepository;
use Lp\MatterhornImport\SpecificPrice\SpecificPriceSynchronizer;
use Lp\MatterhornImport\Util\DatabaseSafety;
use Lp\MatterhornImport\Util\ItemTransactionGuard;
use Lp\MatterhornImport\Util\TransientDatabaseFailure;

final class NewProductWorker
{
    public function __construct(
        private NewProductQueueRepository $queue,
        private SourceInterface $sourceAdapter,
        private ProductWriterInterface $writer,
        private InterruptedCreateRecovery $createRecovery,
        private FeatureSynchronizer $features,
        private CombinationAttributeResolver $combinationAttributes,
        private CombinationSynchronizer $combinations,
        private SpecificPriceSynchronizer $specificPrices,
        private MappingRepository $mapping,
        private ImageQueueRepository $images,
        private RunRepository $runs,
        private ImportLock $lock,
        private DatabaseSafety $safety,
        private ItemTransactionGuard $transactionGuard
    ) {
    }

    public function tick(string $worker, int $limit = 20, ?int $shopId = null): array
    {
        $this->safety->assertTransactionalCore();
        $sourceName = trim($this->sourceAdapter->name());
        if ($sourceName === '') { throw new \RuntimeException('New-product worker source name is empty'); }
        $jobs = $this->queue->claim($worker, $sourceName, $limit, $shopId);
        $stats = [
            'processed' => 0,
            'done' => 0,
            'failed' => 0,
            'deferred' => 0,
            'lost' => 0,
            'generation_requeued' => 0,
            'existing_updated' => 0,
            'recovered' => 0,
            'hook_commit_recoveries' => 0,
        ];

        foreach ($jobs as $job) {
            $stats['processed']++;
            $idQueue = (int) $job['id_queue'];
            $expectedRunId = (int) $job['id_run'];
            $token = (string) $job['locked_by'];
            $jobShop = (int) $job['id_shop'];
            $source = $sourceName;

            if (!$this->queue->renew($idQueue, $token)) {
                $stats['lost']++;
                continue;
            }

            if (!$this->lock->acquire($jobShop, $source, 0)) {
                try {
                    if ($this->queue->fail(
                        $idQueue,
                        $token,
                        'Shop/source import lock is busy; deferred',
                        true,
                        $expectedRunId
                    )) {
                        $stats['deferred']++;
                    } else {
                        $stats['generation_requeued']++;
                    }
                } catch (\Throwable) {
                    $stats['lost']++;
                }
                continue;
            }

            try {
                if (!$this->queue->renew($idQueue, $token)) {
                    $stats['lost']++;
                    continue;
                }

                $db = \Db::getInstance();
                if (!$db->execute('START TRANSACTION')) {
                    throw new \RuntimeException('Could not start new-product transaction');
                }
                $this->transactionGuard->arm($db);

                try {
                    $product = ProductData::fromJson((string) $job['payload']);
                    $idProduct = $this->mapping->findProductId($jobShop, $source, $product->sourceKey);
                    $existing = $idProduct > 0;

                    if ($existing) {
                        $this->writer->update($idProduct, $product, $jobShop);
                        $stats['existing_updated']++;
                    } else {
                        if ((int) ($job['attempts'] ?? 0) > 1) {
                            $run = $this->runs->get($expectedRunId);
                            $runStartedAt = is_array($run) ? (string) ($run['started_at'] ?? '') : '';
                            if ($runStartedAt === '') {
                                throw new \RuntimeException('New-product recovery cannot resolve source run start time');
                            }
                            $idProduct = $this->createRecovery->findRecoverable(
                                $jobShop,
                                $source,
                                $product,
                                $runStartedAt
                            );
                        }

                        if ($idProduct > 0) {
                            $this->writer->update($idProduct, $product, $jobShop);
                            $stats['recovered']++;
                        } else {
                            $idProduct = $this->writer->create($product, $jobShop);
                        }
                    }
                    $this->transactionGuard->restoreAfterExternalCommit();

                    $this->features->sync($expectedRunId, $jobShop, $source, $idProduct, $product);
                    $this->transactionGuard->restoreAfterExternalCommit();

                    $combinationProduct = $this->combinationAttributes->resolve($product, $jobShop, $source);
                    $this->combinations->sync(
                        $expectedRunId,
                        $jobShop,
                        $source,
                        $idProduct,
                        $combinationProduct
                    );
                    $this->transactionGuard->restoreAfterExternalCommit();

                    $this->specificPrices->sync($expectedRunId, $jobShop, $source, $idProduct, $product);
                    $this->transactionGuard->restoreAfterExternalCommit();

                    $stats['hook_commit_recoveries'] += $this->transactionGuard->recoveryCount();

                    $this->mapping->save($jobShop, $source, $expectedRunId, $idProduct, $product);
                    $this->images->enqueue(
                        $expectedRunId,
                        $jobShop,
                        $source,
                        $product->sourceKey,
                        $idProduct,
                        $product->images
                    );

                    if (!$this->queue->renew($idQueue, $token)) {
                        throw new \RuntimeException('New-product queue ownership lost before commit');
                    }
                    $finalizedGeneration = $this->queue->done(
                        $idQueue,
                        $token,
                        $idProduct,
                        $expectedRunId
                    );
                    if (!$db->execute('COMMIT')) {
                        throw new \RuntimeException('Could not commit new-product transaction');
                    }
                    $this->transactionGuard->disarm();

                    if ($finalizedGeneration) {
                        $stats['done']++;
                    } else {
                        $stats['generation_requeued']++;
                    }
                } catch (\Throwable $e) {
                    try {
                        if ($this->transactionIsActive($db)) {
                            $db->execute('ROLLBACK');
                        }
                    } catch (\Throwable) {
                    } finally {
                        $this->transactionGuard->disarm();
                    }
                    throw $e;
                }
            } catch (\Throwable $e) {
                $message = strtolower($e->getMessage());
                $retryable = TransientDatabaseFailure::isRetryable($e)
                    || str_contains($message, 'lock is busy')
                    || str_contains($message, 'ownership lost');
                try {
                    if ($this->queue->fail($idQueue, $token, $e->getMessage(), $retryable, $expectedRunId)) {
                        $stats['failed']++;
                    } else {
                        $stats['generation_requeued']++;
                    }
                } catch (\Throwable) {
                    $stats['lost']++;
                }
            } finally {
                $this->transactionGuard->disarm();
                $this->lock->release();
            }
        }

        return $stats;
    }

    private function transactionIsActive(\Db $db): bool
    {
        $value = $db->getValue('SELECT @@session.in_transaction', false);
        if ($value === false) {
            throw new \RuntimeException('Could not inspect new-product transaction state: ' . $db->getMsgError());
        }
        return (int) $value === 1;
    }
}
