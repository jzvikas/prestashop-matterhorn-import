<?php
namespace Lp\MatterhornImport\Repository;

final class FeatureStateRepository
{
    /** @return array<int,array<string,mixed>> */
    public function allForProduct(int $shopId, string $source, string $sourceKey, int $productId): array
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT * FROM `%sli_matterhornim_99dfbf_feature_state` WHERE id_shop=%d AND source='%s' AND source_key='%s' AND id_product=%d",
            _DB_PREFIX_, $shopId, pSQL($source), pSQL($sourceKey), $productId
        ), true, false) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $featureId = (int) ($row['id_feature'] ?? 0);
            if ($featureId > 0) {
                $out[$featureId] = $row;
            }
        }
        return $out;
    }

    public function save(int $shopId, string $source, string $sourceKey, int $productId, int $featureId, int $valueId, int $runId): void
    {
        $sql = sprintf(
            "INSERT INTO `%sli_matterhornim_99dfbf_feature_state` " .
            "(`id_shop`,`source`,`source_key`,`id_product`,`id_feature`,`id_feature_value`,`last_seen_run_id`,`updated_at`) " .
            "VALUES (%d,'%s','%s',%d,%d,%d,%d,'%s') ON DUPLICATE KEY UPDATE " .
            "`id_product`=VALUES(`id_product`),`id_feature_value`=VALUES(`id_feature_value`)," .
            "`last_seen_run_id`=VALUES(`last_seen_run_id`),`updated_at`=VALUES(`updated_at`)",
            _DB_PREFIX_, $shopId, pSQL($source), pSQL($sourceKey), $productId, $featureId, $valueId, $runId, date('Y-m-d H:i:s')
        );
        if (!\Db::getInstance()->execute($sql)) {
            throw new \RuntimeException('Feature ownership state save failed');
        }
    }

    public function delete(int $shopId, string $source, string $sourceKey, int $featureId): void
    {
        if (!\Db::getInstance()->delete(
            'li_matterhornim_99dfbf_feature_state',
            sprintf("id_shop=%d AND source='%s' AND source_key='%s' AND id_feature=%d", $shopId, pSQL($source), pSQL($sourceKey), $featureId)
        )) {
            throw new \RuntimeException('Feature ownership state delete failed');
        }
    }
}
