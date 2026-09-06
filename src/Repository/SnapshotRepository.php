<?php
namespace Lp\MatterhornImport\Repository;

use Lp\MatterhornImport\DTO\ProductData;

final class SnapshotRepository
{
    private const TABLE = 'li_matterhornim_99dfbf_snapshot';
    private const MAPPING_TABLE = 'li_matterhornim_99dfbf_mapping';
    private const MAX_FETCH_PAYLOAD_BYTES = 8388608;
    private const MAX_WRITE_SQL_BYTES = 8388608; // 8 MiB including SQL syntax and escaped payloads

    /** @param list<ProductData> $products */
    public function upsertBatch(int $runId, array $products): void
    {
        if ($products === []) { return; }

        $prefix = sprintf(
            "INSERT INTO `%s%s` (`id_run`,`source_key`,`reference`,`payload_hash`,`core_hash`,`price_hash`,`stock_hash`,`attribute_hash`,`feature_hash`,`category_hash`,`combination_hash`,`combination_stock_hash`,`specific_price_hash`,`image_hash`,`payload`) VALUES ",
            _DB_PREFIX_,
            self::TABLE
        );
        $suffix = " ON DUPLICATE KEY UPDATE `reference`=VALUES(`reference`),`payload_hash`=VALUES(`payload_hash`),`core_hash`=VALUES(`core_hash`),`price_hash`=VALUES(`price_hash`),`stock_hash`=VALUES(`stock_hash`),`attribute_hash`=VALUES(`attribute_hash`),`feature_hash`=VALUES(`feature_hash`),`category_hash`=VALUES(`category_hash`),`combination_hash`=VALUES(`combination_hash`),`combination_stock_hash`=VALUES(`combination_stock_hash`),`specific_price_hash`=VALUES(`specific_price_hash`),`image_hash`=VALUES(`image_hash`),`payload`=VALUES(`payload`)";
        $valuesBudget = self::MAX_WRITE_SQL_BYTES - strlen($prefix) - strlen($suffix);
        if ($valuesBudget <= 0) {
            throw new \RuntimeException('Matterhorn snapshot SQL write budget is smaller than statement overhead');
        }

        $values = [];
        $valuesBytes = 0;
        foreach ($products as $product) {
            if (!$product instanceof ProductData) {
                throw new \InvalidArgumentException('Snapshot batch contains non-ProductData item');
            }
            $row = $this->valueRow($runId, $product);
            $rowBytes = strlen($row);
            if ($rowBytes > $valuesBudget) {
                throw new \RuntimeException(
                    'Escaped Matterhorn snapshot row exceeds SQL write budget for source key ' . $product->sourceKey
                );
            }

            $separatorBytes = $values === [] ? 0 : 1;
            if ($values !== [] && $valuesBytes + $separatorBytes + $rowBytes > $valuesBudget) {
                $this->executeValueChunk($prefix, $suffix, $values);
                $values = [];
                $valuesBytes = 0;
                $separatorBytes = 0;
            }

            $values[] = $row;
            $valuesBytes += $separatorBytes + $rowBytes;
        }

        if ($values !== []) {
            $this->executeValueChunk($prefix, $suffix, $values);
        }
    }

