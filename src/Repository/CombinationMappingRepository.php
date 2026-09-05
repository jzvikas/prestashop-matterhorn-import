<?php
namespace Lp\MatterhornImport\Repository;

final class CombinationMappingRepository
{
    /** @return array<string,array<string,mixed>> */
    public function allForProduct(int $shopId, string $source, string $sourceKey, int $productId): array
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT * FROM `%sli_matterhornim_99dfbf_combination_mapping` WHERE id_shop=%d AND source='%s' AND source_key='%s' AND id_product=%d",
            _DB_PREFIX_, $shopId, pSQL($source), pSQL($sourceKey), $productId
        ), true, false) ?: [];
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['semantic_key']] = $row;
        }
        return $indexed;
    }

    /** @return array{source:string,source_key:string,semantic_key:string,id_product:int,id_product_attribute:int}|null */
    public function ownerForAttribute(int $shopId, int $productAttributeId): ?array
    {
        if ($shopId <= 0 || $productAttributeId <= 0) {
            throw new \InvalidArgumentException('Combination owner lookup requires shop and product attribute');
        }
        $row = \Db::getInstance()->getRow(sprintf(
            "SELECT source,source_key,semantic_key,id_product,id_product_attribute FROM `%sli_matterhornim_99dfbf_combination_mapping` " .
            "WHERE id_shop=%d AND id_product_attribute=%d LIMIT 1",
            _DB_PREFIX_, $shopId, $productAttributeId
        ), false);
        if (!is_array($row) || $row === []) { return null; }
        return [
            'source' => (string) ($row['source'] ?? ''),
            'source_key' => (string) ($row['source_key'] ?? ''),
            'semantic_key' => (string) ($row['semantic_key'] ?? ''),
            'id_product' => (int) ($row['id_product'] ?? 0),
            'id_product_attribute' => (int) ($row['id_product_attribute'] ?? 0),
        ];
    }

    public function save(
        int $shopId,
        string $source,
        string $sourceKey,
        string $semanticKey,
        int $productId,
        int $productAttributeId,
        string $structureHash,
        string $stockHash,
        int $runId
    ): void {
        $sql = sprintf(
            "INSERT INTO `%sli_matterhornim_99dfbf_combination_mapping` " .
            "(`id_shop`,`source`,`source_key`,`semantic_key`,`id_product`,`id_product_attribute`,`structure_hash`,`stock_hash`,`last_seen_run_id`,`updated_at`) " .
            "VALUES (%d,'%s','%s','%s',%d,%d,'%s','%s',%d,'%s') ON DUPLICATE KEY UPDATE " .
            "`id_product`=VALUES(`id_product`),`id_product_attribute`=VALUES(`id_product_attribute`)," .
            "`structure_hash`=VALUES(`structure_hash`),`stock_hash`=VALUES(`stock_hash`)," .
            "`last_seen_run_id`=VALUES(`last_seen_run_id`),`updated_at`=VALUES(`updated_at`)",
            _DB_PREFIX_, $shopId, pSQL($source), pSQL($sourceKey), pSQL($semanticKey),
            $productId, $productAttributeId, pSQL($structureHash), pSQL($stockHash), $runId, date('Y-m-d H:i:s')
        );
        if (!\Db::getInstance()->execute($sql)) {
            throw new \RuntimeException('Combination mapping save failed');
        }
    }

    public function deleteExact(
        int $shopId,
        string $source,
        string $sourceKey,
        string $semanticKey,
        int $productId,
        int $productAttributeId
    ): void {
        if ($shopId <= 0 || $source === '' || $sourceKey === '' || $semanticKey === '' || $productId <= 0 || $productAttributeId <= 0) {
            throw new \InvalidArgumentException('Exact combination mapping delete requires complete owner identity');
        }
        $db = \Db::getInstance();
        if (!$db->delete(
            'li_matterhornim_99dfbf_combination_mapping',
            sprintf(
                "id_shop=%d AND source='%s' AND source_key='%s' AND semantic_key='%s' AND id_product=%d AND id_product_attribute=%d",
                $shopId, pSQL($source), pSQL($sourceKey), pSQL($semanticKey), $productId, $productAttributeId
            )
        )) {
            throw new \RuntimeException('Combination mapping exact delete failed');
        }
        if ((int) $db->Affected_Rows() !== 1) {
            throw new \RuntimeException('Combination mapping ownership changed before exact delete');
        }
    }

    public function deleteSemantic(int $shopId, string $source, string $sourceKey, string $semanticKey): void
    {
        if (!\Db::getInstance()->delete(
            'li_matterhornim_99dfbf_combination_mapping',
            sprintf("id_shop=%d AND source='%s' AND source_key='%s' AND semantic_key='%s'", $shopId, pSQL($source), pSQL($sourceKey), pSQL($semanticKey))
        )) {
            throw new \RuntimeException('Combination mapping delete failed');
        }
    }

    public function deleteByAttribute(int $shopId, int $productAttributeId): void
    {
        if (!\Db::getInstance()->delete(
            'li_matterhornim_99dfbf_combination_mapping',
            'id_shop=' . $shopId . ' AND id_product_attribute=' . $productAttributeId
        )) {
            throw new \RuntimeException('Combination mapping attribute cleanup failed');
        }
    }
}
