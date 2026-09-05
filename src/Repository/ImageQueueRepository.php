<?php
namespace Lp\MatterhornImport\Repository;

final class ImageQueueRepository
{
    private const TABLE = 'li_matterhornim_99dfbf_image_queue';
    private const LEASE_MINUTES = 15;
    private const MAX_ATTEMPTS = 5;

    public function enqueue(int $runId, int $shopId, string $source, string $sourceKey, int $productId, array $urls): void
    {
        $this->enqueueBatch($runId, $shopId, $source, [['source_key' => $sourceKey, 'id_product' => $productId, 'urls' => $urls]]);
    }

    public function enqueueBatch(int $runId, int $shopId, string $source, array $jobs): void
    {
        $values = [];
        $now = date('Y-m-d H:i:s');
        foreach ($jobs as $job) {
            $urls = array_values(array_unique(array_filter(array_map(static fn(mixed $url): string => trim((string) $url), (array) $job['urls']), static fn(string $url): bool => $url !== '')));
            foreach ($urls as $position => $url) {
                $values[] = sprintf("(%d,%d,'%s','%s',%d,'%s','%s',%d,%d,'pending',NULL,'%s','%s')", $runId, $shopId, pSQL($source), pSQL((string) $job['source_key']), (int) $job['id_product'], pSQL($url, true), hash('sha256', $url), $position, $position === 0 ? 1 : 0, $now, $now);
                if (count($values) >= 500) { $this->insertValues($values); $values = []; }
            }
        }
        if ($values !== []) { $this->insertValues($values); }
    }

    /** @return list<array<string,mixed>> */
    public function claim(string $worker, string $source, int $limit = 20, ?int $shopId = null): array
    {
        $source = trim($source);
        if ($source === '') { throw new \InvalidArgumentException('Image queue claim requires source'); }
        $limit = max(1, min(500, $limit));
        $scopeWhere = " AND source='" . pSQL($source) . "'" . ($shopId === null ? '' : ' AND id_shop=' . (int) $shopId);
        $token = $this->claimToken($worker);
        if (!\Db::getInstance()->execute(sprintf("UPDATE `%s%s` SET status='processing',locked_by='%s',locked_until=DATE_ADD(NOW(),INTERVAL %d MINUTE),available_at=NULL,attempts=attempts+1,updated_at=NOW() WHERE ((status='pending' AND (available_at IS NULL OR available_at<=NOW())) OR (status='processing' AND locked_until<=NOW())) AND attempts<%d%s ORDER BY id_queue LIMIT %d", _DB_PREFIX_, self::TABLE, pSQL($token), self::LEASE_MINUTES, self::MAX_ATTEMPTS, $scopeWhere, $limit))) {
            throw new \RuntimeException('Matterhorn image queue claim failed');
        }
        return \Db::getInstance()->executeS(sprintf("SELECT * FROM `%s%s` WHERE status='processing' AND locked_by='%s' AND locked_until>NOW()%s ORDER BY id_queue LIMIT %d", _DB_PREFIX_, self::TABLE, pSQL($token), $scopeWhere, $limit), true, false) ?: [];
    }

    public function renew(int $id, string $token): bool
    {
        $db = \Db::getInstance();
        if (!$db->execute(sprintf("UPDATE `%s%s` SET locked_until=DATE_ADD(NOW(),INTERVAL %d MINUTE),updated_at=NOW() WHERE id_queue=%d AND status='processing' AND locked_by='%s' AND locked_until>NOW()", _DB_PREFIX_, self::TABLE, self::LEASE_MINUTES, $id, pSQL($token)))) {
            throw new \RuntimeException('Matterhorn image queue lease renewal failed');
        }
        return (int) $db->Affected_Rows() > 0 || $this->ownsActiveLease($id, $token);
    }

    /** @return array<string,mixed> */
    public function lockOwned(int $id, string $token): array
    {
        if ($id <= 0 || $token === '') { throw new \InvalidArgumentException('Image queue lock requires id/token'); }
        $row = \Db::getInstance()->getRow(sprintf(
            "SELECT * FROM `%s%s` WHERE id_queue=%d AND status='processing' AND locked_by='%s' AND locked_until>NOW() FOR UPDATE",
            _DB_PREFIX_, self::TABLE, $id, pSQL($token)
        ), false);
        if (!is_array($row) || $row === []) {
            throw new \RuntimeException('Matterhorn image queue ownership lost before locked persistence');
        }
        return $row;
    }

