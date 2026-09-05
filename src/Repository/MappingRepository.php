<?php
namespace Lp\MatterhornImport\Repository;

use Lp\MatterhornImport\DTO\ProductData;

final class MappingRepository
{
    private const TABLE = 'li_matterhornim_99dfbf_mapping';

    public function save(int $shopId, string $source, int $runId, int $productId, ProductData $product): void
    {
        $source = trim($source);
        if ($shopId <= 0 || $runId <= 0 || $productId <= 0 || $source === '' || trim($product->sourceKey) === '') {
            throw new \InvalidArgumentException('Mapping save requires valid shop/source/run/product');
        }

        $db = \Db::getInstance();
        $where = sprintf(
            "id_shop=%d AND source='%s' AND source_key='%s'",
            $shopId,
            pSQL($source),
            pSQL($product->sourceKey)
        );
        $data = $this->data($runId, $productId, $product);

        if (!$db->update(self::TABLE, $data, $where, 0, true)) {
            throw new \RuntimeException('Matterhorn exact-owner mapping update failed: ' . $db->getMsgError());
        }

        $existingProductId = $this->findProductId($shopId, $source, $product->sourceKey);
        if ($existingProductId > 0) {
            if ($existingProductId !== $productId) {
                throw new \RuntimeException('Matterhorn mapping ownership changed while saving source key ' . $product->sourceKey);
            }
            return;
        }

        $insert = ['id_shop' => $shopId, 'source' => pSQL($source), 'source_key' => pSQL($product->sourceKey)] + $data;
        if ($db->insert(self::TABLE, $insert, false, true, \Db::INSERT)) {
            return;
        }

        $afterRace = $this->findProductId($shopId, $source, $product->sourceKey);
        if ($afterRace === $productId) {
            if (!$db->update(self::TABLE, $data, $where, 0, true)) {
                throw new \RuntimeException('Matterhorn concurrent mapping refresh failed: ' . $db->getMsgError());
            }
            return;
        }

        $owner = $this->findOwnerByProduct($shopId, $productId);
        if ($owner !== null) {
            throw new \RuntimeException(sprintf(
                'Matterhorn product ownership conflict: shop %d product %d is already owned by %s/%s',
                $shopId,
                $productId,
                $owner['source'],
                $owner['source_key']
            ));
        }

        throw new \RuntimeException('Matterhorn mapping insert failed: ' . $db->getMsgError());
    }

    public function findProductId(int $shopId, string $source, string $sourceKey): int
    {
        return (int) \Db::getInstance()->getValue(sprintf(
            "SELECT id_product FROM `%s%s` WHERE id_shop=%d AND source='%s' AND source_key='%s'",
            _DB_PREFIX_, self::TABLE, $shopId, pSQL($source), pSQL($sourceKey)
        ), false);
    }

    /** @return array{source:string,source_key:string}|null */
    public function findOwnerByProduct(int $shopId, int $productId): ?array
    {
        if ($shopId <= 0 || $productId <= 0) { return null; }
        $row = \Db::getInstance()->getRow(sprintf(
            "SELECT source,source_key FROM `%s%s` WHERE id_shop=%d AND id_product=%d",
            _DB_PREFIX_, self::TABLE, $shopId, $productId
        ), false);
        if (!is_array($row) || trim((string) ($row['source'] ?? '')) === '' || trim((string) ($row['source_key'] ?? '')) === '') {
            return null;
        }
        return ['source' => (string) $row['source'], 'source_key' => (string) $row['source_key']];
    }

    public function ownsProduct(int $shopId, string $source, string $sourceKey, int $productId): bool
    {
        if ($shopId <= 0 || $productId <= 0 || trim($source) === '' || trim($sourceKey) === '') { return false; }
        return $this->findProductId($shopId, $source, $sourceKey) === $productId;
    }

