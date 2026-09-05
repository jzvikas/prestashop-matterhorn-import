<?php
namespace Lp\MatterhornImport\Repository;

final class NewProductQueueRepository
{
    private const TABLE = 'li_matterhornim_99dfbf_new_product_queue';
    private const LEASE_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;
    private const ENQUEUE_CHUNK = 500;

    public function enqueueBatch(int $runId, int $shopId, string $source, array $rows): int
    {
        if ($rows === []) { return 0; }
        $values = [];
        foreach ($rows as $row) {
            $values[] = sprintf("(%d,%d,'%s','%s','%s','%s','pending',0,NULL,NULL,NULL,NULL,NOW(),NOW())", $runId, $shopId, pSQL($source), pSQL((string) $row['source_key']), pSQL((string) $row['payload'], true), pSQL((string) $row['payload_hash']));
            if (count($values) >= self::ENQUEUE_CHUNK) { $this->insertValues($values); $values = []; }
        }
        if ($values !== []) { $this->insertValues($values); }
        return count($rows);
    }

    public function claim(string $worker, int $limit = 20, ?int $shopId = null): array
    {
        $limit = max(1, min(200, $limit));
        $shopWhere = $shopId === null ? '' : ' AND id_shop=' . (int) $shopId;
        $token = $this->claimToken($worker);
        if (!\Db::getInstance()->execute(sprintf("UPDATE `%s%s` SET status='processing',locked_by='%s',locked_until=DATE_ADD(NOW(),INTERVAL %d MINUTE),available_at=NULL,attempts=attempts+1,updated_at=NOW() WHERE ((status='pending' AND (available_at IS NULL OR available_at<=NOW())) OR (status='processing' AND locked_until<=NOW())) AND attempts<%d%s ORDER BY id_queue LIMIT %d", _DB_PREFIX_, self::TABLE, pSQL($token), self::LEASE_MINUTES, self::MAX_ATTEMPTS, $shopWhere, $limit))) {
            throw new \RuntimeException('Matterhorn new-product queue claim failed');
        }
        return \Db::getInstance()->executeS(sprintf("SELECT * FROM `%s%s` WHERE status='processing' AND locked_by='%s' AND locked_until>NOW()%s ORDER BY id_queue LIMIT %d", _DB_PREFIX_, self::TABLE, pSQL($token), $shopWhere, $limit), true, false) ?: [];
    }

    public function renew(int $id, string $token): bool
    {
        $db = \Db::getInstance();
        if (!$db->execute(sprintf("UPDATE `%s%s` SET locked_until=DATE_ADD(NOW(),INTERVAL %d MINUTE),updated_at=NOW() WHERE id_queue=%d AND status='processing' AND locked_by='%s' AND locked_until>NOW()", _DB_PREFIX_, self::TABLE, self::LEASE_MINUTES, $id, pSQL($token)))) {
            throw new \RuntimeException('Matterhorn new-product queue lease renewal failed');
        }
        return (int) $db->Affected_Rows() === 1 || $this->ownsActiveLease($id, $token);
    }

    /** @return bool true if this generation finalized, false if a newer generation was requeued */
    public function done(int $id, string $token, int $productId, int $expectedRunId = 0): bool
    {
        $db = \Db::getInstance();
        $runFence = $expectedRunId > 0 ? ' AND id_run=' . $expectedRunId : '';
        if (!$db->execute(sprintf("UPDATE `%s%s` SET status='done',id_product=%d,locked_by=NULL,locked_until=NULL,available_at=NULL,last_error=NULL,updated_at=NOW() WHERE id_queue=%d AND status='processing' AND locked_by='%s' AND locked_until>NOW()%s", _DB_PREFIX_, self::TABLE, $productId, $id, pSQL($token), $runFence))) {
            throw new \RuntimeException('Matterhorn new-product queue completion update failed');
        }
        if ((int) $db->Affected_Rows() === 1) { return true; }
        if ($expectedRunId > 0 && $this->requeueNewerGeneration($id, $token, $expectedRunId, $productId)) { return false; }
        throw new \RuntimeException('Matterhorn new-product queue ownership lost before completion');
    }

    /** @return bool true if failure applied to this generation, false if a newer generation was requeued */
    public function fail(int $id, string $token, string $message, bool $retryable = true, int $expectedRunId = 0): bool
    {
        $retry = $retryable ? 1 : 0;
        $runFence = $expectedRunId > 0 ? ' AND id_run=' . $expectedRunId : '';
        $db = \Db::getInstance();
        if (!$db->execute(sprintf("UPDATE `%s%s` SET status=IF(%d=0 OR attempts>=%d,'failed','pending'),locked_by=NULL,locked_until=NULL,available_at=IF(%d=0 OR attempts>=%d,NULL,TIMESTAMPADD(SECOND,CASE attempts WHEN 1 THEN 15 WHEN 2 THEN 30 WHEN 3 THEN 60 WHEN 4 THEN 120 ELSE 300 END,NOW())),last_error='%s',updated_at=NOW() WHERE id_queue=%d AND status='processing' AND locked_by='%s' AND locked_until>NOW()%s", _DB_PREFIX_, self::TABLE, $retry, self::MAX_ATTEMPTS, $retry, self::MAX_ATTEMPTS, pSQL(mb_substr($message, 0, 4000), true), $id, pSQL($token), $runFence))) {
            throw new \RuntimeException('Matterhorn new-product queue failure update failed');
        }
        if ((int) $db->Affected_Rows() === 1) { return true; }
        if ($expectedRunId > 0 && $this->requeueNewerGeneration($id, $token, $expectedRunId, null)) { return false; }
        throw new \RuntimeException('Matterhorn new-product queue ownership lost before failure update');
    }

