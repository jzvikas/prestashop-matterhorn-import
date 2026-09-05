<?php
namespace Lp\MatterhornImport\Gc;

use Lp\MatterhornImport\Contract\SourceInterface;
use Lp\MatterhornImport\Image\PrestaImageProcessor;
use Lp\MatterhornImport\Repository\ImageOrphanRepository;
use Lp\MatterhornImport\Repository\ImageQueueRepository;
use Lp\MatterhornImport\Repository\RunRepository;

final class GcService
{
    private const ORPHAN_PAGE_LIMIT = 2000;

    public function __construct(
        private ImageOrphanRepository $imageOrphans,
        private ImageQueueRepository $imageQueue,
        private PrestaImageProcessor $imageProcessor,
        private SourceInterface $sourceAdapter,
        private RunRepository $runs
    ) {
    }

    public function run(int $keepRunId, int $imageDays, int $newProductDays, int $chunk, int $maxRows, int $timeLimitSeconds, ?int $shopId = null): array
    {
        if ($keepRunId < 0 || $imageDays < 0 || $newProductDays < 0 || $chunk < 1 || $maxRows < 0 || $timeLimitSeconds < 0) {
            throw new \InvalidArgumentException('Invalid GC execution limits');
        }
        if ($shopId !== null && $shopId <= 0) { throw new \InvalidArgumentException('GC shop ID must be positive'); }
        $source = trim($this->sourceAdapter->name());
        if ($source === '') { throw new \RuntimeException('GC source name is empty'); }
        if ($keepRunId > 0) {
            if ($shopId === null) {
                throw new \InvalidArgumentException('GC --keep-run requires a concrete --shop so the retention boundary cannot cross shop contexts');
            }
            $this->runs->assertContext($keepRunId, $shopId, $source);
        }
        $chunk = min(10000, $chunk);
        $started = microtime(true);
        $stats = [
            'image_orphans_processed'=>0,'image_orphans_deleted'=>0,'image_orphans_resolved'=>0,'image_orphans_deferred'=>0,
            'images'=>0,'new_products'=>0,'snapshots'=>0,'image_state'=>0,'total'=>0,
        ];

        $orphanStats = $this->drainImageOrphans($chunk, $maxRows, $stats['total'], $started, $timeLimitSeconds, $shopId, $source);
        foreach ($orphanStats as $key => $value) { $stats[$key] = $value; }

        if (!$this->stopped($maxRows, $stats['total'], $started, $timeLimitSeconds)) {
            $stats['images'] = $this->drain(fn(int $limit): int => $this->deleteImageJobs($imageDays, $limit, $shopId, $source), $chunk, $maxRows, $stats['total'], $started, $timeLimitSeconds);
        }
        if (!$this->stopped($maxRows, $stats['total'], $started, $timeLimitSeconds)) {
            $stats['new_products'] = $this->drain(fn(int $limit): int => $this->deleteNewProductJobs($newProductDays, $limit, $shopId, $source), $chunk, $maxRows, $stats['total'], $started, $timeLimitSeconds);
        }
        if ($keepRunId > 0 && !$this->stopped($maxRows, $stats['total'], $started, $timeLimitSeconds)) {
            $stats['snapshots'] = $this->drain(fn(int $limit): int => $this->deleteSnapshots($keepRunId, $limit, $shopId, $source), $chunk, $maxRows, $stats['total'], $started, $timeLimitSeconds);
        }
        if (!$this->stopped($maxRows, $stats['total'], $started, $timeLimitSeconds)) {
            $stats['image_state'] = $this->drain(fn(int $limit): int => $this->deleteImageStateOrphans($limit, $shopId, $source), $chunk, $maxRows, $stats['total'], $started, $timeLimitSeconds);
        }
        $paused = $this->stopped($maxRows, $stats['total'], $started, $timeLimitSeconds);
        $reason = null;
        if ($paused) { $reason = $maxRows > 0 && $stats['total'] >= $maxRows ? 'max_rows' : 'time_limit'; }
        return $stats + ['paused'=>$paused,'reason'=>$reason,'shop_id'=>$shopId,'source'=>$source];
    }

    private function drain(callable $deleteChunk, int $chunk, int $maxRows, int &$total, float $started, int $timeLimitSeconds): int
    {
        $deletedTotal = 0;
        while (!$this->stopped($maxRows, $total, $started, $timeLimitSeconds)) {
            $limit = $maxRows > 0 ? min($chunk, $maxRows - $total) : $chunk;
            if ($limit <= 0) { break; }
            $deleted = $deleteChunk($limit);
            if ($deleted < 0 || $deleted > $limit) { throw new \RuntimeException('GC chunk returned invalid affected rows'); }
            $deletedTotal += $deleted; $total += $deleted;
            if ($deleted < $limit) { break; }
        }
        return $deletedTotal;
    }

