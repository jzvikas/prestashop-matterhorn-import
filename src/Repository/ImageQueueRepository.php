<?php
namespace Lp\MatterhornImport\Repository;

final class ImageQueueRepository
{
    private const TABLE = 'li_matterhornim_99dfbf_image_queue';
    private const LEASE_MINUTES = 15;
    private const MAX_ATTEMPTS = 5;
    private const ENQUEUE_CHUNK = 500;
    private const MAX_URL_BYTES = 16384;
    private const MAX_WRITE_VALUES_BYTES = 7340032; // 7 MiB escaped VALUES; reserve SQL overhead

    public function enqueue(int $runId, int $shopId, string $source, string $sourceKey, int $productId, array $urls): void
    {
        $this->enqueueBatch($runId, $shopId, $source, [['source_key' => $sourceKey, 'id_product' => $productId, 'urls' => $urls]]);
    }

    public function enqueueBatch(int $runId, int $shopId, string $source, array $jobs): void
    {
        $values = [];
        $valuesBytes = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($jobs as $job) {
            $urls = array_values(array_unique(array_filter(array_map(static fn(mixed $url): string => trim((string) $url), (array) $job['urls']), static fn(string $url): bool => $url !== '')));
            foreach ($urls as $position => $url) {
                if (strlen($url) > self::MAX_URL_BYTES) {
                    throw new \InvalidArgumentException('Image URL exceeds operational limit of ' . self::MAX_URL_BYTES . ' bytes');
                }
                $value = sprintf(
                    "(%d,%d,'%s','%s',%d,'%s','%s',%d,%d,'pending',NULL,'%s','%s')",
                    $runId,
                    $shopId,
                    pSQL($source),
                    pSQL((string) $job['source_key']),
                    (int) $job['id_product'],
                    pSQL($url, true),
                    hash('sha256', $url),
                    $position,
                    $position === 0 ? 1 : 0,
                    $now,
                    $now
                );
                $valueBytes = strlen($value);
                if ($valueBytes > self::MAX_WRITE_VALUES_BYTES) {
                    throw new \RuntimeException('Escaped image queue row exceeds SQL write budget');
                }
                $separatorBytes = $values === [] ? 0 : 1;
                if (
                    $values !== []
                    && (
                        count($values) >= self::ENQUEUE_CHUNK
                        || $valuesBytes + $separatorBytes + $valueBytes > self::MAX_WRITE_VALUES_BYTES
                    )
                ) {
                    $this->insertValues($values);
                    $values = [];
                    $valuesBytes = 0;
                    $separatorBytes = 0;
                }
                $values[] = $value;
                $valuesBytes += $separatorBytes + $valueBytes;
            }
        }
        if ($values !== []) { $this->insertValues($values); }
    }

    public function supersedeOlderUnresolvedForAuthoritativeManifest(
        int $runId,
        int $shopId,
        string $source,
        string $sourceKey,
        int $productId
    ): int {
        $source = trim($source);
        $sourceKey = trim($sourceKey);
        if ($runId <= 0 || $shopId <= 0 || $source === '' || $sourceKey === '' || $productId <= 0) {
            throw new \InvalidArgumentException('Authoritative image manifest supersede requires run/shop/source/source-key/product');
        }

        // Authoritative callers enqueue every currently desired URL first. Because uq_product_url
        // reuses the same queue row and accepted enqueue moves that row to this run generation,
        // any exact-owner row still left on an older generation is no longer part of the manifest.
        // Clearing an active token here makes a stale downloader lose its next lease/row fence.
        $db = \Db::getInstance();
        $reason = 'superseded: removed from newer authoritative image manifest';
        if (!$db->execute(sprintf(
            "UPDATE `%s%s` SET status='done',locked_by=NULL,locked_until=NULL,available_at=NULL,last_error='%s',updated_at=NOW() " .
            "WHERE id_shop=%d AND source='%s' AND source_key='%s' AND id_product=%d AND id_run<%d " .
            "AND status IN ('pending','processing','failed')",
            _DB_PREFIX_,
            self::TABLE,
            pSQL($reason, true),
            $shopId,
            pSQL($source),
            pSQL($sourceKey),
            $productId,
            $runId
        ))) {
            throw new \RuntimeException('Matterhorn stale image manifest supersede failed');
        }

        return (int) $db->Affected_Rows();
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
        // One claim token owns a bounded batch that ImageWorker consumes sequentially. Heartbeat
        // every still-active sibling whenever the current image renews so a slow download/attach
        // cannot let untouched rows expire and consume their retry budget before they are attempted.
        // Expired rows stay excluded, so renewal never steals ownership back from another worker.
        if (!$db->execute(sprintf(
            "UPDATE `%s%s` SET locked_until=DATE_ADD(NOW(),INTERVAL %d MINUTE),updated_at=NOW() WHERE status='processing' AND locked_by='%s' AND locked_until>NOW()",
            _DB_PREFIX_, self::TABLE, self::LEASE_MINUTES, pSQL($token)
        ))) {
            throw new \RuntimeException('Matterhorn image queue lease renewal failed');
        }
        return $this->ownsActiveLease($id, $token);
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
        // A processing lease may survive only while the exact source/source_key owner is unchanged;
        // a newer owner handoff revokes the old worker before source identity is replaced.
        // Keep source/source_key and id_run assignments after the lease expressions: MySQL/MariaDB
        // evaluate single-table UPDATE assignments from left to right, so owner/generation predicates
        // below still see the previously persisted identity and generation.
        $accept = "(VALUES(id_run)>id_run OR (VALUES(id_run)=id_run AND VALUES(source)=source AND VALUES(source_key)=source_key))";
        $sameOwner = "(VALUES(source)=source AND VALUES(source_key)=source_key)";
        $sql = sprintf(
            "INSERT INTO `%s%s` (`id_run`,`id_shop`,`source`,`source_key`,`id_product`,`url`,`url_hash`,`position`,`is_cover`,`status`,`available_at`,`created_at`,`updated_at`) VALUES %s " .
            "ON DUPLICATE KEY UPDATE " .
            "attempts=IF(%s,IF(status='processing' AND %s,attempts,0),attempts)," .
            "available_at=IF(%s,IF(status='processing' AND %s,available_at,NULL),available_at)," .
            "locked_by=IF(%s,IF(status='processing' AND %s,locked_by,NULL),locked_by)," .
            "locked_until=IF(%s,IF(status='processing' AND %s,locked_until,NULL),locked_until)," .
            "last_error=IF(%s,IF(status='processing' AND %s,last_error,NULL),last_error)," .
            "updated_at=IF(%s,VALUES(updated_at),updated_at)," .
            "status=IF(%s,IF(status='processing' AND %s,'processing','pending'),status)," .
            "url=IF(%s,VALUES(url),url)," .
            "position=IF(%s,VALUES(position),position)," .
            "is_cover=IF(%s,VALUES(is_cover),is_cover)," .
            "source=IF(%s,VALUES(source),source)," .
            "source_key=IF(%s,VALUES(source_key),source_key)," .
            "id_run=GREATEST(id_run,VALUES(id_run))",
            _DB_PREFIX_, self::TABLE, implode(',', $values),
            $accept, $sameOwner,
            $accept, $sameOwner,
            $accept, $sameOwner,
            $accept, $sameOwner,
            $accept, $sameOwner,
            $accept,
            $accept, $sameOwner,
            $accept,
            $accept,
            $accept,
            $accept,
            $accept
        );
        if (!\Db::getInstance()->execute($sql)) { throw new \RuntimeException('Matterhorn image queue batch insert failed'); }
    }
}