    public function retryFailed(?int $shopId = null, int $limit = 1000): int
    {
        $limit = max(1, min(100000, $limit));
        $where = "status='failed'" . ($shopId === null ? '' : ' AND id_shop=' . (int) $shopId);
        $db = \Db::getInstance();
        $rows = $db->executeS('SELECT id_queue FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE ' . $where . ' ORDER BY id_queue LIMIT ' . $limit, true, false) ?: [];
        if ($rows === []) { return 0; }
        $ids = implode(',', array_map(static fn(array $row): int => (int) $row['id_queue'], $rows));
        // The candidate list is only a bounded preload. Recheck failed status while updating so a
        // concurrent retry+claim cannot have its processing lease cleared by this stale ID list.
        if (!$db->execute("UPDATE `" . _DB_PREFIX_ . self::TABLE . "` SET status='pending',attempts=0,available_at=NULL,last_error=NULL,locked_by=NULL,locked_until=NULL,updated_at=NOW() WHERE status='failed' AND id_queue IN (" . $ids . ')')) {
            throw new \RuntimeException('Matterhorn new-product queue retry reset failed');
        }
        return (int) $db->Affected_Rows();
    }

    public function counts(?int $shopId = null): array
    {
        $where = $shopId === null ? '' : ' WHERE id_shop=' . (int) $shopId;
        return \Db::getInstance()->executeS('SELECT status,COUNT(*) qty FROM `' . _DB_PREFIX_ . self::TABLE . '`' . $where . ' GROUP BY status', true, false) ?: [];
    }

    public function gc(int $days = 2): int
    {
        return (int) \Db::getInstance()->delete(self::TABLE, "status='done' AND updated_at<DATE_SUB(NOW(),INTERVAL " . max(0, $days) . ' DAY)');
    }

    private function requeueNewerGeneration(int $id, string $token, int $expectedRunId, ?int $productId): bool
    {
        $productSql = $productId !== null && $productId > 0 ? ',id_product=' . $productId : '';
        $db = \Db::getInstance();
        if (!$db->execute(sprintf("UPDATE `%s%s` SET status='pending',attempts=0,available_at=NULL,last_error=NULL,locked_by=NULL,locked_until=NULL%s,updated_at=NOW() WHERE id_queue=%d AND status='processing' AND locked_by='%s' AND locked_until>NOW() AND id_run>%d", _DB_PREFIX_, self::TABLE, $productSql, $id, pSQL($token), $expectedRunId))) {
            throw new \RuntimeException('Matterhorn new-product newer-generation requeue failed');
        }
        return (int) $db->Affected_Rows() === 1;
    }

    private function ownsActiveLease(int $id, string $token): bool
    {
        return (bool) \Db::getInstance()->getValue(sprintf("SELECT 1 FROM `%s%s` WHERE id_queue=%d AND status='processing' AND locked_by='%s' AND locked_until>NOW()", _DB_PREFIX_, self::TABLE, $id, pSQL($token)), false);
    }

    private function claimToken(string $worker): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', trim($worker)) ?: 'worker';
        return substr($prefix, 0, 28) . ':' . bin2hex(random_bytes(16));
    }

    private function insertValues(array $values): void
    {
        // A newer run may refresh payload/id_run while an older worker retains its processing lease.
        // The old worker finalizer is expectedRunId-fenced and requeues the row after its generation
        // completes, ensuring the newest supplier payload is subsequently applied without lease theft.
        $sql = sprintf("INSERT INTO `%s%s` (`id_run`,`id_shop`,`source`,`source_key`,`payload`,`payload_hash`,`status`,`attempts`,`available_at`,`locked_by`,`locked_until`,`last_error`,`created_at`,`updated_at`) VALUES %s ON DUPLICATE KEY UPDATE payload=IF(VALUES(id_run)>=id_run,VALUES(payload),payload),payload_hash=IF(VALUES(id_run)>=id_run,VALUES(payload_hash),payload_hash),attempts=IF(status='processing',attempts,IF(VALUES(id_run)>id_run,0,attempts)),available_at=IF(status='processing',available_at,IF(VALUES(id_run)>id_run,NULL,available_at)),locked_by=IF(status='processing',locked_by,IF(VALUES(id_run)>id_run,NULL,locked_by)),locked_until=IF(status='processing',locked_until,IF(VALUES(id_run)>id_run,NULL,locked_until)),last_error=IF(status='processing',last_error,IF(VALUES(id_run)>id_run,NULL,last_error)),updated_at=IF(VALUES(id_run)>=id_run,NOW(),updated_at),status=IF(status='processing','processing',IF(VALUES(id_run)>id_run,'pending',status)),id_run=GREATEST(id_run,VALUES(id_run))", _DB_PREFIX_, self::TABLE, implode(',', $values));
        if (!\Db::getInstance()->execute($sql)) { throw new \RuntimeException('Matterhorn new-product queue enqueue failed'); }
    }
}
