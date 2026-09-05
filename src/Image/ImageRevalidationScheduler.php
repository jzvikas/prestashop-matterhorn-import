<?php
namespace Lp\MatterhornImport\Image;

use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Lock\ImportLock;
use Lp\MatterhornImport\Repository\ImageQueueRepository;
use Lp\MatterhornImport\Repository\ImageStateRepository;
use Lp\MatterhornImport\Repository\RunRepository;
use Lp\MatterhornImport\Repository\SnapshotRepository;

final class ImageRevalidationScheduler
{
    public function __construct(
        private RunRepository $runs,
        private SnapshotRepository $snapshots,
        private ImageStateRepository $state,
        private ImageQueueRepository $queue,
        private ImportLock $lock
    ) {}

    /** @return array{run_id:int,age_hours:int,candidates:int,scheduled_products:int,scheduled_images:int,payload_window_deferred:int} */
    public function schedule(int $shopId, string $source, int $ageHours = 24, int $limit = 500): array
    {
        if ($shopId <= 0 || trim($source) === '') { throw new \InvalidArgumentException('Image revalidation requires shop/source'); }
        $ageHours = max(1, min(87600, $ageHours));
        $limit = max(1, min(5000, $limit));

        if (!$this->lock->acquire($shopId, $source, 0)) {
            throw new \RuntimeException('Import/image revalidation lock is busy');
        }

        try {
            $run = $this->runs->latest($shopId, $source);
            if (!is_array($run)) { throw new \RuntimeException('Image revalidation requires an existing completed run'); }
            $runId = (int) ($run['id_run'] ?? 0);
            if ($runId <= 0) { throw new \RuntimeException('Image revalidation resolved an invalid run'); }
            foreach (['read_status','import_status','update_status','remove_status'] as $field) {
                if ((string) ($run[$field] ?? '') !== 'completed') {
                    throw new \RuntimeException('Image revalidation requires completed READ/IMPORT/UPDATE/REMOVE');
                }
            }
            if ((string) ($run['status'] ?? '') !== 'completed') {
                throw new \RuntimeException('Image revalidation requires a completed latest run');
            }
            if ((string) ($run['image_reconcile_status'] ?? '') !== 'completed') {
                throw new \RuntimeException('Image revalidation requires completed image reconciliation for the latest run');
            }

            $keys = $this->state->staleSourceKeys($shopId, $source, $ageHours, $limit);
            if ($keys === []) {
                return [
                    'run_id'=>$runId,'age_hours'=>$ageHours,'candidates'=>0,
                    'scheduled_products'=>0,'scheduled_images'=>0,'payload_window_deferred'=>0,
                ];
            }

            $rows = $this->snapshots->imageManifestRowsForSourceKeys($runId, $shopId, $source, $keys, $limit);
            if ($rows === []) {
                throw new \RuntimeException('Stale image states have no matching latest-run snapshot manifest');
            }

            // imageManifestRowsForSourceKeys is source-key ordered and can stop early only because
            // of the bounded payload window. Missing requested keys at or before the returned cursor
            // are therefore integrity errors, while greater keys are legitimately deferred.
            $returnedKeys = [];
            $lastReturnedKey = '';
            foreach ($rows as $row) {
                $rowKey = trim((string) ($row['source_key'] ?? ''));
                if ($rowKey === '') { throw new \RuntimeException('Latest-run image manifest returned an empty source key'); }
                $returnedKeys[$rowKey] = true;
                $lastReturnedKey = $rowKey;
            }
            $sortedKeys = $keys;
            sort($sortedKeys, SORT_STRING);
            foreach ($sortedKeys as $key) {
                if (strcmp($key, $lastReturnedKey) > 0) { break; }
                if (!isset($returnedKeys[$key])) {
                    throw new \RuntimeException('Stale image state is missing from latest-run snapshot manifest: ' . $key);
                }
            }

            $jobs = [];
            $scheduledImages = 0;
            foreach ($rows as $row) {
                $sourceKey = trim((string) ($row['source_key'] ?? ''));
                $productId = (int) ($row['id_product'] ?? 0);
                if ($sourceKey === '' || $productId <= 0) {
                    throw new \RuntimeException('Latest-run image manifest contains an invalid mapped product row');
                }
                $product = ProductData::fromJson((string) ($row['payload'] ?? ''));
                $urls = array_values(array_unique(array_filter(
                    array_map(static fn(mixed $url): string => trim((string) $url), $product->images),
                    static fn(string $url): bool => $url !== ''
                )));
                if ($urls === []) {
                    // A completed reconciliation should already have removed such states.
                    // Fail closed rather than repeatedly selecting an impossible stale state.
                    throw new \RuntimeException('Stale image state has no desired latest-run manifest for ' . $sourceKey);
                }
                $jobs[] = ['source_key'=>$sourceKey,'id_product'=>$productId,'urls'=>$urls];
                $scheduledImages += count($urls);
            }

            $this->queue->enqueueBatch($runId, $shopId, $source, $jobs);

            return [
                'run_id'=>$runId,
                'age_hours'=>$ageHours,
                'candidates'=>count($keys),
                'scheduled_products'=>count($jobs),
                'scheduled_images'=>$scheduledImages,
                'payload_window_deferred'=>max(0, count($keys) - count($rows)),
            ];
        } finally {
            $this->lock->release();
        }
    }
}
