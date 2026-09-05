<?php
namespace Lp\MatterhornImport\Import;

use Lp\MatterhornImport\Contract\OutOfFeedPolicyInterface;
use Lp\MatterhornImport\Repository\ErrorRepository;
use Lp\MatterhornImport\Repository\MappingRepository;
use Lp\MatterhornImport\Repository\RunRepository;
use Lp\MatterhornImport\Repository\SnapshotRepository;
use Lp\MatterhornImport\Util\DatabaseSafety;
use Lp\MatterhornImport\Util\ExecutionBudget;
use Lp\MatterhornImport\Util\ItemTransactionGuard;
use Lp\MatterhornImport\Util\RunFailureRecorder;
use Lp\MatterhornImport\Util\TransientDatabaseFailure;

final class RemoveStage
{
    private const DEFAULT_MAX_REMOVE_PERCENT = 25;

    public function __construct(
        private OutOfFeedPolicyInterface $outOfFeedPolicy,
        private RunRepository $runs,
        private SnapshotRepository $snapshots,
        private MappingRepository $mapping,
        private ErrorRepository $errors,
        private DatabaseSafety $safety,
        private ItemTransactionGuard $transactionGuard,
        private RunFailureRecorder $failureRecorder,
        private ExecutionBudget $budget
    ) {}

    /** @return array{mapped:int,candidates:int,percent:float,max_percent:int,safe:bool} */
    public function plan(int $runId, int $shopId, string $source): array
    {
        $run = $this->runs->assertContext($runId, $shopId, $source);
        $this->assertRunnable($run);
        $mapped = $this->mapping->countInFeedSource($shopId, $source);
        $candidates = $mapped > 0 ? $this->snapshots->countRemoved($runId, $shopId, $source) : 0;
        $maxPercent = $this->maxRemovePercent($shopId);
        $actualPercent = $mapped > 0 ? ($candidates * 100.0) / $mapped : 0.0;

        return [
            'mapped' => $mapped,
            'candidates' => $candidates,
            'percent' => $actualPercent,
            'max_percent' => $maxPercent,
            'safe' => $mapped <= 0 || $candidates <= 0 || $actualPercent <= $maxPercent,
        ];
    }