    public function done(int $id, string $token): void
    {
        $db = \Db::getInstance();
        if (!$db->execute(sprintf("UPDATE `%s%s` SET status='done',locked_by=NULL,locked_until=NULL,available_at=NULL,last_error=NULL,updated_at=NOW() WHERE id_queue=%d AND status='processing' AND locked_by='%s' AND locked_until>NOW()", _DB_PREFIX_, self::TABLE, $id, pSQL($token)))) {
            throw new \RuntimeException('Matterhorn image queue completion update failed');
        }
        if ((int) $db->Affected_Rows() !== 1) { throw new \RuntimeException('Matterhorn image queue ownership lost or lease expired before completion'); }
    }

    public function supersede(int $id, string $token, string $reason): bool
    {
        $db = \Db::getInstance();
        $message = 'superseded: ' . mb_substr($reason, 0, 3980);
        if (!$db->execute(sprintf("UPDATE `%s%s` SET status='done',locked_by=NULL,locked_until=NULL,available_at=NULL,last_error='%s',updated_at=NOW() WHERE id_queue=%d AND status='processing' AND locked_by='%s' AND locked_until>NOW()", _DB_PREFIX_, self::TABLE, pSQL($message, true), $id, pSQL($token)))) {
            throw new \RuntimeException('Matterhorn image queue supersede update failed');
        }
        return (int) $db->Affected_Rows() === 1;
    }

    public function fail(int $id, string $token, string $error, bool $retryable = true): bool
    {
        $retryFlag = $retryable ? 1 : 0;
        $db = \Db::getInstance();
        if (!$db->execute(sprintf("UPDATE `%s%s` SET status=IF(%d=0 OR attempts>=%d,'failed','pending'),locked_by=NULL,locked_until=NULL,available_at=IF(%d=0 OR attempts>=%d,NULL,TIMESTAMPADD(SECOND,CASE attempts WHEN 1 THEN 15 WHEN 2 THEN 30 WHEN 3 THEN 60 WHEN 4 THEN 120 ELSE 300 END,NOW())),last_error='%s',updated_at=NOW() WHERE id_queue=%d AND status='processing' AND locked_by='%s' AND locked_until>NOW()", _DB_PREFIX_, self::TABLE, $retryFlag, self::MAX_ATTEMPTS, $retryFlag, self::MAX_ATTEMPTS, pSQL(mb_substr($error, 0, 4000), true), $id, pSQL($token)))) {
            throw new \RuntimeException('Matterhorn image queue failure update failed');
        }
        return (int) $db->Affected_Rows() === 1;
    }

    public function retryFailed(string $source, ?int $shopId = null, int $limit = 1000): int
    {
        $source = trim($source);
        if ($source === '') { throw new \InvalidArgumentException('Image retry requires source'); }
        $limit = max(1, min(100000, $limit));
        $where = "status='failed' AND source='" . pSQL($source) . "'" . ($shopId === null ? '' : ' AND id_shop=' . (int) $shopId);
        $db = \Db::getInstance();
        $ids = $db->executeS('SELECT id_queue FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE ' . $where . ' ORDER BY id_queue LIMIT ' . $limit, true, false) ?: [];
        if ($ids === []) { return 0; }
        $list = implode(',', array_map(static fn(array $row): int => (int) $row['id_queue'], $ids));
        if (!$db->execute("UPDATE `" . _DB_PREFIX_ . self::TABLE . "` SET status='pending',attempts=0,available_at=NULL,last_error=NULL,locked_by=NULL,locked_until=NULL,updated_at=NOW() WHERE status='failed' AND source='" . pSQL($source) . "' AND id_queue IN (" . $list . ')')) {
            throw new \RuntimeException('Matterhorn image queue retry reset failed');
        }
        return (int) $db->Affected_Rows();
    }

