<?php
namespace Lp\MatterhornImport\Repository;

final class ImageOrphanRepository
{
    private const TABLE = 'li_matterhornim_99dfbf_image_orphan';
    private const INITIAL_DELAY_MINUTES = 30;

    public function record(array $queueRow, int $idImage, string $reason, ?string $lastError = null): void
    {
        if ($idImage <= 0) {
            throw new \InvalidArgumentException('Image orphan requires a positive image ID');
        }
        $errorSql = $lastError === null || trim($lastError) === ''
            ? 'NULL'
            : "'" . pSQL(mb_substr($lastError, 0, 4000), true) . "'";
        $sql = sprintf(
            "INSERT INTO `%s%s` (`id_queue`,`id_run`,`id_shop`,`source`,`source_key`,`id_product`,`id_image`,`reason`,`attempts`,`available_at`,`last_error`,`created_at`,`updated_at`) " .
            "VALUES (%d,%d,%d,'%s','%s',%d,%d,'%s',0,DATE_ADD(NOW(),INTERVAL %d MINUTE),%s,NOW(),NOW()) " .
            "ON DUPLICATE KEY UPDATE id_queue=VALUES(id_queue),id_run=VALUES(id_run),source=VALUES(source),source_key=VALUES(source_key),reason=VALUES(reason),available_at=VALUES(available_at),last_error=VALUES(last_error),updated_at=NOW()",
            _DB_PREFIX_, self::TABLE,
            (int) ($queueRow['id_queue'] ?? 0),
            (int) ($queueRow['id_run'] ?? 0),
            (int) ($queueRow['id_shop'] ?? 0),
            pSQL((string) ($queueRow['source'] ?? '')),
            pSQL((string) ($queueRow['source_key'] ?? '')),
            (int) ($queueRow['id_product'] ?? 0),
            $idImage,
            pSQL(mb_substr(trim($reason), 0, 64), true),
            self::INITIAL_DELAY_MINUTES,
            $errorSql
        );
        if (!\Db::getInstance()->execute($sql)) {
            throw new \RuntimeException('Matterhorn image orphan recovery marker save failed');
        }
    }

    /** @return list<array<string,mixed>> */
    public function due(int $limit, ?int $shopId = null): array
    {
        $limit = max(1, min(2000, $limit));
        $shopWhere = $shopId === null ? '' : ' AND id_shop=' . (int) $shopId;
        return \Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE (available_at IS NULL OR available_at<=NOW())' . $shopWhere . ' ORDER BY id_orphan LIMIT ' . $limit
        ) ?: [];
    }

    public function forget(int $idOrphan): void
    {
        if ($idOrphan <= 0) { return; }
        if (!\Db::getInstance()->delete(self::TABLE, 'id_orphan=' . $idOrphan)) {
            throw new \RuntimeException('Matterhorn image orphan recovery marker delete failed');
        }
    }

    public function forgetImage(int $shopId, int $productId, int $idImage): void
    {
        if ($shopId <= 0 || $productId <= 0 || $idImage <= 0) { return; }
        if (!\Db::getInstance()->delete(self::TABLE, 'id_shop=' . $shopId . ' AND id_product=' . $productId . ' AND id_image=' . $idImage)) {
            throw new \RuntimeException('Matterhorn image orphan recovery marker delete failed');
        }
    }

    public function defer(int $idOrphan, string $error): void
    {
        if ($idOrphan <= 0) { return; }
        $sql = sprintf(
            "UPDATE `%s%s` SET attempts=LEAST(attempts+1,255),available_at=TIMESTAMPADD(SECOND,CASE WHEN attempts<1 THEN 900 WHEN attempts<3 THEN 3600 WHEN attempts<6 THEN 21600 ELSE 86400 END,NOW()),last_error='%s',updated_at=NOW() WHERE id_orphan=%d",
            _DB_PREFIX_, self::TABLE, pSQL(mb_substr($error, 0, 4000), true), $idOrphan
        );
        if (!\Db::getInstance()->execute($sql)) {
            throw new \RuntimeException('Matterhorn image orphan recovery defer failed');
        }
    }

    public function count(?int $shopId = null, ?string $source = null): int
    {
        $where = [];
        if ($shopId !== null) { $where[] = 'id_shop=' . (int) $shopId; }
        if ($source !== null) { $where[] = "source='" . pSQL($source) . "'"; }
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '`' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
        );
    }
}
