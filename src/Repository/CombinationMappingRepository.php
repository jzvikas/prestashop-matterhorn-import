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
        )) ?: [];
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['semantic_key']] = $row;
        }
        return $indexed;
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