    public function status(int $idQueue): ?string
    {
        if ($idQueue <= 0) { return null; }
        $value = \Db::getInstance()->getValue(sprintf('SELECT status FROM `%s%s` WHERE id_queue=%d', _DB_PREFIX_, self::TABLE, $idQueue), false);
        return $value === false || $value === null ? null : (string) $value;
    }

    public function unresolvedForRun(int $runId, int $shopId): int
    {
        return (int) \Db::getInstance()->getValue(sprintf("SELECT COUNT(*) FROM `%s%s` WHERE id_run=%d AND id_shop=%d AND status<>'done'", _DB_PREFIX_, self::TABLE, $runId, $shopId), false);
    }

    public function unresolvedForSource(int $shopId, string $source): int
    {
        if ($shopId <= 0 || trim($source) === '') { throw new \InvalidArgumentException('Image source queue check requires shop/source'); }
        return (int) \Db::getInstance()->getValue(sprintf(
            "SELECT COUNT(*) FROM `%s%s` WHERE id_shop=%d AND source='%s' AND status<>'done'",
            _DB_PREFIX_, self::TABLE, $shopId, pSQL($source)
        ), false);
    }

    public function gc(int $days = 2): int
    {
        return (int) \Db::getInstance()->delete(self::TABLE, "status='done' AND updated_at < DATE_SUB(NOW(), INTERVAL " . max(0, $days) . ' DAY)');
    }

    public function counts(?int $shopId = null, ?string $source = null): array
    {
        $clauses = [];
        if ($shopId !== null) { $clauses[] = 'id_shop=' . (int) $shopId; }
        if ($source !== null && trim($source) !== '') { $clauses[] = "source='" . pSQL(trim($source)) . "'"; }
        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
        return \Db::getInstance()->executeS('SELECT status,COUNT(*) qty FROM `' . _DB_PREFIX_ . self::TABLE . '`' . $where . ' GROUP BY status', true, false) ?: [];
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
        // The same product/url row is a desired-state handoff across import generations. A stale
        // producer must never move that desired state backwards after a newer run has queued it.
        // Keep id_run assignment last: MySQL/MariaDB evaluate single-table UPDATE assignments from
        // left to right, so every VALUES(id_run)>=id_run predicate below compares against the
        // previously persisted generation rather than an id_run value already changed in this SET.
        $sql = sprintf(
            "INSERT INTO `%s%s` (`id_run`,`id_shop`,`source`,`source_key`,`id_product`,`url`,`url_hash`,`position`,`is_cover`,`status`,`available_at`,`created_at`,`updated_at`) VALUES %s " .
            "ON DUPLICATE KEY UPDATE " .
            "source=IF(VALUES(id_run)>=id_run,VALUES(source),source)," .
            "source_key=IF(VALUES(id_run)>=id_run,VALUES(source_key),source_key)," .
            "url=IF(VALUES(id_run)>=id_run,VALUES(url),url)," .
            "position=IF(VALUES(id_run)>=id_run,VALUES(position),position)," .
            "is_cover=IF(VALUES(id_run)>=id_run,VALUES(is_cover),is_cover)," .
            "attempts=IF(VALUES(id_run)>=id_run,IF(status='processing',attempts,0),attempts)," .
            "available_at=IF(VALUES(id_run)>=id_run,IF(status='processing',available_at,NULL),available_at)," .
            "locked_by=IF(VALUES(id_run)>=id_run,IF(status='processing',locked_by,NULL),locked_by)," .
            "locked_until=IF(VALUES(id_run)>=id_run,IF(status='processing',locked_until,NULL),locked_until)," .
            "last_error=IF(VALUES(id_run)>=id_run,IF(status='processing',last_error,NULL),last_error)," .
            "updated_at=IF(VALUES(id_run)>=id_run,VALUES(updated_at),updated_at)," .
            "status=IF(VALUES(id_run)>=id_run,IF(status='processing','processing','pending'),status)," .
            "id_run=GREATEST(id_run,VALUES(id_run))",
            _DB_PREFIX_, self::TABLE, implode(',', $values)
        );
        if (!\Db::getInstance()->execute($sql)) { throw new \RuntimeException('Matterhorn image queue batch insert failed'); }
    }
}
