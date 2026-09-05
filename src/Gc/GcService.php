<?php
namespace Lp\MatterhornImport\Gc;

final class GcService
{
    public function run(int $keepRunId, int $imageDays, int $newProductDays, int $chunk, int $maxRows, int $timeLimitSeconds, ?int $shopId = null): array
    {
        if ($keepRunId < 0 || $imageDays < 0 || $newProductDays < 0 || $chunk < 1 || $maxRows < 0 || $timeLimitSeconds < 0) {
            throw new \InvalidArgumentException('Invalid GC execution limits');
        }
        if ($shopId !== null && $shopId <= 0) { throw new \InvalidArgumentException('GC shop ID must be positive'); }
        $chunk = min(10000, $chunk);
        $started = microtime(true);
        $stats = ['images'=>0,'new_products'=>0,'snapshots'=>0,'image_state'=>0,'total'=>0];

        $stats['images'] = $this->drain(fn(int $limit): int => $this->deleteImageJobs($imageDays, $limit, $shopId), $chunk, $maxRows, $stats['total'], $started, $timeLimitSeconds);
        if (!$this->stopped($maxRows, $stats['total'], $started, $timeLimitSeconds)) {
            $stats['new_products'] = $this->drain(fn(int $limit): int => $this->deleteNewProductJobs($newProductDays, $limit, $shopId), $chunk, $maxRows, $stats['total'], $started, $timeLimitSeconds);
        }
        if ($keepRunId > 0 && !$this->stopped($maxRows, $stats['total'], $started, $timeLimitSeconds)) {
            $stats['snapshots'] = $this->drain(fn(int $limit): int => $this->deleteSnapshots($keepRunId, $limit, $shopId), $chunk, $maxRows, $stats['total'], $started, $timeLimitSeconds);
        }
        if (!$this->stopped($maxRows, $stats['total'], $started, $timeLimitSeconds)) {
            $stats['image_state'] = $this->drain(fn(int $limit): int => $this->deleteImageStateOrphans($limit, $shopId), $chunk, $maxRows, $stats['total'], $started, $timeLimitSeconds);
        }
        $paused = $this->stopped($maxRows, $stats['total'], $started, $timeLimitSeconds);
        $reason = null;
        if ($paused) { $reason = $maxRows > 0 && $stats['total'] >= $maxRows ? 'max_rows' : 'time_limit'; }
        return $stats + ['paused'=>$paused,'reason'=>$reason,'shop_id'=>$shopId];
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

    private function stopped(int $maxRows, int $total, float $started, int $timeLimitSeconds): bool
    {
        return ($maxRows > 0 && $total >= $maxRows) || ($timeLimitSeconds > 0 && microtime(true) - $started >= $timeLimitSeconds);
    }

    private function deleteImageJobs(int $days, int $limit, ?int $shopId): int
    {
        return $this->deleteBounded('li_matterhornim_99dfbf_image_queue', "status='done' AND updated_at<DATE_SUB(NOW(),INTERVAL " . $days . ' DAY)' . ($shopId === null ? '' : ' AND id_shop=' . $shopId), 'id_queue', $limit);
    }

    private function deleteNewProductJobs(int $days, int $limit, ?int $shopId): int
    {
        $table = _DB_PREFIX_ . 'li_matterhornim_99dfbf_new_product_queue';
        $where = "status='done' AND id_product IS NOT NULL AND updated_at<DATE_SUB(NOW(),INTERVAL " . $days . ' DAY)' . ($shopId === null ? '' : ' AND id_shop=' . $shopId);
        $where .= " AND EXISTS (SELECT 1 FROM `" . _DB_PREFIX_ . "li_matterhornim_99dfbf_mapping` m WHERE m.id_shop=`{$table}`.id_shop AND m.source=`{$table}`.source AND m.source_key=`{$table}`.source_key AND m.id_product=`{$table}`.id_product)";
        return $this->deleteBounded('li_matterhornim_99dfbf_new_product_queue', $where, 'id_queue', $limit);
    }

    private function deleteSnapshots(int $keepRunId, int $limit, ?int $shopId): int
    {
        $where = 'id_run<' . $keepRunId;
        if ($shopId !== null) { $where .= ' AND id_run IN (SELECT id_run FROM `' . _DB_PREFIX_ . 'li_matterhornim_99dfbf_run` WHERE id_shop=' . $shopId . ')'; }
        $db = \Db::getInstance();
        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'li_matterhornim_99dfbf_snapshot` WHERE ' . $where . ' ORDER BY id_run,source_key LIMIT ' . $limit;
        if (!$db->execute($sql)) { throw new \RuntimeException('Bounded snapshot GC failed'); }
        return (int) $db->Affected_Rows();
    }

    private function deleteImageStateOrphans(int $limit, ?int $shopId): int
    {
        $db = \Db::getInstance();
        $shopWhere = $shopId === null ? '' : ' AND s.id_shop=' . $shopId;
        $rows = $db->executeS('SELECT s.id_shop,s.source,s.source_key,s.url_hash FROM `' . _DB_PREFIX_ . 'li_matterhornim_99dfbf_image_state` s LEFT JOIN `' . _DB_PREFIX_ . 'li_matterhornim_99dfbf_mapping` m ON m.id_shop=s.id_shop AND m.source=s.source AND m.source_key=s.source_key AND m.id_product=s.id_product LEFT JOIN `' . _DB_PREFIX_ . 'image` i ON i.id_image=s.id_image AND i.id_product=s.id_product LEFT JOIN `' . _DB_PREFIX_ . 'image_shop` ish ON ish.id_image=s.id_image AND ish.id_shop=s.id_shop WHERE (m.source_key IS NULL OR i.id_image IS NULL OR ish.id_image IS NULL)' . $shopWhere . ' ORDER BY s.id_shop,s.source,s.source_key,s.url_hash LIMIT ' . $limit) ?: [];
        if ($rows === []) { return 0; }
        $keys = [];
        foreach ($rows as $row) { $keys[] = sprintf("(%d,'%s','%s','%s')", (int)$row['id_shop'], pSQL((string)$row['source']), pSQL((string)$row['source_key']), pSQL((string)$row['url_hash'])); }
        if (!$db->execute('DELETE FROM `' . _DB_PREFIX_ . 'li_matterhornim_99dfbf_image_state` WHERE (id_shop,source,source_key,url_hash) IN (' . implode(',', $keys) . ')')) { throw new \RuntimeException('Bounded image-state GC failed'); }
        return (int) $db->Affected_Rows();
    }

    private function deleteBounded(string $table, string $where, string $order, int $limit): int
    {
        $db = \Db::getInstance();
        if (!$db->execute('DELETE FROM `' . _DB_PREFIX_ . $table . '` WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . $limit)) { throw new \RuntimeException('Bounded queue GC failed for ' . $table); }
        return (int) $db->Affected_Rows();
    }
}