    /** @return array{image_orphans_processed:int,image_orphans_deleted:int,image_orphans_resolved:int,image_orphans_deferred:int} */
    private function drainImageOrphans(int $chunk, int $maxRows, int &$total, float $started, int $timeLimitSeconds, ?int $shopId, string $source): array
    {
        $stats = ['image_orphans_processed'=>0,'image_orphans_deleted'=>0,'image_orphans_resolved'=>0,'image_orphans_deferred'=>0];
        while (!$this->stopped($maxRows, $total, $started, $timeLimitSeconds)) {
            $limit = min($chunk, self::ORPHAN_PAGE_LIMIT);
            if ($maxRows > 0) { $limit = min($limit, $maxRows - $total); }
            if ($limit <= 0) { break; }
            $rows = $this->imageOrphans->due($limit, $shopId, $source);
            if ($rows === []) { break; }
            $processed = 0;
            foreach ($rows as $row) {
                if ($this->stopped($maxRows, $total, $started, $timeLimitSeconds)) { break; }
                $processed++; $total++; $stats['image_orphans_processed']++;
                $result = $this->recoverImageOrphan($row);
                $stats[$result]++;
            }
            if ($processed < $limit) { break; }
        }
        return $stats;
    }

    private function recoverImageOrphan(array $row): string
    {
        $idOrphan = (int) ($row['id_orphan'] ?? 0);
        $idImage = (int) ($row['id_image'] ?? 0);
        $productId = (int) ($row['id_product'] ?? 0);
        $shopId = (int) ($row['id_shop'] ?? 0);
        $queueStatus = $this->imageQueue->status((int) ($row['id_queue'] ?? 0));

        if (in_array($queueStatus, ['pending','processing'], true)) {
            $this->imageOrphans->defer($idOrphan, 'image queue job is still active: ' . $queueStatus);
            return 'image_orphans_deferred';
        }
        if ($this->hasImageStateReference($productId, $idImage)) {
            $this->imageOrphans->forget($idOrphan);
            return 'image_orphans_resolved';
        }
        $imageExists = (bool) \Db::getInstance()->getValue(sprintf(
            'SELECT 1 FROM `%simage` WHERE id_image=%d AND id_product=%d', _DB_PREFIX_, $idImage, $productId
        ), false);
        if (!$imageExists) {
            $this->imageOrphans->forget($idOrphan);
            return 'image_orphans_resolved';
        }

        try {
            if ($this->imageProcessor->deleteImage($idImage, $productId, $shopId)) {
                $this->imageOrphans->forget($idOrphan);
                return 'image_orphans_deleted';
            }
            $this->imageOrphans->defer($idOrphan, 'image is not exclusively owned by the target shop; destructive orphan cleanup refused');
        } catch (\Throwable $e) {
            $this->imageOrphans->defer($idOrphan, $e->getMessage());
        }
        return 'image_orphans_deferred';
    }

    private function hasImageStateReference(int $productId, int $idImage): bool
    {
        if ($productId <= 0 || $idImage <= 0) { return false; }
        return (bool) \Db::getInstance()->getValue(sprintf(
            'SELECT 1 FROM `%sli_matterhornim_99dfbf_image_state` WHERE id_product=%d AND id_image=%d',
            _DB_PREFIX_, $productId, $idImage
        ), false);
    }

    private function stopped(int $maxRows, int $total, float $started, int $timeLimitSeconds): bool
    {
        return ($maxRows > 0 && $total >= $maxRows) || ($timeLimitSeconds > 0 && microtime(true) - $started >= $timeLimitSeconds);
    }