    public function lockProductOwnership(int $shopId, string $source, string $sourceKey, int $productId): bool
    {
        if ($shopId <= 0 || $productId <= 0 || trim($source) === '' || trim($sourceKey) === '') { return false; }
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT id_product FROM `%s%s` WHERE id_shop=%d AND source='%s' AND source_key='%s' LIMIT 1 FOR UPDATE",
            _DB_PREFIX_, self::TABLE, $shopId, pSQL($source), pSQL($sourceKey)
        ), true, false) ?: [];
        return count($rows) === 1 && (int) $rows[0]['id_product'] === $productId;
    }

    public function deleteOwned(int $shopId, string $source, string $sourceKey, int $productId): void
    {
        if ($shopId <= 0 || $productId <= 0 || trim($source) === '' || trim($sourceKey) === '') { throw new \InvalidArgumentException('Invalid mapping ownership delete context'); }
        $db = \Db::getInstance();
        if (!$db->delete(self::TABLE, sprintf("id_shop=%d AND source='%s' AND source_key='%s' AND id_product=%d", $shopId, pSQL($source), pSQL($sourceKey), $productId))) {
            throw new \RuntimeException('Matterhorn mapping ownership delete failed');
        }
        if ((int) $db->Affected_Rows() !== 1) { throw new \RuntimeException('Matterhorn mapping ownership changed before delete'); }
    }

    public function markOutOfFeed(int $shopId, string $source, string $sourceKey, int $productId, int $runId): void
    {
        if ($shopId <= 0 || $productId <= 0 || $runId <= 0 || trim($source) === '' || trim($sourceKey) === '') {
            throw new \InvalidArgumentException('Invalid out-of-feed mapping ownership context');
        }
        $db = \Db::getInstance();
        if (!$db->update(
            self::TABLE,
            ['out_of_feed' => 1, 'last_seen_run_id' => $runId, 'updated_at' => date('Y-m-d H:i:s')],
            sprintf(
                "id_shop=%d AND source='%s' AND source_key='%s' AND id_product=%d",
                $shopId,
                pSQL($source),
                pSQL($sourceKey),
                $productId
            )
        )) {
            throw new \RuntimeException('Matterhorn out-of-feed mapping update failed');
        }
        if ((int) $db->Affected_Rows() !== 1) {
            throw new \RuntimeException('Matterhorn mapping ownership changed before out-of-feed completion');
        }
    }

    public function touchSeen(int $shopId, string $source, string $sourceKey, int $runId): void
    {
        if (!\Db::getInstance()->update(self::TABLE, ['out_of_feed'=>0,'last_seen_run_id'=>$runId,'updated_at'=>date('Y-m-d H:i:s')], sprintf("id_shop=%d AND source='%s' AND source_key='%s'", $shopId, pSQL($source), pSQL($sourceKey)))) { throw new \RuntimeException('Matterhorn mapping touch failed'); }
    }

    public function delete(int $shopId, string $source, string $sourceKey): void
    {
        if (!\Db::getInstance()->delete(self::TABLE, sprintf("id_shop=%d AND source='%s' AND source_key='%s'", $shopId, pSQL($source), pSQL($sourceKey)))) { throw new \RuntimeException('Matterhorn mapping delete failed'); }
    }

    public function countSource(int $shopId, string $source): int
    {
        return (int) \Db::getInstance()->getValue(sprintf("SELECT COUNT(*) FROM `%s%s` WHERE id_shop=%d AND source='%s'", _DB_PREFIX_, self::TABLE, $shopId, pSQL($source)), false);
    }

    public function countInFeedSource(int $shopId, string $source): int
    {
        return (int) \Db::getInstance()->getValue(sprintf("SELECT COUNT(*) FROM `%s%s` WHERE id_shop=%d AND source='%s' AND out_of_feed=0", _DB_PREFIX_, self::TABLE, $shopId, pSQL($source)), false);
    }

    private function data(int $runId, int $productId, ProductData $product): array
    {
        return [
            'id_product' => $productId,
            'payload_hash' => pSQL($product->payloadHash()),
            'core_hash' => pSQL($product->coreHash()),
            'price_hash' => pSQL($product->priceHash()),
            'stock_hash' => pSQL($product->stockHash()),
            'attribute_hash' => pSQL($product->attributeHash()),
            'feature_hash' => pSQL($product->featureHash()),
            'category_hash' => pSQL($product->categoryHash()),
            'combination_hash' => pSQL($product->combinationHash()),
            'combination_stock_hash' => pSQL($product->combinationStockHash()),
            'specific_price_hash' => pSQL($product->specificPriceHash()),
            'image_hash' => pSQL($product->imageHash()),
            'out_of_feed' => 0,
            'last_seen_run_id' => $runId,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }
}
