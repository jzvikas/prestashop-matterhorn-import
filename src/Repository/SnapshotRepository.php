<?php
namespace Lp\MatterhornImport\Repository;

use Lp\MatterhornImport\DTO\ProductData;

final class SnapshotRepository
{
    private const TABLE = 'li_matterhornim_99dfbf_snapshot';

    /** @param list<ProductData> $products */
    public function upsertBatch(int $runId, array $products): void
    {
        if ($products === []) {
            return;
        }
        $values = [];
        foreach ($products as $product) {
            if (!$product instanceof ProductData) {
                throw new \InvalidArgumentException('Snapshot batch contains non-ProductData item');
            }
            $values[] = sprintf(
                "(%d,'%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",
                $runId,
                pSQL($product->sourceKey),
                pSQL($product->reference),
                pSQL($product->payloadHash()),
                pSQL($product->coreHash()),
                pSQL($product->priceHash()),
                pSQL($product->stockHash()),
                pSQL($product->attributeHash()),
                pSQL($product->featureHash()),
                pSQL($product->categoryHash()),
                pSQL($product->combinationHash()),
                pSQL($product->combinationStockHash()),
                pSQL($product->specificPriceHash()),
                pSQL($product->imageHash()),
                pSQL($product->toJson(), true)
            );
        }
        $sql = sprintf(
            "INSERT INTO `%s%s` (`id_run`,`source_key`,`reference`,`payload_hash`,`core_hash`,`price_hash`,`stock_hash`,`attribute_hash`,`feature_hash`,`category_hash`,`combination_hash`,`combination_stock_hash`,`specific_price_hash`,`image_hash`,`payload`) VALUES %s " .
            "ON DUPLICATE KEY UPDATE `reference`=VALUES(`reference`),`payload_hash`=VALUES(`payload_hash`),`core_hash`=VALUES(`core_hash`),`price_hash`=VALUES(`price_hash`),`stock_hash`=VALUES(`stock_hash`),`attribute_hash`=VALUES(`attribute_hash`),`feature_hash`=VALUES(`feature_hash`),`category_hash`=VALUES(`category_hash`),`combination_hash`=VALUES(`combination_hash`),`combination_stock_hash`=VALUES(`combination_stock_hash`),`specific_price_hash`=VALUES(`specific_price_hash`),`image_hash`=VALUES(`image_hash`),`payload`=VALUES(`payload`)",
            _DB_PREFIX_, self::TABLE, implode(',', $values)
        );
        if (!\Db::getInstance()->execute($sql)) {
            throw new \RuntimeException('Matterhorn snapshot batch upsert failed');
        }
    }

    public function purgeRun(int $runId): int
    {
        return (int) \Db::getInstance()->delete(self::TABLE, 'id_run=' . (int) $runId);
    }

    public function countRun(int $runId): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_run=' . (int) $runId
        );
    }
}
