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
        return \Db::getInstance()->executeS(sprintf("SELECT * FROM `%s%s` WHERE status='processing' AND locked_by='%s' AND locked_until>NOW()%s ORDER BY id_queue LIMIT %d", _DB_PREFIX_, self::TABLE, pSQL($token), $shopWhere, $limit)) ?: [];
    }

    public function renew(int $id, string $token): bool
    {
        $db = \Db::getInstance();
        if (!$db->execute(sprintf("UPDATE `%s%s` SET locked_until=DATE_ADD(NOW(),INTERVAL %d MINUTE),updated_at=NOW() WHERE id_queue=%d AND status='processing' AND locked_by='%s' AND locked_until>NOW()", _DB_PREFIX_, self::TABLE, self::LEASE_MINUTES, $id, pSQL($token)))) {
            throw new \RuntimeException('Matterhorn new-product queue lease renewal failed');
        }
        return (int) $db->Affected_Rows() === 1 || $this->ownsActiveLease($id, $token);
    }

    public function done(int $id, string $token, int $productId): void
    {
        $db = \Db::getInstance();
        if (!$db->execute(sprintf("UPDATE `%s%s` SET status='done',id_product=%d,locked_by=NULL,locked_until=NULL,available_at=NULL,last_error=NULL,updated_at=NOW() WHERE id_queue=%d AND status='processing' AND locked_by='%s' AND locked_until>NOW()", _DB_PREFIX_, self::TABLE, $productId, $id, pSQL($token))) || (int) $db->Affected_Rows() !== 1) {
            throw new \RuntimeException('Matterhorn new-product queue ownership lost before completion');
        }
    }

    public function fail(int $id, string $token, string $message, bool $retryable = true): bool
    {
        $retry = $retryable ? 1 : 0;
        $db = \Db::getInstance();
        if (!$db->execute(sprintf("UPDATE `%s%s` SET status=IF(%d=0 OR attempts>=%d,'failed','pending'),locked_by=NULL,locked_until=NULL,available_at=IF(%d=0 OR attempts>=%d,NULL,TIMESTAMPADD(SECOND,CASE attempts WHEN 1 THEN 15 WHEN 2 THEN 30 WHEN 3 THEN 60 WHEN 4 THEN 120 ELSE 300 END,NOW())),last_error='%s',updated_at=NOW() WHERE id_queue=%d AND status='processing' AND locked_by='%s' AND locked_until>NOW()", _DB_PREFIX_, self::TABLE, $retry, self::MAX_ATTEMPTS, $retry, self::MAX_ATTEMPTS, pSQL(mb_substr($message, 0, 4000), true), $id, pSQL($token)))) {
            throw new \RuntimeException('Matterhorn new-product queue failure update failed');
        }
        return (int) $db->Affected_Rows() === 1;
    }

    public function retryFailed(?int $shopId = null, int $limit = 1000): int
    {
        $limit = max(1, min(100000, $limit));
        $where = "status='failed'" . ($shopId === null ? '' : ' AND id_shop=' . (int) $shopId);
        $db = \Db::getInstance();
        $rows = $db->executeS('SELECT id_queue FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE ' . $where . ' ORDER BY id_queue LIMIT ' . $limit) ?: [];
        if ($rows === []) { return 0; }
        $ids = implode(',', array_map(static fn(array $row): int => (int) $row['id_queue'], $rows));
        if (!$db->execute("UPDATE `" . _DB_PREFIX_ . self::TABLE . "` SET status='pending',attempts=0,available_at=NULL,last_error=NULL,locked_by=NULL,locked_until=NULL,updated_at=NOW() WHERE id_queue IN (" . $ids . ')')) {
            throw new \RuntimeException('Matterhorn new-product queue retry reset failed');
        }
        return (int) $db->Affected_Rows();
    }

    public function counts(?int $shopId = null): array
    {
        $where = $shopId === null ? '' : ' WHERE id_shop=' . (int) $shopId;
        return \Db::getInstance()->executeS('SELECT status,COUNT(*) qty FROM `' . _DB_PREFIX_ . self::TABLE . '`' . $where . ' GROUP BY status') ?: [];
    }

    public function gc(int $days = 2): int
    {
        return (int) \Db::getInstance()->delete(self::TABLE, "status='done' AND updated_at<DATE_SUB(NOW(),INTERVAL " . max(0, $days) . ' DAY)');
    }

    private function ownsActiveLease(int $id, string $token): bool
    {
        return (bool) \Db::getInstance()->getValue(sprintf("SELECT 1 FROM `%s%s` WHERE id_queue=%d AND status='processing' AND locked_by='%s' AND locked_until>NOW()", _DB_PREFIX_, self::TABLE, $id, pSQL($token)));
    }

    private function claimToken(string $worker): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', trim($worker)) ?: 'worker';
        return substr($prefix, 0, 28) . ':' . bin2hex(random_bytes(16));
    }

    private function insertValues(array $values): void
    {
        $sql = sprintf("INSERT INTO `%s%s` (`id_run`,`id_shop`,`source`,`source_key`,`payload`,`payload_hash`,`status`,`attempts`,`available_at`,`locked_by`,`locked_until`,`last_error`,`created_at`,`updated_at`) VALUES %s ON DUPLICATE KEY UPDATE id_run=IF(status IN ('done','processing'),id_run,VALUES(id_run)),payload=IF(status IN ('done','processing'),payload,VALUES(payload)),payload_hash=IF(status IN ('done','processing'),payload_hash,VALUES(payload_hash)),attempts=IF(status IN ('done','processing'),attempts,0),available_at=IF(status IN ('done','processing'),available_at,NULL),locked_by=IF(status IN ('done','processing'),locked_by,NULL),locked_until=IF(status IN ('done','processing'),locked_until,NULL),last_error=IF(status IN ('done','processing'),last_error,NULL),updated_at=IF(status IN ('done','processing'),updated_at,NOW()),status=IF(status IN ('done','processing'),status,'pending')", _DB_PREFIX_, self::TABLE, implode(',', $values));
        if (!\Db::getInstance()->execute($sql)) { throw new \RuntimeException('Matterhorn new-product queue enqueue failed'); }
    }
}
