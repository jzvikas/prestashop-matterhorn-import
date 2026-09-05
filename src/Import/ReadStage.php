<?php
namespace Lp\MatterhornImport\Import;

use Lp\MatterhornImport\Config\MatterhornPolicy;
use Lp\MatterhornImport\Contract\CheckpointableSourceInterface;
use Lp\MatterhornImport\Contract\ProductMapperInterface;
use Lp\MatterhornImport\Contract\SourceInterface;
use Lp\MatterhornImport\Repository\ErrorRepository;
use Lp\MatterhornImport\Repository\RunRepository;
use Lp\MatterhornImport\Repository\SnapshotRepository;
use Lp\MatterhornImport\Util\ExecutionBudget;
use Lp\MatterhornImport\Util\RunFailureRecorder;
use Lp\MatterhornImport\Util\ShopContextManager;

final class ReadStage
{
    private const WRITE_BATCH = 500;
    private const MAX_PRODUCT_PAYLOAD_BYTES = 2097152;
    private const MAX_BATCH_PAYLOAD_BYTES = 8388608;

    public function __construct(
        private SourceInterface $source,
        private ProductMapperInterface $mapper,
        private RunRepository $runs,
        private SnapshotRepository $snapshots,
        private ErrorRepository $errors,
        private ShopContextManager $shopContext,
        private RunFailureRecorder $failureRecorder,
        private ExecutionBudget $budget,
        private MatterhornPolicy $policy
    ) {}

    public function run(int $runId, int $maxItems = 0, int $timeLimitSeconds = 0): bool
    {
        $run = $this->runs->get($runId);
        if ($run === null) { throw new \RuntimeException('Matterhorn import run not found: ' . $runId); }
        $shopId = (int) $run['id_shop'];
        $this->shopContext->activate($shopId);
        if ((string) $run['read_status'] === 'completed') {
            throw new \RuntimeException('READ is already completed for run #' . $runId);
        }
        foreach (['import_status','update_status','remove_status'] as $downstream) {
            if ((string) $run[$downstream] !== 'pending') {
                throw new \RuntimeException('READ resume blocked because downstream stages already started');
            }
        }

        $policySnapshot = $this->policy->snapshot($shopId, true);
        $policyHash = $this->policy->hash($policySnapshot);
        $checkpoint = (int) ($run['read_checkpoint'] ?? 0);
        $checkpointSource = $this->source instanceof CheckpointableSourceInterface ? $this->source : null;
        $this->budget->start($maxItems, $timeLimitSeconds);

        try {
            $fingerprint = $checkpointSource?->fingerprint();
            $storedFingerprint = (string) ($run['source_fingerprint'] ?? '');
            $storedPolicyHash = (string) ($run['source_policy_hash'] ?? '');
            if ($checkpoint > 0) {
                if ($checkpointSource === null) { throw new \RuntimeException('Source does not support READ checkpoint resume; start a new run'); }
                if ($storedFingerprint === '' || !hash_equals($storedFingerprint, (string) $fingerprint)) {
                    throw new \RuntimeException('Source changed since READ checkpoint; start a new run');
                }
                if ($storedPolicyHash === '' || !hash_equals($storedPolicyHash, $policyHash)) {
                    throw new \RuntimeException('Matterhorn semantic configuration changed since READ checkpoint; start a new run');
                }
                $this->runs->resume($runId);
            } else {
                $this->prepareFreshRead($runId, $fingerprint, $policyHash);
            }

            $this->runs->stage($runId, 'read', 'running');
            $stream = $checkpointSource !== null ? $checkpointSource->rowsFrom($checkpoint) : $this->source->rows();
            $absoluteCheckpoint = $checkpoint;
            $products = [];
            $batchErrors = [];
            $batchWarnings = [];
            $batchTotal = $batchValid = $batchInvalid = $batchPayloadBytes = 0;
            $paused = false;

            foreach ($stream as $row) {
                if ($this->budget->shouldStop()) { $paused = true; break; }
                $absoluteCheckpoint++;
                try {
                    $product = $this->mapper->map($row);
                    $payloadBytes = strlen($product->toJson());
                    if ($payloadBytes > self::MAX_PRODUCT_PAYLOAD_BYTES) {
                        throw new \RuntimeException('Normalized product payload exceeds READ limit of ' . self::MAX_PRODUCT_PAYLOAD_BYTES . ' bytes (' . $payloadBytes . ' bytes)');
                    }
                    if ($batchTotal > 0 && ($batchTotal >= self::WRITE_BATCH || $batchPayloadBytes + $payloadBytes > self::MAX_BATCH_PAYLOAD_BYTES)) {
                        $this->flushBatch($runId, $absoluteCheckpoint - 1, $products, $batchErrors, $batchWarnings, $batchTotal, $batchValid, $batchInvalid);
                        $products = []; $batchErrors = []; $batchWarnings = []; $batchTotal = $batchValid = $batchInvalid = $batchPayloadBytes = 0;
                    }
                    $products[] = $product;
                    foreach ((array) ($product->extra['supplier_warnings'] ?? []) as $warning) {
                        $message = trim((string) $warning);
                        if ($message !== '') { $batchWarnings[] = ['source_key' => $product->sourceKey, 'message' => $message]; }
                    }
                    $batchPayloadBytes += $payloadBytes;
                    $batchValid++;
                } catch (\Throwable $e) {
                    $batchInvalid++;
                    $batchErrors[] = ['source_key' => (string) ($row['id'] ?? $row['reference'] ?? ''), 'error' => $e];
                }
                $batchTotal++;
                $this->budget->markItem();
                if ($batchTotal >= self::WRITE_BATCH) {
                    $this->flushBatch($runId, $absoluteCheckpoint, $products, $batchErrors, $batchWarnings, $batchTotal, $batchValid, $batchInvalid);
                    $products = []; $batchErrors = []; $batchWarnings = []; $batchTotal = $batchValid = $batchInvalid = $batchPayloadBytes = 0;
                }
                if ($this->budget->shouldStop()) { $paused = true; break; }
            }

            if ($batchTotal > 0) {
                $this->flushBatch($runId, $absoluteCheckpoint, $products, $batchErrors, $batchWarnings, $batchTotal, $batchValid, $batchInvalid);
            }
            if ($checkpointSource !== null && !hash_equals((string) $fingerprint, $checkpointSource->fingerprint())) {
                throw new \RuntimeException('Source XML changed while READ was running; downstream stages blocked');
            }
            if ($paused || $this->budget->shouldStop()) {
                if ($checkpointSource === null) { throw new \RuntimeException('READ stop requested but source is not checkpointable; start a new run'); }
                $this->runs->stage($runId, 'read', 'paused');
                $this->runs->finish($runId, 'paused');
                return false;
            }

            $run = $this->runs->get($runId);
            if ($run === null) { throw new \RuntimeException('Matterhorn run disappeared during READ'); }
            $distinct = $this->snapshots->countRun($runId);
            $duplicates = max(0, (int) $run['source_valid'] - $distinct);
            $this->runs->setReadDuplicate($runId, $duplicates);
            if ((int) $run['source_invalid'] > 0) {
                throw new \RuntimeException('READ contains ' . (int) $run['source_invalid'] . ' invalid rows; downstream stages blocked');
            }
            if ($duplicates > 0) {
                throw new \RuntimeException('READ contains ' . $duplicates . ' duplicate source keys; downstream stages blocked');
            }
            if ((int) $run['source_valid'] === 0) {
                throw new \RuntimeException('READ produced zero valid products; destructive stages blocked');
            }
            $previous = $this->runs->previousCompleted($runId, (int) $run['id_shop'], (string) $run['source']);
            if ($previous && (int) $previous['source_valid'] >= 1000 && (int) $run['source_valid'] < (int) floor((int) $previous['source_valid'] * 0.80)) {
                throw new \RuntimeException('Source sanity guard: valid row count dropped below 80% of previous completed run');
            }
            $this->runs->stage($runId, 'read', 'completed');
            return true;
        } catch (\Throwable $e) {
            $this->failureRecorder->record($runId, 'read', $e);
            throw $e;
        }
    }

