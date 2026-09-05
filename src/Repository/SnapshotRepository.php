<?php
namespace Lp\MatterhornImport\Repository;

use Lp\MatterhornImport\DTO\ProductData;

final class SnapshotRepository
{
    private const TABLE = 'li_matterhornim_99dfbf_snapshot';
    private const MAPPING_TABLE = 'li_matterhornim_99dfbf_mapping';
    private const MAX_FETCH_PAYLOAD_BYTES = 8388608;

    /** @param list<ProductData> $products */
    public function upsertBatch(int $runId, array $products): void
    {
        if ($products === []) { return; }
        $values = [];
        foreach ($products as $product) {
            if (!$product instanceof ProductData) { throw new \InvalidArgumentException('Snapshot batch contains non-ProductData item'); }
            $values[] = sprintf(
                "(%d,'%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",
                $runId, pSQL($product->sourceKey), pSQL($product->reference), pSQL($product->payloadHash()),
                pSQL($product->coreHash()), pSQL($product->priceHash()), pSQL($product->stockHash()),
                pSQL($product->attributeHash()), pSQL($product->featureHash()), pSQL($product->categoryHash()),
                pSQL($product->combinationHash()), pSQL($product->combinationStockHash()),
                pSQL($product->specificPriceHash()), pSQL($product->imageHash()), pSQL($product->toJson(), true)
            );
        }
        $sql = sprintf(
            "INSERT INTO `%s%s` (`id_run`,`source_key`,`reference`,`payload_hash`,`core_hash`,`price_hash`,`stock_hash`,`attribute_hash`,`feature_hash`,`category_hash`,`combination_hash`,`combination_stock_hash`,`specific_price_hash`,`image_hash`,`payload`) VALUES %s " .
            "ON DUPLICATE KEY UPDATE `reference`=VALUES(`reference`),`payload_hash`=VALUES(`payload_hash`),`core_hash`=VALUES(`core_hash`),`price_hash`=VALUES(`price_hash`),`stock_hash`=VALUES(`stock_hash`),`attribute_hash`=VALUES(`attribute_hash`),`feature_hash`=VALUES(`feature_hash`),`category_hash`=VALUES(`category_hash`),`combination_hash`=VALUES(`combination_hash`),`combination_stock_hash`=VALUES(`combination_stock_hash`),`specific_price_hash`=VALUES(`specific_price_hash`),`image_hash`=VALUES(`image_hash`),`payload`=VALUES(`payload`)",
            _DB_PREFIX_, self::TABLE, implode(',', $values)
        );
        if (!\Db::getInstance()->execute($sql)) { throw new \RuntimeException('Matterhorn snapshot batch upsert failed'); }
    }

    public function purgeRun(int $runId): int
    {
        return (int) \Db::getInstance()->delete(self::TABLE, 'id_run=' . (int) $runId);
    }

    public function countRun(int $runId): int
    {
        return (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_run=' . (int) $runId);
    }

    /** @return list<array<string,mixed>> */
    public function newRows(int $runId, int $shopId, string $source, string $after = '', int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $cursor = $after === '' ? '' : " AND s.source_key>'" . pSQL($after) . "'";
        $join = sprintf(
            " FROM `%s%s` s LEFT JOIN `%s%s` m ON m.id_shop=%d AND m.source='%s' AND m.source_key=s.source_key WHERE s.id_run=%d AND m.id_product IS NULL%s",
            _DB_PREFIX_, self::TABLE, _DB_PREFIX_, self::MAPPING_TABLE, $shopId, pSQL($source), $runId, $cursor
        );
        $window = $this->payloadWindow('SELECT s.source_key,OCTET_LENGTH(s.payload) payload_bytes' . $join . ' ORDER BY s.source_key LIMIT ' . $limit, 'source_key');
        if ($window === null) { return []; }
        $sql = 'SELECT s.*' . $join . " AND s.source_key<='" . pSQL((string) $window['last']) . "' ORDER BY s.source_key LIMIT " . (int) $window['count'];
        return \Db::getInstance()->executeS($sql) ?: [];
    }

    /** @return array{last:int|string,count:int}|null */
    private function payloadWindow(string $sql, string $cursorField): ?array
    {
        $rows = \Db::getInstance()->executeS($sql) ?: [];
        if ($rows === []) { return null; }
        $bytes = 0; $count = 0; $last = null;
        foreach ($rows as $row) {
            $rowBytes = max(0, (int) ($row['payload_bytes'] ?? 0));
            if ($count > 0 && $bytes + $rowBytes > self::MAX_FETCH_PAYLOAD_BYTES) { break; }
            $bytes += $rowBytes; $count++; $last = $row[$cursorField];
            if ($bytes >= self::MAX_FETCH_PAYLOAD_BYTES) { break; }
        }
        return $count > 0 && $last !== null ? ['last' => $last, 'count' => $count] : null;
    }
}
