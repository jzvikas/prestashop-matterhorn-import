<?php
namespace Lp\MatterhornImport\Import;

use Lp\MatterhornImport\Contract\SourceInterface;
use Lp\MatterhornImport\Lock\ImportLock;
use Lp\MatterhornImport\Repository\RunRepository;
use Lp\MatterhornImport\Util\ExecutionBudget;

final class ImportRunner
{
    public function __construct(
        private SourceInterface $source,
        private RunRepository $runs,
        private ImportLock $lock,
        private ReadStage $read,
        private ImportStage $import,
        private UpdateStage $update,
        private RemoveStage $remove,
        private ExecutionBudget $budget
    ) {
    }

    public function run(int $shopId, int $batch = 500): int
    {
        $result = $this->runBounded($shopId, $batch);
        if ($result['status'] !== 'completed') {
            throw new \RuntimeException('Unlimited Matterhorn orchestration unexpectedly paused');
        }
        return $result['run'];
    }

    /** @return array{run:int,status:'completed'|'paused',stage:string} */
    public function runBounded(
        int $shopId,
        int $batch = 500,
        int $maxItems = 0,
        int $timeLimitSeconds = 0,
        ?int $resumeRunId = null
    ): array {
        if ($shopId <= 0 || $batch < 1 || $maxItems < 0 || $timeLimitSeconds < 0) {
            throw new \InvalidArgumentException('Invalid Matterhorn orchestration limits');
        }
        $batch = min(2000, $batch);
        $source = $this->source->name();
        if (!$this->lock->acquire($shopId, $source)) {
            throw new \RuntimeException('Matterhorn import already running for this shop');
        }

        $runId = null;
        $executionStarted = false;
        $remainingItems = $maxItems;
        $deadline = $timeLimitSeconds > 0 ? microtime(true) + $timeLimitSeconds : 0.0;

        try {
            if ($resumeRunId !== null) {
                $runId = $resumeRunId;
                $run = $this->runs->assertContext($runId, $shopId, $source);
                if (in_array((string) $run['status'], ['completed', 'cancelled'], true)) {
                    throw new \RuntimeException('Matterhorn import run #' . $runId . ' is terminal and cannot be resumed');
                }
                $this->runs->assertLatestCompletedReadGeneration($runId, $shopId, $source);
                $this->runs->resume($runId);
                $executionStarted = true;
            } else {
                $runId = $this->runs->create($shopId, $source);
                $executionStarted = true;
            }

            $run = $this->runs->assertContext($runId, $shopId, $source);
            if ((string) $run['read_status'] !== 'completed') {
                if (!$this->hasBudget($remainingItems, $maxItems, $deadline)) {
                    return $this->pauseBetweenStages($runId, 'read');
                }
                $completed = $this->read->run(
                    $runId,
                    $this->stageItemLimit($remainingItems, $maxItems),
                    $this->remainingSeconds($deadline)
                );
                $remainingItems = $this->consumeItems($remainingItems, $maxItems);
                if (!$completed) {
                    return ['run' => $runId, 'status' => 'paused', 'stage' => 'read'];
                }
            }

            $run = $this->runs->assertContext($runId, $shopId, $source);
            if ((string) $run['import_status'] !== 'completed') {
                if (!$this->hasBudget($remainingItems, $maxItems, $deadline)) {
                    return $this->pauseBetweenStages($runId, 'import');
                }
                $completed = $this->import->run(
                    $runId,
                    $shopId,
                    $source,
                    $batch,
                    $this->stageItemLimit($remainingItems, $maxItems),
                    $this->remainingSeconds($deadline)
                );
                $remainingItems = $this->consumeItems($remainingItems, $maxItems);
                if (!$completed) {
                    return ['run' => $runId, 'status' => 'paused', 'stage' => 'import'];
                }
            }

            $run = $this->runs->assertContext($runId, $shopId, $source);
            if ((string) $run['update_status'] !== 'completed') {
                if (!$this->hasBudget($remainingItems, $maxItems, $deadline)) {
                    return $this->pauseBetweenStages($runId, 'update');
                }
                $completed = $this->update->run(
                    $runId,
                    $shopId,
                    $source,
                    $batch,
                    $this->stageItemLimit($remainingItems, $maxItems),
                    $this->remainingSeconds($deadline)
                );
                $remainingItems = $this->consumeItems($remainingItems, $maxItems);
                if (!$completed) {
                    return ['run' => $runId, 'status' => 'paused', 'stage' => 'update'];
                }
            }

            $run = $this->runs->assertContext($runId, $shopId, $source);
            if ((string) $run['remove_status'] !== 'completed') {
                if (!$this->hasBudget($remainingItems, $maxItems, $deadline)) {
                    return $this->pauseBetweenStages($runId, 'remove');
                }
                $completed = $this->remove->run(
                    $runId,
                    $shopId,
                    $source,
                    $batch,
                    $this->stageItemLimit($remainingItems, $maxItems),
                    $this->remainingSeconds($deadline)
                );
                if (!$completed) {
                    return ['run' => $runId, 'status' => 'paused', 'stage' => 'remove'];
                }
            }

            // REMOVE currently completes the run as well. Keep this idempotent write here so
            // the runner remains correct if stage ownership of the final status changes later.
            $this->runs->finish($runId, 'completed');
            return ['run' => $runId, 'status' => 'completed', 'stage' => 'completed'];
        } catch (\Throwable $e) {
            if ($runId !== null && $executionStarted) {
                $this->markFailedBestEffort($runId);
            }
            throw $e;
        } finally {
            $this->lock->release();
        }
    }

    private function hasBudget(int $remainingItems, int $maxItems, float $deadline): bool
    {
        if ($maxItems > 0 && $remainingItems <= 0) {
            return false;
        }
        return $deadline <= 0.0 || microtime(true) < $deadline;
    }

    private function stageItemLimit(int $remainingItems, int $maxItems): int
    {
        return $maxItems > 0 ? max(1, $remainingItems) : 0;
    }

    private function remainingSeconds(float $deadline): int
    {
        if ($deadline <= 0.0) {
            return 0;
        }
        return max(1, (int) ceil($deadline - microtime(true)));
    }

    private function consumeItems(int $remainingItems, int $maxItems): int
    {
        if ($maxItems <= 0) {
            return 0;
        }
        return max(0, $remainingItems - $this->budget->processed());
    }

    /** @return array{run:int,status:'paused',stage:string} */
    private function pauseBetweenStages(int $runId, string $nextStage): array
    {
        $this->runs->finish($runId, 'paused');
        return ['run' => $runId, 'status' => 'paused', 'stage' => $nextStage];
    }

    private function markFailedBestEffort(int $runId): void
    {
        try {
            $run = $this->runs->get($runId);
            if ($run !== null && (string) ($run['status'] ?? '') === 'cancelled') {
                return;
            }
            $this->runs->finish($runId, 'failed');
        } catch (\Throwable $finishError) {
            error_log(sprintf(
                '[matterhornimport] failed to mark import run %d as failed: %s',
                $runId,
                $finishError->getMessage()
            ));
        }
    }
}
