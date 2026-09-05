<?php
namespace Lp\MatterhornImport\Image;

use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Lock\ImportLock;
use Lp\MatterhornImport\Repository\ErrorRepository;
use Lp\MatterhornImport\Repository\ImageQueueRepository;
use Lp\MatterhornImport\Repository\ImageStateRepository;
use Lp\MatterhornImport\Repository\RunRepository;
use Lp\MatterhornImport\Repository\SnapshotRepository;
use Lp\MatterhornImport\Util\DatabaseSafety;
use Lp\MatterhornImport\Util\ExecutionBudget;

final class ImageReconciler
{
    public function __construct(
        private RunRepository $runs,
        private SnapshotRepository $snapshots,
        private ImageQueueRepository $queue,
        private ImageStateRepository $state,
        private PrestaImageProcessor $processor,
        private ErrorRepository $errors,
        private ImportLock $lock,
        private DatabaseSafety $safety,
        private ExecutionBudget $budget
    ) {
    }

    public function run(int $runId, int $shopId, int $batch = 500, int $maxItems = 0, int $timeLimitSeconds = 0): array
    {
        $this->safety->assertTransactionalCore();
        $run = $this->runs->get($runId);
        if (!$run || (int) $run['id_shop'] !== $shopId) {
            throw new \RuntimeException('Image reconcile run/shop mismatch');
        }
        $source = (string) $run['source'];
        $status = (string) ($run['image_reconcile_status'] ?? 'pending');
        if ($status === 'completed') {
            return [
                'status' => 'completed',
                'stop_reason' => null,
                'checkpoint' => (string) ($run['image_reconcile_checkpoint'] ?? ''),
                'total_done' => (int) ($run['image_reconcile_done'] ?? 0),
                'products' => 0,
                'states_removed' => 0,
                'images_deleted' => 0,
                'shop_links_detached' => 0,
                'placements_synced' => 0,
            ];
        }

        $this->assertReady($runId, $shopId, $source);
        if (!$this->lock->acquire($shopId, $source, 0)) {
            throw new \RuntimeException('Import/reconciliation lock is busy');
        }

        $batch = max(1, min(2000, $batch));
        $this->budget->start(max(0, $maxItems), max(0, $timeLimitSeconds));
        $products = $statesRemoved = $imagesDeleted = $shopLinksDetached = $placementsSynced = 0;
        $paused = false;
        $started = false;
        $currentSourceKey = null;

        try {
            $this->assertReady($runId, $shopId, $source);
            $run = $this->runs->get($runId);
            if (!$run) { throw new \RuntimeException('Image reconciliation run disappeared'); }
            $cursor = trim((string) ($run['image_reconcile_checkpoint'] ?? ''));
            $this->runs->imageReconcileStart($runId);
            $started = true;

            while (true) {
                if ($this->budget->shouldStop()) { $paused = true; break; }
                $rows = $this->snapshots->imageManifestRows($runId, $shopId, $source, $cursor, $batch);
                if ($rows === []) { break; }

                foreach ($rows as $row) {
                    if ($this->budget->shouldStop()) { $paused = true; break 2; }
                    $sourceKey = (string) $row['source_key'];
                    $currentSourceKey = $sourceKey;
                    $product = ProductData::fromJson((string) $row['payload']);
                    $result = $this->reconcileProduct(
                        $runId,
                        $shopId,
                        $source,
                        $sourceKey,
                        (int) $row['id_product'],
                        $product
                    );

                    // Checkpoint only after the complete per-product reconciliation. A crash
                    // before this write replays the same product, which is deliberately safe.
                    $this->runs->imageReconcileCheckpoint($runId, $sourceKey);
                    $cursor = $sourceKey;
                    $currentSourceKey = null;
                    $this->budget->markItem();
                    $products++;
                    $statesRemoved += $result['states_removed'];
                    $imagesDeleted += $result['images_deleted'];
                    $shopLinksDetached += $result['shop_links_detached'];
                    $placementsSynced += $result['placements_synced'];
                }
            }

            $this->runs->imageReconcileFinish($runId, $paused ? 'paused' : 'completed');
        } catch (\Throwable $e) {
            try { $this->errors->add($runId, 'image', $currentSourceKey, $e); }
            catch (\Throwable $logError) { error_log('[matterhornimport] image reconcile error persistence failed: ' . $logError->getMessage()); }
            if ($started) {
                try { $this->runs->imageReconcileFinish($runId, 'failed'); }
                catch (\Throwable $stateError) { error_log('[matterhornimport] image reconcile failure-state persistence failed: ' . $stateError->getMessage()); }
            }
            throw $e;
        } finally {
            $this->lock->release();
        }

        $final = $this->runs->get($runId) ?? [];
        return [
            'status' => $paused ? 'paused' : 'completed',
            'stop_reason' => $paused ? $this->budget->reason() : null,
            'checkpoint' => (string) ($final['image_reconcile_checkpoint'] ?? ''),
            'total_done' => (int) ($final['image_reconcile_done'] ?? 0),
            'products' => $products,
            'states_removed' => $statesRemoved,
            'images_deleted' => $imagesDeleted,
            'shop_links_detached' => $shopLinksDetached,
            'placements_synced' => $placementsSynced,
        ];
    }

