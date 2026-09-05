<?php
namespace Lp\MatterhornImport\Repository;

final class FeatureMappingRepository
{
    /** @var array<string,array{id_feature:int,id_feature_value:int}|null> */
    private array $pairCache = [];

    /** @return array{id_feature:int,id_feature_value:int}|null */
    public function resolvePair(int $shopId, string $source, string $featureKey, string $valueKey): ?array
    {
        if ($shopId <= 0) {
            throw new \InvalidArgumentException('Feature mapping requires a concrete shop');
        }
        $cacheKey = $shopId . "\0" . $source . "\0" . $featureKey . "\0" . $valueKey;
        if (array_key_exists($cacheKey, $this->pairCache)) {
            return $this->pairCache[$cacheKey];
        }

        $row = \Db::getInstance()->getRow(sprintf(
            "SELECT fm.id_feature,fvm.id_feature_value FROM `%sli_matterhornim_99dfbf_feature_mapping` fm " .
            "INNER JOIN `%sli_matterhornim_99dfbf_feature_value_mapping` fvm " .
            "ON fvm.id_shop=fm.id_shop AND fvm.source=fm.source AND fvm.supplier_feature_key=fm.supplier_feature_key " .
            "INNER JOIN `%sfeature_shop` fs ON fs.id_feature=fm.id_feature AND fs.id_shop=%d " .
            "INNER JOIN `%sfeature_value` fv ON fv.id_feature_value=fvm.id_feature_value AND fv.id_feature=fm.id_feature " .
            "WHERE fm.id_shop=%d AND fm.source='%s' AND fm.supplier_feature_key='%s' AND fvm.supplier_value_key='%s'",
            _DB_PREFIX_, _DB_PREFIX_, _DB_PREFIX_, $shopId, _DB_PREFIX_, $shopId,
            pSQL($source), pSQL($featureKey), pSQL($valueKey)
        ));
        if (!$row || (int) $row['id_feature'] <= 0 || (int) $row['id_feature_value'] <= 0) {
            return $this->pairCache[$cacheKey] = null;
        }
        return $this->pairCache[$cacheKey] = [
            'id_feature' => (int) $row['id_feature'],
            'id_feature_value' => (int) $row['id_feature_value'],
        ];
    }

    public function saveResolved(
        int $shopId,
        string $source,
        string $featureKey,
        string $featureName,
        string $valueKey,
        string $valueName,
        int $featureId,
        int $featureValueId
    ): void {
        if ($shopId <= 0 || $featureId <= 0 || $featureValueId <= 0) {
            throw new \InvalidArgumentException('Feature mapping save requires valid shop/feature/value IDs');
        }
        $db = \Db::getInstance();
        $now = date('Y-m-d H:i:s');
        if (!$db->execute(sprintf(
            "INSERT INTO `%sli_matterhornim_99dfbf_feature_mapping` " .
            "(`id_shop`,`source`,`supplier_feature_key`,`supplier_name`,`id_feature`,`updated_at`) " .
            "VALUES (%d,'%s','%s','%s',%d,'%s') ON DUPLICATE KEY UPDATE " .
            "`supplier_name`=VALUES(`supplier_name`),`id_feature`=VALUES(`id_feature`),`updated_at`=VALUES(`updated_at`)",
            _DB_PREFIX_, $shopId, pSQL($source), pSQL($featureKey), pSQL($featureName), $featureId, $now
        ))) {
            throw new \RuntimeException('Feature mapping save failed');
        }
        if (!$db->execute(sprintf(
            "INSERT INTO `%sli_matterhornim_99dfbf_feature_value_mapping` " .
            "(`id_shop`,`source`,`supplier_feature_key`,`supplier_value_key`,`supplier_value`,`id_feature`,`id_feature_value`,`updated_at`) " .
            "VALUES (%d,'%s','%s','%s','%s',%d,%d,'%s') ON DUPLICATE KEY UPDATE " .
            "`supplier_value`=VALUES(`supplier_value`),`id_feature`=VALUES(`id_feature`)," .
            "`id_feature_value`=VALUES(`id_feature_value`),`updated_at`=VALUES(`updated_at`)",
            _DB_PREFIX_, $shopId, pSQL($source), pSQL($featureKey), pSQL($valueKey), pSQL($valueName),
            $featureId, $featureValueId, $now
        ))) {
            throw new \RuntimeException('Feature value mapping save failed');
        }
        unset($this->pairCache[$cacheKey = $shopId . "\0" . $source . "\0" . $featureKey . "\0" . $valueKey]);
    }
}