    private function prepareFreshRead(int $runId, ?string $fingerprint, string $policyHash): void
    {
        $db = \Db::getInstance();
        if (!$db->execute('START TRANSACTION')) { throw new \RuntimeException('Could not start READ reset transaction'); }
        try {
            $this->snapshots->purgeRun($runId);
            $this->errors->purgeStage($runId, 'read');
            $this->runs->resetRead($runId, $fingerprint, $policyHash);
            if (!$db->execute('COMMIT')) { throw new \RuntimeException('Could not commit READ reset'); }
        } catch (\Throwable $e) {
            $db->execute('ROLLBACK');
            throw $e;
        }
    }

    /**
     * @param list<\Lp\MatterhornImport\DTO\ProductData> $products
     * @param list<array{source_key:string,error:\Throwable}> $batchErrors
     * @param list<array{source_key:string,message:string}> $batchWarnings
     */
    private function flushBatch(int $runId, int $checkpoint, array $products, array $batchErrors, array $batchWarnings, int $total, int $valid, int $invalid): void
    {
        $db = \Db::getInstance();
        if (!$db->execute('START TRANSACTION')) { throw new \RuntimeException('Could not start READ batch transaction'); }
        try {
            $this->snapshots->upsertBatch($runId, $products);
            foreach ($batchErrors as $item) { $this->errors->add($runId, 'read', $item['source_key'], $item['error']); }
            foreach ($batchWarnings as $item) { $this->errors->add($runId, 'read', $item['source_key'], 'WARNING: ' . $item['message']); }
            $this->runs->commitReadProgress($runId, $checkpoint, $total, $valid, $invalid);
            if (!$db->execute('COMMIT')) { throw new \RuntimeException('Could not commit READ batch'); }
        } catch (\Throwable $e) {
            $db->execute('ROLLBACK');
            throw $e;
        }
    }
}