    public function purgeRun(int $runId): int { return (int) \Db::getInstance()->delete(self::TABLE, 'id_run=' . (int) $runId); }
    public function countRun(int $runId): int { return (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_run=' . (int) $runId, false); }

    public function newRows(int $runId, int $shopId, string $source, string $after = '', int $limit = 500): array
    {
        $limit=max(1,min(2000,$limit)); $cursor=$after===''?'':" AND s.source_key>'".pSQL($after)."'";
        $join=sprintf(" FROM `%s%s` s LEFT JOIN `%s%s` m ON m.id_shop=%d AND m.source='%s' AND m.source_key=s.source_key WHERE s.id_run=%d AND m.id_product IS NULL%s",_DB_PREFIX_,self::TABLE,_DB_PREFIX_,self::MAPPING_TABLE,$shopId,pSQL($source),$runId,$cursor);
        $window=$this->payloadWindow('SELECT s.source_key,OCTET_LENGTH(s.payload) payload_bytes'.$join.' ORDER BY s.source_key LIMIT '.$limit,'source_key');
        if($window===null){return [];} return \Db::getInstance()->executeS('SELECT s.*'.$join." AND s.source_key<='".pSQL((string)$window['last'])."' ORDER BY s.source_key LIMIT ".(int)$window['count'], true, false)?:[];
    }

    public function changedRows(int $runId,int $shopId,string $source,int $afterProductId=0,int $limit=500):array
    {
        $limit=max(1,min(2000,$limit));
        $where=sprintf(" FROM `%s%s` s INNER JOIN `%s%s` m ON m.id_shop=%d AND m.source='%s' AND m.source_key=s.source_key LEFT JOIN `%sproduct` p ON p.id_product=m.id_product LEFT JOIN `%sproduct_shop` ps ON ps.id_product=m.id_product AND ps.id_shop=%d WHERE s.id_run=%d AND m.id_product>%d AND (m.out_of_feed=1 OR p.id_product IS NULL OR ps.id_product IS NULL OR s.payload_hash<>m.payload_hash OR s.core_hash<>m.core_hash OR s.price_hash<>m.price_hash OR s.stock_hash<>m.stock_hash OR s.attribute_hash<>m.attribute_hash OR s.feature_hash<>m.feature_hash OR s.category_hash<>m.category_hash OR s.combination_hash<>m.combination_hash OR s.combination_stock_hash<>m.combination_stock_hash OR s.specific_price_hash<>m.specific_price_hash OR s.image_hash<>m.image_hash)",_DB_PREFIX_,self::TABLE,_DB_PREFIX_,self::MAPPING_TABLE,$shopId,pSQL($source),_DB_PREFIX_,_DB_PREFIX_,$shopId,$runId,$afterProductId);
        $window=$this->payloadWindow('SELECT m.id_product,OCTET_LENGTH(s.payload) payload_bytes'.$where.' ORDER BY m.id_product LIMIT '.$limit,'id_product'); if($window===null){return [];}
        $sql="SELECT s.*,m.id_product,m.out_of_feed AS old_out_of_feed,CASE WHEN p.id_product IS NULL THEN 0 ELSE 1 END AS product_exists,CASE WHEN ps.id_product IS NULL THEN 0 ELSE 1 END AS product_shop_exists,m.payload_hash old_payload_hash,m.core_hash old_core_hash,m.price_hash old_price_hash,m.stock_hash old_stock_hash,m.attribute_hash old_attribute_hash,m.feature_hash old_feature_hash,m.category_hash old_category_hash,m.combination_hash old_combination_hash,m.combination_stock_hash old_combination_stock_hash,m.specific_price_hash old_specific_price_hash,m.image_hash old_image_hash".$where.' AND m.id_product<='.(int)$window['last'].' ORDER BY m.id_product LIMIT '.(int)$window['count'];
        return \Db::getInstance()->executeS($sql, true, false)?:[];
    }

    public function removedRows(int $runId,int $shopId,string $source,int $afterProductId=0,int $limit=500):array
    {
        $limit=max(1,min(2000,$limit)); return \Db::getInstance()->executeS(sprintf("SELECT m.* FROM `%s%s` m LEFT JOIN `%s%s` s ON s.id_run=%d AND s.source_key=m.source_key WHERE m.id_shop=%d AND m.source='%s' AND m.out_of_feed=0 AND m.id_product>%d AND s.source_key IS NULL ORDER BY m.id_product LIMIT %d",_DB_PREFIX_,self::MAPPING_TABLE,_DB_PREFIX_,self::TABLE,$runId,$shopId,pSQL($source),$afterProductId,$limit), true, false)?:[];
    }

    public function countRemoved(int $runId,int $shopId,string $source):int
    {
        return (int)\Db::getInstance()->getValue(sprintf("SELECT COUNT(*) FROM `%s%s` m LEFT JOIN `%s%s` s ON s.id_run=%d AND s.source_key=m.source_key WHERE m.id_shop=%d AND m.source='%s' AND m.out_of_feed=0 AND s.source_key IS NULL",_DB_PREFIX_,self::MAPPING_TABLE,_DB_PREFIX_,self::TABLE,$runId,$shopId,pSQL($source)), false);
    }

    public function imageManifestRows(int $runId, int $shopId, string $source, string $after = '', int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $cursor = $after === '' ? '' : " AND s.source_key>'" . pSQL($after) . "'";
        $where = sprintf(" FROM `%s%s` s INNER JOIN `%s%s` m ON m.id_shop=%d AND m.source='%s' AND m.source_key=s.source_key WHERE s.id_run=%d%s", _DB_PREFIX_, self::TABLE, _DB_PREFIX_, self::MAPPING_TABLE, $shopId, pSQL($source), $runId, $cursor);
        $window = $this->payloadWindow('SELECT s.source_key,OCTET_LENGTH(s.payload) payload_bytes' . $where . ' ORDER BY s.source_key LIMIT ' . $limit, 'source_key');
        if ($window === null) { return []; }
        return \Db::getInstance()->executeS('SELECT s.source_key,s.payload,m.id_product' . $where . " AND s.source_key<='" . pSQL((string) $window['last']) . "' ORDER BY s.source_key LIMIT " . (int) $window['count'], true, false) ?: [];
    }

    /** @param list<string> $sourceKeys @return list<string> */
    public function imageManifestSourceKeysForSourceKeys(int $runId, int $shopId, string $source, array $sourceKeys): array
    {
        $keys = array_values(array_unique(array_filter(
            array_map(static fn(mixed $key): string => trim((string) $key), $sourceKeys),
            static fn(string $key): bool => $key !== ''
        )));
        if ($keys === []) { return []; }
        if (count($keys) > 5000) { throw new \InvalidArgumentException('Image manifest availability lookup exceeds bounded key limit'); }

        $found = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            $quoted = implode(',', array_map(static fn(string $key): string => "'" . pSQL($key) . "'", $chunk));
            $rows = \Db::getInstance()->executeS(sprintf(
                "SELECT s.source_key FROM `%s%s` s INNER JOIN `%s%s` m ON m.id_shop=%d AND m.source='%s' AND m.source_key=s.source_key WHERE s.id_run=%d AND s.source_key IN (%s) ORDER BY s.source_key",
                _DB_PREFIX_, self::TABLE, _DB_PREFIX_, self::MAPPING_TABLE, $shopId, pSQL($source), $runId, $quoted
            ), true, false) ?: [];
            foreach ($rows as $row) {
                $key = trim((string) ($row['source_key'] ?? ''));
                if ($key !== '') { $found[$key] = $key; }
            }
        }
        ksort($found, SORT_STRING);
        return array_values($found);
    }

    /** @param list<string> $sourceKeys @return list<array<string,mixed>> */
    public function imageManifestRowsForSourceKeys(int $runId, int $shopId, string $source, array $sourceKeys, int $limit = 500): array
    {
        $keys = array_values(array_unique(array_filter(
            array_map(static fn(mixed $key): string => trim((string) $key), $sourceKeys),
            static fn(string $key): bool => $key !== ''
        )));
        if ($keys === []) { return []; }
        $limit = max(1, min(2000, min($limit, count($keys))));
        $quoted = implode(',', array_map(static fn(string $key): string => "'" . pSQL($key) . "'", $keys));
        $where = sprintf(
            " FROM `%s%s` s INNER JOIN `%s%s` m ON m.id_shop=%d AND m.source='%s' AND m.source_key=s.source_key " .
            "WHERE s.id_run=%d AND s.source_key IN (%s)",
            _DB_PREFIX_, self::TABLE, _DB_PREFIX_, self::MAPPING_TABLE, $shopId, pSQL($source), $runId, $quoted
        );
        $window = $this->payloadWindow('SELECT s.source_key,OCTET_LENGTH(s.payload) payload_bytes' . $where . ' ORDER BY s.source_key LIMIT ' . $limit, 'source_key');
        if ($window === null) { return []; }
        return \Db::getInstance()->executeS(
            'SELECT s.source_key,s.payload,m.id_product' . $where . " AND s.source_key<='" . pSQL((string) $window['last']) . "' ORDER BY s.source_key LIMIT " . (int) $window['count'],
            true,
            false
        ) ?: [];
    }

    private function valueRow(int $runId, ProductData $product): string
    {
        return sprintf(
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

    /** @param list<string> $values */
    private function executeValueChunk(string $prefix, string $suffix, array $values): void
    {
        $sql = $prefix . implode(',', $values) . $suffix;
        if (strlen($sql) > self::MAX_WRITE_SQL_BYTES) {
            throw new \RuntimeException('Matterhorn snapshot batch SQL exceeded configured write budget');
        }
        if (!\Db::getInstance()->execute($sql)) {
            throw new \RuntimeException('Matterhorn snapshot batch upsert failed');
        }
    }

    private function payloadWindow(string $sql,string $cursorField):?array
    {
        $rows=\Db::getInstance()->executeS($sql, true, false)?:[]; if($rows===[]){return null;} $bytes=0;$count=0;$last=null;
        foreach($rows as $row){$rowBytes=max(0,(int)($row['payload_bytes']??0));if($count>0&&$bytes+$rowBytes>self::MAX_FETCH_PAYLOAD_BYTES){break;}$bytes+=$rowBytes;$count++;$last=$row[$cursorField];if($bytes>=self::MAX_FETCH_PAYLOAD_BYTES){break;}}
        return $count>0&&$last!==null?['last'=>$last,'count'=>$count]:null;
    }
}