    private function deleteImageJobs(int $days, int $limit, ?int $shopId, string $source): int
    {
        $queueTable = _DB_PREFIX_ . 'li_matterhornim_99dfbf_image_queue';
        $where = "status='done' AND source='" . pSQL($source) . "' AND updated_at<DATE_SUB(NOW(),INTERVAL " . $days . ' DAY)' . ($shopId === null ? '' : ' AND id_shop=' . $shopId);
        $where .= ' AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'li_matterhornim_99dfbf_image_orphan` o WHERE o.id_queue=`' . $queueTable . '`.id_queue)';
        return $this->deleteBounded('li_matterhornim_99dfbf_image_queue', $where, 'id_queue', $limit);
    }

    private function deleteNewProductJobs(int $days, int $limit, ?int $shopId, string $source): int
    {
        $table = _DB_PREFIX_ . 'li_matterhornim_99dfbf_new_product_queue';
        $where = "status='done' AND source='" . pSQL($source) . "' AND id_product IS NOT NULL AND updated_at<DATE_SUB(NOW(),INTERVAL " . $days . ' DAY)' . ($shopId === null ? '' : ' AND id_shop=' . $shopId);
        $where .= " AND EXISTS (SELECT 1 FROM `" . _DB_PREFIX_ . "li_matterhornim_99dfbf_mapping` m WHERE m.id_shop=`{$table}`.id_shop AND m.source=`{$table}`.source AND m.source_key=`{$table}`.source_key AND m.id_product=`{$table}`.id_product)";
        return $this->deleteBounded('li_matterhornim_99dfbf_new_product_queue', $where, 'id_queue', $limit);
    }

    private function deleteSnapshots(int $keepRunId, int $limit, ?int $shopId, string $source): int
    {
        if ($shopId === null) { throw new \LogicException('Snapshot GC requires a concrete shop retention context'); }
        $runTable = _DB_PREFIX_ . 'li_matterhornim_99dfbf_run';
        $snapshotTable = _DB_PREFIX_ . 'li_matterhornim_99dfbf_snapshot';
        $scopeWhere = " AND r.source='" . pSQL($source) . "' AND r.id_shop=" . $shopId;

        $sql = 'DELETE FROM `' . $snapshotTable . '` WHERE id_run<' . $keepRunId .
            ' AND id_run IN (' .
            'SELECT r.id_run FROM `' . $runTable . '` r WHERE 1=1' . $scopeWhere .
            ' AND EXISTS (SELECT 1 FROM `' . $runTable . '` newer ' .
            'WHERE newer.id_shop=r.id_shop AND newer.source=r.source AND newer.id_run>r.id_run)' .
            ') ORDER BY id_run,source_key LIMIT ' . $limit;
        $db = \Db::getInstance();
        if (!$db->execute($sql)) { throw new \RuntimeException('Bounded snapshot GC failed'); }
        return (int) $db->Affected_Rows();
    }

    private function deleteImageStateOrphans(int $limit, ?int $shopId, string $source): int
    {
        $db = \Db::getInstance();
        $scopeWhere = " AND s.source='" . pSQL($source) . "'" . ($shopId === null ? '' : ' AND s.id_shop=' . $shopId);
        $rows = $db->executeS(
            'SELECT s.id_shop,s.source,s.source_key,s.url_hash FROM `' . _DB_PREFIX_ . 'li_matterhornim_99dfbf_image_state` s ' .
            'LEFT JOIN `' . _DB_PREFIX_ . 'li_matterhornim_99dfbf_mapping` m ON m.id_shop=s.id_shop AND m.source=s.source AND m.source_key=s.source_key AND m.id_product=s.id_product ' .
            'LEFT JOIN `' . _DB_PREFIX_ . 'image` i ON i.id_image=s.id_image AND i.id_product=s.id_product ' .
            'LEFT JOIN `' . _DB_PREFIX_ . 'image_shop` ish ON ish.id_image=s.id_image AND ish.id_shop=s.id_shop ' .
            'WHERE (m.source_key IS NULL OR i.id_image IS NULL OR ish.id_image IS NULL)' . $scopeWhere . ' ' .
            'ORDER BY s.id_shop,s.source,s.source_key,s.url_hash LIMIT ' . $limit,
            true,
            false
        ) ?: [];
        if ($rows === []) { return 0; }
        $keys = [];
        foreach ($rows as $row) {
            $keys[] = sprintf("(%d,'%s','%s','%s')", (int)$row['id_shop'], pSQL((string)$row['source']), pSQL((string)$row['source_key']), pSQL((string)$row['url_hash']));
        }

        $state = _DB_PREFIX_ . 'li_matterhornim_99dfbf_image_state';
        $mapping = _DB_PREFIX_ . 'li_matterhornim_99dfbf_mapping';
        $image = _DB_PREFIX_ . 'image';
        $imageShop = _DB_PREFIX_ . 'image_shop';
        $sql = 'DELETE FROM `' . $state . '` WHERE (id_shop,source,source_key,url_hash) IN (' . implode(',', $keys) . ')' .
            ' AND (' .
            'NOT EXISTS (SELECT 1 FROM `' . $mapping . '` m WHERE m.id_shop=`' . $state . '`.id_shop ' .
            'AND m.source=`' . $state . '`.source AND m.source_key=`' . $state . '`.source_key AND m.id_product=`' . $state . '`.id_product)' .
            ' OR NOT EXISTS (SELECT 1 FROM `' . $image . '` i WHERE i.id_image=`' . $state . '`.id_image AND i.id_product=`' . $state . '`.id_product)' .
            ' OR NOT EXISTS (SELECT 1 FROM `' . $imageShop . '` ish WHERE ish.id_image=`' . $state . '`.id_image AND ish.id_shop=`' . $state . '`.id_shop)' .
            ')';
        if (!$db->execute($sql)) { throw new \RuntimeException('Bounded image-state GC failed'); }
        return (int) $db->Affected_Rows();
    }

    private function deleteBounded(string $table, string $where, string $order, int $limit): int
    {
        $db = \Db::getInstance();
        if (!$db->execute('DELETE FROM `' . _DB_PREFIX_ . $table . '` WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . $limit)) { throw new \RuntimeException('Bounded queue GC failed for ' . $table); }
        return (int) $db->Affected_Rows();
    }
}