    private function assertReady(int $runId, int $shopId, string $source): void
    {
        $run = $this->runs->get($runId);
        if (!$run || (int) $run['id_shop'] !== $shopId || (string) $run['source'] !== $source) {
            throw new \RuntimeException('Image reconcile run/shop/source changed');
        }
        foreach (['read_status','import_status','update_status','remove_status'] as $field) {
            if ((string) ($run[$field] ?? '') !== 'completed') {
                throw new \RuntimeException('Image reconciliation requires completed READ/IMPORT/UPDATE/REMOVE');
            }
        }
        if ((string) ($run['status'] ?? '') !== 'completed') {
            throw new \RuntimeException('Image reconciliation requires a completed import run');
        }
        $latest = $this->runs->latest($shopId, $source);
        if (!$latest || (int) $latest['id_run'] !== $runId) {
            throw new \RuntimeException('Only the latest shop/source run may reconcile images');
        }
        if ($this->queue->unresolvedForRun($runId, $shopId) !== 0) {
            throw new \RuntimeException('Image reconciliation blocked until all run image jobs are done');
        }
        if ($this->queue->unresolvedForSource($shopId, $source) !== 0) {
            throw new \RuntimeException('Image reconciliation blocked until all shop/source image jobs are done');
        }
    }

    /** @return array{states_removed:int,images_deleted:int,shop_links_detached:int,placements_synced:int} */
    private function reconcileProduct(int $runId, int $shopId, string $source, string $sourceKey, int $productId, ProductData $product): array
    {
        if ($productId <= 0) { throw new \RuntimeException('Image reconciliation received invalid product ID for ' . $sourceKey); }
        $urls = array_values(array_unique(array_filter(
            array_map(static fn(mixed $url): string => trim((string) $url), $product->images),
            static fn(string $url): bool => $url !== ''
        )));
        $desired = [];
        foreach ($urls as $position => $url) {
            $desired[hash('sha256', $url)] = ['position' => $position, 'is_cover' => $position === 0];
        }

        $states = $this->state->statesForProduct($shopId, $source, $sourceKey, $productId);
        $byHash = [];
        foreach ($states as $state) { $byHash[(string) $state['url_hash']] = $state; }
        foreach ($desired as $urlHash => $placement) {
            if (!isset($byHash[$urlHash]) || (int) ($byHash[$urlHash]['last_seen_run_id'] ?? 0) <= 0) {
                throw new \RuntimeException('Desired image state is incomplete for ' . $sourceKey);
            }
        }

        $statesRemoved = $imagesDeleted = $shopLinksDetached = 0;
        foreach ($states as $state) {
            $urlHash = (string) $state['url_hash'];
            if (isset($desired[$urlHash])) { continue; }
            $idImage = (int) $state['id_image'];
            if ($this->state->canDeleteStateImage($shopId, $source, $sourceKey, $productId, $idImage, $urlHash)) {
                if ($this->processor->deleteImage($idImage, $productId, $shopId)) { $imagesDeleted++; }
            } elseif (!$this->state->hasOtherTargetShopStateRef($shopId, $productId, $idImage, $source, $sourceKey, $urlHash)) {
                if ($this->detachTargetShop($shopId, $productId, $idImage)) { $shopLinksDetached++; }
            }
            $this->state->deleteState($shopId, $source, $sourceKey, $productId, $urlHash);
            $statesRemoved++;
        }

        $placements = [];
        if ($desired !== []) {
            foreach ($this->state->statesForProduct($shopId, $source, $sourceKey, $productId) as $state) {
                $urlHash = (string) $state['url_hash'];
                if (!isset($desired[$urlHash])) { continue; }
                $placements[] = [
                    'id_image' => (int) $state['id_image'],
                    'position' => (int) $desired[$urlHash]['position'],
                    'is_cover' => (bool) $desired[$urlHash]['is_cover'],
                ];
            }
            $this->processor->syncProductPlacement($productId, $shopId, $placements);
        }

        return [
            'states_removed' => $statesRemoved,
            'images_deleted' => $imagesDeleted,
            'shop_links_detached' => $shopLinksDetached,
            'placements_synced' => count($placements),
        ];
    }

    private function detachTargetShop(int $shopId, int $productId, int $idImage): bool
    {
        if ($shopId <= 0 || $productId <= 0 || $idImage <= 0) { return false; }
        $db = \Db::getInstance();
        if (!(bool) $db->getValue(sprintf('SELECT 1 FROM `%simage` WHERE id_image=%d AND id_product=%d', _DB_PREFIX_, $idImage, $productId))) { return false; }
        $shopCount = (int) $db->getValue(sprintf('SELECT COUNT(*) FROM `%simage_shop` WHERE id_image=%d', _DB_PREFIX_, $idImage));
        if ($shopCount <= 1) { return false; }
        if (!$db->delete('image_shop', 'id_image=' . $idImage . ' AND id_product=' . $productId . ' AND id_shop=' . $shopId)) {
            throw new \RuntimeException('Cannot detach stale image from target shop');
        }
        return (int) $db->Affected_Rows() === 1;
    }
}