    public function run(
        int $runId,
        int $shopId,
        string $source,
        int $batch = 500,
        int $maxItems = 0,
        int $timeLimitSeconds = 0
    ): bool {
        $this->safety->assertTransactionalCore();
        $plan = $this->plan($runId, $shopId, $source);
        $this->budget->start($maxItems, $timeLimitSeconds);

        try {
            $this->assertRemovalPlanSafe($plan);
            $this->runs->resume($runId);
            $this->runs->resetStageFailureCounter($runId, 'remove');
            $this->runs->stage($runId, 'remove', 'running');
            $cursor = 0;
            $failures = 0;
            $paused = false;
            $batch = max(1, min(2000, $batch));

            while (!$this->budget->shouldStop()
                && ($rows = $this->snapshots->removedRows($runId, $shopId, $source, $cursor, $batch)) !== []
            ) {
                foreach ($rows as $row) {
                    if ($this->budget->shouldStop()) {
                        $paused = true;
                        break;
                    }

                    $productId = (int) $row['id_product'];
                    $sourceKey = (string) $row['source_key'];
                    $cursor = $productId;
                    $db = \Db::getInstance();
                    $transaction = false;

                    try {
                        if (!$db->execute('START TRANSACTION')) {
                            throw new \RuntimeException('Could not start REMOVE item transaction');
                        }
                        $transaction = true;
                        $this->transactionGuard->arm($db);

                        if (!$this->mapping->lockProductOwnership($shopId, $source, $sourceKey, $productId)) {
                            throw new \RuntimeException('REMOVE mapping ownership changed before policy execution');
                        }

                        $this->outOfFeedPolicy->apply($productId, $shopId);

                        // Product/Stock ObjectModels and third-party hooks can commit the shared
                        // PrestaShop connection. If that happened, the row lock was released too.
                        // Restore the item transaction and reacquire exact mapping ownership before
                        // recording the durable out-of-feed completion.
                        $this->transactionGuard->restoreAfterExternalCommit();
                        if ($this->transactionGuard->recoveryCount() > 0
                            && !$this->mapping->lockProductOwnership($shopId, $source, $sourceKey, $productId)
                        ) {
                            throw new \RuntimeException('REMOVE mapping ownership changed after PrestaShop external commit');
                        }

                        $this->mapping->markOutOfFeed($shopId, $source, $sourceKey, $productId, $runId);
                        $this->runs->increment($runId, 'remove_done', 1);

                        if (!$db->execute('COMMIT')) {
                            throw new \RuntimeException('Could not commit REMOVE item completion');
                        }
                        $transaction = false;
                        $this->transactionGuard->disarm();
                        $this->budget->markItem();
                    } catch (\Throwable $itemError) {
                        if ($transaction) {
                            try {
                                if ($this->transactionIsActive($db)) {
                                    $db->execute('ROLLBACK');
                                }
                            } catch (\Throwable) {
                            }
                            $transaction = false;
                        }
                        $this->transactionGuard->disarm();

                        if (TransientDatabaseFailure::isRetryable($itemError)) {
                            throw $itemError;
                        }

                        $failures++;
                        $this->runs->increment($runId, 'remove_failed', 1);
                        $this->budget->markItem();
                        $this->errors->add($runId, 'remove', $sourceKey, $itemError);
                    }
                }

                if ($paused || $this->budget->shouldStop()) {
                    $paused = true;
                    break;
                }
                if (count($rows) < $batch) {
                    break;
                }
            }

            if ($failures > 0) {
                throw new \RuntimeException('REMOVE completed with ' . $failures . ' failed item(s); retry required');
            }
            if ($paused || $this->budget->shouldStop()) {
                $this->runs->stage($runId, 'remove', 'paused');
                $this->runs->finish($runId, 'paused');
                return false;
            }

            $this->runs->stage($runId, 'remove', 'completed');
            $this->runs->finish($runId, 'completed');
            return true;
        } catch (\Throwable $e) {
            $this->transactionGuard->disarm();
            $this->failureRecorder->record($runId, 'remove', $e);
            throw $e;
        }
    }

    private function transactionIsActive(\Db $db): bool
    {
        $value = $db->getValue('SELECT @@session.in_transaction', false);
        if ($value === false) {
            throw new \RuntimeException('Could not inspect REMOVE transaction state: ' . $db->getMsgError());
        }
        return (int) $value === 1;
    }

    private function assertRunnable(array $run): void
    {
        if ((string) $run['read_status'] !== 'completed'
            || (string) $run['import_status'] !== 'completed'
            || (string) $run['update_status'] !== 'completed'
            || (int) $run['source_valid'] === 0
        ) {
            throw new \RuntimeException('REMOVE blocked: previous stages or source sanity check incomplete');
        }
        if ((string) $run['remove_status'] === 'completed') {
            throw new \RuntimeException('REMOVE is already completed for this run');
        }
    }

    /** @param array{mapped:int,candidates:int,percent:float,max_percent:int,safe:bool} $plan */
    private function assertRemovalPlanSafe(array $plan): void
    {
        if ($plan['safe']) { return; }
        throw new \RuntimeException(sprintf(
            'REMOVE safety guard blocked %d of %d in-feed products (%.2f%%); configured maximum is %d%%',
            $plan['candidates'],
            $plan['mapped'],
            $plan['percent'],
            $plan['max_percent']
        ));
    }

    private function maxRemovePercent(int $shopId): int
    {
        $configured = (int) \Configuration::get('MATTERHORNIMPORT_MAX_REMOVE_PERCENT', null, null, $shopId);
        return $configured > 0 ? max(1, min(100, $configured)) : self::DEFAULT_MAX_REMOVE_PERCENT;
    }
}
