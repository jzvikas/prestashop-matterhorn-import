<?php
namespace Lp\MatterhornImport\Repository;

use Lp\MatterhornImport\DTO\ProductData;

final class MappingRepository
{
    private const TABLE = 'li_matterhornim_99dfbf_mapping';

    public function save(int $shopId, string $source, int $runId, int $productId, ProductData $product): void
    {
        if ($shopId <= 0 || $runId <= 0 || $productId <= 0 || trim($source) === '') { throw new \InvalidArgumentException('Mapping save requires valid shop/source/run/product'); }
        $sql = sprintf(
            "INSERT INTO `%s%s` (`id_shop`,`source`,`source_key`,`id_product`,`payload_hash`,`core_hash`,`price_hash`,`stock_hash`,`attribute_hash`,`feature_hash`,`category_hash`,`combination_hash`,`combination_stock_hash`,`specific_price_hash`,`image_hash`,`out_of_feed`,`last_seen_run_id`,`updated_at`) " .
            "VALUES (%d,'%s','%s',%d,'%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s',0,%d,'%s') " .
            "ON DUPLICATE KEY UPDATE `id_product`=VALUES(`id_product`),`payload_hash`=VALUES(`payload_hash`),`core_hash`=VALUES(`core_hash`),`price_hash`=VALUES(`price_hash`),`stock_hash`=VALUES(`stock_hash`),`attribute_hash`=VALUES(`attribute_hash`),`feature_hash`=VALUES(`feature_hash`),`category_hash`=VALUES(`category_hash`),`combination_hash`=VALUES(`combination_hash`),`combination_stock_hash`=VALUES(`combination_stock_hash`),`specific_price_hash`=VALUES(`specific_price_hash`),`image_hash`=VALUES(`image_hash`),`out_of_feed`=0,`last_seen_run_id`=VALUES(`last_seen_run_id`),`updated_at`=VALUES(`updated_at`)",
            _DB_PREFIX_, self::TABLE, $shopId, pSQL($source), pSQL($product->sourceKey), $productId,
            pSQL($product->payloadHash()), pSQL($product->coreHash()), pSQL($product->priceHash()), pSQL($product->stockHash()),
            pSQL($product->attributeHash()), pSQL($product->featureHash()), pSQL($product->categoryHash()), pSQL($product->combinationHash()),
            pSQL($product->combinationStockHash()), pSQL($product->specificPriceHash()), pSQL($product->imageHash()), $runId, date('Y-m-d H:i:s')
        );
        if (!\Db::getInstance()->execute($sql)) { throw new \RuntimeException('Matterhorn mapping save failed'); }
    }

    public function findProductId(int $shopId, string $source, string $sourceKey): int
    {
        return (int) \Db::getInstance()->getValue(sprintf("SELECT id_product FROM `%s%s` WHERE id_shop=%d AND source='%s' AND source_key='%s'", _DB_PREFIX_, self::TABLE, $shopId, pSQL($source), pSQL($sourceKey)));
    }

    public function markOutOfFeed(int $shopId, string $source, string $sourceKey, int $runId): void
    {
        if (!\Db::getInstance()->update(self::TABLE, [
            'out_of_feed' => 1,'last_seen_run_id' => $runId,'updated_at' => date('Y-m-d H:i:s'),
        ], sprintf("id_shop=%d AND source='%s' AND source_key='%s'", $shopId, pSQL($source), pSQL($sourceKey)))) {
            throw new \RuntimeException('Matterhorn out-of-feed mapping update failed');
        }
    }

    public function touchSeen(int $shopId, string $source, string $sourceKey, int $runId): void
    {
        if (!\Db::getInstance()->update(self::TABLE, ['out_of_feed'=>0,'last_seen_run_id'=>$runId,'updated_at'=>date('Y-m-d H:i:s')], sprintf("id_shop=%d AND source='%s' AND source_key='%s'", $shopId, pSQL($source), pSQL($sourceKey)))) {
            throw new \RuntimeException('Matterhorn mapping touch failed');
        }
    }

    public function delete(int $shopId, string $source, string $sourceKey): void
    {
        if (!\Db::getInstance()->delete(self::TABLE, sprintf("id_shop=%d AND source='%s' AND source_key='%s'", $shopId, pSQL($source), pSQL($sourceKey)))) { throw new \RuntimeException('Matterhorn mapping delete failed'); }
    }

    public function countSource(int $shopId, string $source): int
    {
        return (int) \Db::getInstance()->getValue(sprintf("SELECT COUNT(*) FROM `%s%s` WHERE id_shop=%d AND source='%s'", _DB_PREFIX_, self::TABLE, $shopId, pSQL($source)));
    }

    public function countInFeedSource(int $shopId, string $source): int
    {
        return (int) \Db::getInstance()->getValue(sprintf("SELECT COUNT(*) FROM `%s%s` WHERE id_shop=%d AND source='%s' AND out_of_feed=0", _DB_PREFIX_, self::TABLE, $shopId, pSQL($source)));
    }
}
