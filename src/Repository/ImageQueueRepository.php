<?php
namespace Lp\MatterhornImport\Repository;

final class ImageQueueRepository
{
    private const TABLE = 'li_matterhornim_99dfbf_image_queue';
    private const LEASE_MINUTES = 15;
    private const MAX_ATTEMPTS = 5;

    public function enqueue(int $runId, int $shopId, string $source, string $sourceKey, int $productId, array $urls): void
    {
        $values = [];
        $now = date('Y-m-d H:i:s');
        $urls = array_values(array_unique(array_filter(array_map(
            static fn(mixed $url): string => trim((string) $url), $urls
        ), static fn(string $url): bool => $url !== '')));
        foreach ($urls as $position => $url) {
            $values[] = sprintf(
                "(%d,%d,'%s','%s',%d,'%s','%s',%d,%d,'pending',NULL,'%s','%s')",
                $runId, $shopId, pSQL($source), pSQL($sourceKey), $productId,
                pSQL($url, true), hash('sha256', $url), $position, $position === 0 ? 1 : 0, $now, $now
            );
        }
        if ($values === []) { return; }
        $sql = sprintf(
            "INSERT INTO `%s%s` (`id_run`,`id_shop`,`source`,`source_key`,`id_product`,`url`,`url_hash`,`position`,`is_cover`,`status`,`available_at`,`created_at`,`updated_at`) VALUES %s " .
            "ON DUPLICATE KEY UPDATE id_run=IF(status='done',VALUES(id_run),id_run),source=IF(status='done',VALUES(source),source),source_key=IF(status='done',VALUES(source_key),source_key),url=IF(status='done',VALUES(url),url),position=IF(status='done',VALUES(position),position),is_cover=IF(status='done',VALUES(is_cover),is_cover),attempts=IF(status='done',0,attempts),available_at=IF(status='done',NULL,available_at),last_error=IF(status='done',NULL,last_error),updated_at=IF(status='done',VALUES(updated_at),updated_at),status=IF(status='done','pending',status)",
            _DB_PREFIX_, self::TABLE, implode(',', $values)
        );
        if (!\Db::getInstance()->execute($sql)) { throw new \RuntimeException('Matterhorn image queue insert failed'); }
    }

    /** @return list<array<string,mixed>> */
    public function claim(string $worker, int $limit = 20, ?int $shopId = null): array
    {
        $limit = max(1, min(500, $limit));
        $shopWhere = $shopId === null ? '' : ' AND id_shop=' . (int) $shopId;
        $token = $this->claimToken($worker);
        if (!\Db::getInstance()->execute(sprintf(
            "UPDATE `%s%s` SET status='processing',locked_by='%s',locked_until=DATE_ADD(NOW(),INTERVAL %d MINUTE),available_at=NULL,attempts=attempts+1,updated_at=NOW() WHERE ((status='pending' AND (available_at IS NULL OR available_at<=NOW())) OR (status='processing' AND locked_until<=NOW())) AND attempts<%d%s ORDER BY id_queue LIMIT %d",
            _DB_PREFIX_, self::TABLE, pSQL($token), self::LEASE_MINUTES, self::MAX_ATTEMPTS, $shopWhere, $limit
        ))) { throw new \RuntimeException('Matterhorn image queue claim failed'); }
        return \Db::getInstance()->executeS(sprintf(
            "SELECT * FROM `%s%s` WHERE status='processing' AND locked_by='%s' AND locked_until>NOW()%s ORDER BY id_queue LIMIT %d",
            _DB_PREFIX_, self::TABLE, pSQL($token), $shopWhere, $limit
        )) ?: [];
    }

    public function done(int $id, string $token): void
    {
        $db = \Db::getInstance();
        if (!$db->execute(sprintf(
            "UPDATE `%s%s` SET status='done',locked_by=NULL,locked_until=NULL,available_at=NULL,last_error=NULL,updated_at=NOW() WHERE id_queue=%d AND status='processing' AND locked_by='%s' AND locked_until>NOW()",
            _DB_PREFIX_, self::TABLE, $id, pSQL($token)
        ))) { throw new \RuntimeException('Matterhorn image queue completion update failed'); }
        if ((int) $db->Affected_Rows() !== 1) { throw new \RuntimeException('Matterhorn image queue lease lost before completion'); }
    }

    public function fail(int $id, string $token, string $error, bool $retryable = true): bool
    {
        $retry = $retryable ? 1 : 0;
        $db = \Db::getInstance();
        if (!$db->execute(sprintf(
            "UPDATE `%s%s` SET status=IF(%d=0 OR attempts>=%d,'failed','pending'),locked_by=NULL,locked_until=NULL,available_at=IF(%d=0 OR attempts>=%d,NULL,TIMESTAMPADD(SECOND,CASE attempts WHEN 1 THEN 15 WHEN 2 THEN 30 WHEN 3 THEN 60 WHEN 4 THEN 120 ELSE 300 END,NOW())),last_error='%s',updated_at=NOW() WHERE id_queue=%d AND status='processing' AND locked_by='%s' AND locked_until>NOW()",
            _DB_PREFIX_, self::TABLE, $retry, self::MAX_ATTEMPTS, $retry, self::MAX_ATTEMPTS,
            pSQL(mb_substr($error, 0, 4000), true), $id, pSQL($token)
        ))) { throw new \RuntimeException('Matterhorn image queue failure update failed'); }
        return (int) $db->Affected_Rows() === 1;
    }

    public function unresolvedForRun(int $runId, int $shopId): int
    {
        return (int) \Db::getInstance()->getValue(sprintf(
            "SELECT COUNT(*) FROM `%s%s` WHERE id_run=%d AND id_shop=%d AND status<>'done'",
            _DB_PREFIX_, self::TABLE, $runId, $shopId
        ));
    }

    private function claimToken(string $worker): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', trim($worker)) ?: 'worker';
        return substr($prefix, 0, 28) . ':' . bin2hex(random_bytes(16));
    }
}
