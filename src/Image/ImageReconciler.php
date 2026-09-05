<?php
namespace Lp\MatterhornImport\Image;

use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Lock\ImportLock;
use Lp\MatterhornImport\Repository\ImageQueueRepository;
use Lp\MatterhornImport\Repository\ImageStateRepository;
use Lp\MatterhornImport\Repository\RunRepository;
use Lp\MatterhornImport\Repository\SnapshotRepository;
use Lp\MatterhornImport\Util\DatabaseSafety;

final class ImageReconciler
{
    private const IMAGE_STATE_TABLE = 'li_matterhornim_99dfbf_image_state';

    public function __construct(
        private RunRepository $runs,
        private SnapshotRepository $snapshots,
        private ImageQueueRepository $queue,
        private ImageStateRepository $state,
        private PrestaImageProcessor $processor,
        private ImportLock $lock,
        private DatabaseSafety $safety
    ) {
    }

    public function run(int $runId, int $shopId, int $batch = 500): array
    {
        $this->safety->assertTransactionalCore();
        $run = $this->runs->get($runId);
        if (!$run || (int) $run['id_shop'] !== $shopId) { throw new \RuntimeException('Image reconcile run/shop mismatch'); }
        $source = (string) $run['source'];
        $this->assertReady($runId, $shopId, $source);
        if (!$this->lock->acquire($shopId, $source, 0)) { throw new \RuntimeException('Import/reconciliation lock is busy'); }

        $batch = max(1, min(2000, $batch));
        $cursor = '';
        $products = $statesRemoved = $imagesDeleted = $shopLinksDetached = $placementsSynced = 0;
        try {
            $this->assertReady($runId, $shopId, $source);
            while (true) {
                $rows = $this->snapshots->imageManifestRows($runId, $shopId, $source, $cursor, $batch);
                if ($rows === []) { break; }
                foreach ($rows as $row) {
                    $cursor = (string) $row['source_key'];
                    $result = $this->reconcileProduct($runId, $shopId, $source, (string) $row['source_key'], (int) $row['id_product'], ProductData::fromJson((string) $row['payload']));
                    $products++;
                    $statesRemoved += $result['states_removed'];
                    $imagesDeleted += $result['images_deleted'];
                    $shopLinksDetached += $result['shop_links_detached'];
                    $placementsSynced += $result['placements_synced'];
                }
            }
        } finally {
            $this->lock->release();
        }

        return ['products'=>$products,'states_removed'=>$statesRemoved,'images_deleted'=>$imagesDeleted,'shop_links_detached'=>$shopLinksDetached,'placements_synced'=>$placementsSynced];
    }

    private function assertReady(int $runId, int $shopId, string $source): void
    {
        $run = $this->runs->get($runId);
        if (!$run || (int) $run['id_shop'] !== $shopId || (string) $run['source'] !== $source) { throw new \RuntimeException('Image reconcile run/shop/source changed'); }
        foreach (['read_status','import_status','update_status','remove_status'] as $field) {
            if ((string) ($run[$field] ?? '') !== 'completed') { throw new \RuntimeException('Image reconciliation requires completed READ/IMPORT/UPDATE/REMOVE'); }
        }
        $latest = $this->runs->latest($shopId, $source);
        if (!$latest || (int) $latest['id_run'] !== $runId) { throw new \RuntimeException('Only the latest shop/source run may reconcile images'); }
        if ($this->queue->unresolvedForRun($runId, $shopId) !== 0) { throw new \RuntimeException('Image reconciliation blocked until all run image jobs are done'); }
    }

    private function reconcileProduct(int $runId, int $shopId, string $source, string $sourceKey, int $productId, ProductData $product): array
    {
        $urls = array_values(array_unique(array_filter(array_map(static fn(mixed $url): string => trim((string) $url), $product->images))));
        $desired = [];
        foreach ($urls as $position => $url) { $desired[hash('sha256', $url)] = ['position'=>$position,'is_cover'=>$position===0]; }
        $states = $this->state->statesForProduct($shopId, $source, $sourceKey, $productId);
        $byHash = [];
        foreach ($states as $state) { $byHash[(string) $state['url_hash']] = $state; }
        foreach ($desired as $urlHash => $_placement) {
            if (!isset($byHash[$urlHash]) || (int) $byHash[$urlHash]['last_seen_run_id'] !== $runId) { throw new \RuntimeException('Desired image state is incomplete for ' . $sourceKey); }
        }

        $statesRemoved = $imagesDeleted = $shopLinksDetached = 0;
        foreach ($states as $state) {
            $urlHash = (string) $state['url_hash'];
            if (isset($desired[$urlHash])) { continue; }
            $idImage = (int) $state['id_image'];
            if ($this->state->canDeleteStateImage($shopId, $source, $sourceKey, $productId, $idImage, $urlHash)) {
                if ($this->processor->deleteImage($idImage, $productId, $shopId)) { $imagesDeleted++; }
            } elseif (!$this->hasOtherTargetShopStateRef($shopId, $productId, $idImage, $source, $sourceKey, $urlHash)) {
                if ($this->detachTargetShop($shopId, $productId, $idImage)) { $shopLinksDetached++; }
            }
            $this->state->deleteState($shopId, $source, $sourceKey, $productId, $urlHash);
            $statesRemoved++;
        }

        $placements = [];
        foreach ($this->state->statesForProduct($shopId, $source, $sourceKey, $productId) as $state) {
            $urlHash = (string) $state['url_hash'];
            if (!isset($desired[$urlHash])) { continue; }
            $placements[] = ['id_image'=>(int)$state['id_image'],'position'=>(int)$desired[$urlHash]['position'],'is_cover'=>(bool)$desired[$urlHash]['is_cover']];
        }
        $this->processor->syncProductPlacement($productId, $shopId, $placements);
        return ['states_removed'=>$statesRemoved,'images_deleted'=>$imagesDeleted,'shop_links_detached'=>$shopLinksDetached,'placements_synced'=>count($placements)];
    }

    private function hasOtherTargetShopStateRef(int $shopId, int $productId, int $idImage, string $source, string $sourceKey, string $urlHash): bool
    {
        return (bool) \Db::getInstance()->getValue(sprintf("SELECT 1 FROM `%s%s` WHERE id_shop=%d AND id_product=%d AND id_image=%d AND NOT (source='%s' AND source_key='%s' AND url_hash='%s')", _DB_PREFIX_, self::IMAGE_STATE_TABLE, $shopId, $productId, $idImage, pSQL($source), pSQL($sourceKey), pSQL($urlHash)));
    }

    private function detachTargetShop(int $shopId, int $productId, int $idImage): bool
    {
        $db = \Db::getInstance();
        if (!(bool) $db->getValue(sprintf('SELECT 1 FROM `%simage` WHERE id_image=%d AND id_product=%d', _DB_PREFIX_, $idImage, $productId))) { return false; }
        $shopCount = (int) $db->getValue(sprintf('SELECT COUNT(*) FROM `%simage_shop` WHERE id_image=%d', _DB_PREFIX_, $idImage));
        if ($shopCount <= 1) { return false; }
        if (!$db->delete('image_shop', 'id_image=' . $idImage . ' AND id_product=' . $productId . ' AND id_shop=' . $shopId)) { throw new \RuntimeException('Cannot detach stale image from target shop'); }
        return (int) $db->Affected_Rows() === 1;
    }
}
