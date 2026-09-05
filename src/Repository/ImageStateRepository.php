<?php
namespace Lp\MatterhornImport\Repository;

use Lp\MatterhornImport\Image\DownloadedImage;

final class ImageStateRepository
{
    private const TABLE = 'li_matterhornim_99dfbf_image_state';

    public function findByUrlHash(int $shopId, string $source, string $sourceKey, int $productId, string $urlHash): ?array
    {
        $row = \Db::getInstance()->getRow(sprintf(
            "SELECT s.* FROM `%s%s` s INNER JOIN `%simage` i ON i.id_image=s.id_image AND i.id_product=s.id_product INNER JOIN `%simage_shop` ish ON ish.id_image=s.id_image AND ish.id_shop=s.id_shop WHERE s.id_shop=%d AND s.source='%s' AND s.source_key='%s' AND s.id_product=%d AND s.url_hash='%s' AND s.id_image>0",
            _DB_PREFIX_, self::TABLE, _DB_PREFIX_, _DB_PREFIX_, $shopId, pSQL($source), pSQL($sourceKey), $productId, pSQL($urlHash)
        ));
        return is_array($row) ? $row : null;
    }

    public function findByContentHash(int $shopId, string $source, int $productId, string $contentHash): ?array
    {
        if ($contentHash === '') {
            return null;
        }
        $row = \Db::getInstance()->getRow(sprintf(
            "SELECT s.id_image,s.url_hash FROM `%s%s` s INNER JOIN `%simage` i ON i.id_image=s.id_image AND i.id_product=s.id_product INNER JOIN `%simage_shop` ish ON ish.id_image=s.id_image AND ish.id_shop=s.id_shop WHERE s.id_shop=%d AND s.source='%s' AND s.id_product=%d AND s.content_hash='%s' AND s.id_image>0 ORDER BY s.updated_at DESC",
            _DB_PREFIX_, self::TABLE, _DB_PREFIX_, _DB_PREFIX_, $shopId, pSQL($source), $productId, pSQL($contentHash)
        ));
        return is_array($row) ? $row : null;
    }

    public function touchNotModified(array $queueRow, int $idImage): void
    {
        $sql = sprintf(
            "UPDATE `%s%s` SET position=%d,is_cover=%d,last_seen_run_id=%d,updated_at=NOW() WHERE id_shop=%d AND source='%s' AND source_key='%s' AND url_hash='%s' AND id_product=%d AND id_image=%d",
            _DB_PREFIX_, self::TABLE, (int) $queueRow['position'], (int) $queueRow['is_cover'], (int) $queueRow['id_run'],
            (int) $queueRow['id_shop'], pSQL((string) $queueRow['source']), pSQL((string) $queueRow['source_key']), pSQL((string) $queueRow['url_hash']), (int) $queueRow['id_product'], $idImage
        );
        $db = \Db::getInstance();
        if (!$db->execute($sql) || (int) $db->Affected_Rows() > 1) {
            throw new \RuntimeException('Image state revalidation update failed');
        }
    }

    public function save(array $queueRow, int $idImage, DownloadedImage $download): void
    {
        $etag = $download->etag === null ? 'NULL' : "'" . pSQL(mb_substr($download->etag, 0, 255), true) . "'";
        $lastModified = $download->lastModified === null ? 'NULL' : "'" . pSQL(mb_substr($download->lastModified, 0, 255), true) . "'";
        $sql = sprintf(
            "INSERT INTO `%s%s` (`id_shop`,`source`,`source_key`,`url_hash`,`content_hash`,`etag`,`last_modified`,`mime`,`width`,`height`,`bytes`,`id_product`,`id_image`,`position`,`is_cover`,`last_seen_run_id`,`updated_at`) VALUES (%d,'%s','%s','%s','%s',%s,%s,'%s',%d,%d,%d,%d,%d,%d,%d,%d,NOW()) ON DUPLICATE KEY UPDATE content_hash=VALUES(content_hash),etag=VALUES(etag),last_modified=VALUES(last_modified),mime=VALUES(mime),width=VALUES(width),height=VALUES(height),bytes=VALUES(bytes),id_product=VALUES(id_product),id_image=VALUES(id_image),position=VALUES(position),is_cover=VALUES(is_cover),last_seen_run_id=VALUES(last_seen_run_id),updated_at=NOW()",
            _DB_PREFIX_, self::TABLE, (int) $queueRow['id_shop'], pSQL((string) $queueRow['source']), pSQL((string) $queueRow['source_key']), pSQL((string) $queueRow['url_hash']),
            pSQL($download->contentHash), $etag, $lastModified, pSQL($download->mime), $download->width, $download->height, $download->bytes,
            (int) $queueRow['id_product'], $idImage, (int) $queueRow['position'], (int) $queueRow['is_cover'], (int) $queueRow['id_run']
        );
        if (!\Db::getInstance()->execute($sql)) {
            throw new \RuntimeException('Image state save failed');
        }
    }

    public function canDeleteReplacedImage(int $shopId, int $productId, int $idImage): bool
    {
        if ($shopId <= 0 || $productId <= 0 || $idImage <= 0) {
            return false;
        }
        $db = \Db::getInstance();
        $stateRefs = (int) $db->getValue(sprintf('SELECT COUNT(*) FROM `%s%s` WHERE id_product=%d AND id_image=%d', _DB_PREFIX_, self::TABLE, $productId, $idImage));
        if ($stateRefs !== 0) {
            return false;
        }
        $imageExists = (bool) $db->getValue(sprintf('SELECT 1 FROM `%simage` WHERE id_image=%d AND id_product=%d', _DB_PREFIX_, $idImage, $productId));
        if (!$imageExists) {
            return false;
        }
        $shopRows = $db->executeS(sprintf('SELECT id_shop FROM `%simage_shop` WHERE id_image=%d', _DB_PREFIX_, $idImage)) ?: [];
        return count($shopRows) === 1 && (int) $shopRows[0]['id_shop'] === $shopId;
    }
}
