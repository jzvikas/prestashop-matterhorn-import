<?php
namespace Lp\MatterhornImport\Repository;

final class FeatureMappingRepository
{
    private const LOCK_TIMEOUT_SECONDS = 10;

    /** @var array<string,array{id_feature:int,id_feature_value:int}|null> */
    private array $pairCache = [];
    /** @var array<string,array{name:string,value:string}> */
    private array $semanticIdentityCache = [];

    /**
     * Fail closed when a deterministic supplier feature/value key has already been
     * persisted for a different display identity. This protects slug-derived keys
     * such as `red-blue`, which can otherwise collide for `red/blue` and `red blue`.
     */
    public function assertSemanticIdentity(
        int $shopId,
        string $source,
        string $featureKey,
        string $featureName,
        string $valueKey,
        string $valueName
    ): void {
        if ($shopId <= 0) {
            throw new \InvalidArgumentException('Feature semantic identity requires a concrete shop');
        }
        $featureKey = trim($featureKey);
        $valueKey = trim($valueKey);
        $featureName = trim($featureName);
        $valueName = trim($valueName);
        if ($source === '' || $featureKey === '' || $valueKey === '' || $featureName === '' || $valueName === '') {
            throw new \InvalidArgumentException('Feature semantic identity requires source, keys and labels');
        }

        $cacheKey = $this->cacheKey($shopId, $source, $featureKey, $valueKey);
        if (isset($this->semanticIdentityCache[$cacheKey])) {
            $cached = $this->semanticIdentityCache[$cacheKey];
            $this->assertIdentityMatches($featureKey, $valueKey, $featureName, $valueName, $cached['name'], $cached['value']);
            return;
        }

        $this->assertSemanticIdentityFresh($shopId, $source, $featureKey, $featureName, $valueKey, $valueName);
    }

    /** @return array{id_feature:int,id_feature_value:int}|null */
    public function resolvePair(int $shopId, string $source, string $featureKey, string $valueKey): ?array
    {
        if ($shopId <= 0) {
            throw new \InvalidArgumentException('Feature mapping requires a concrete shop');
        }
        $cacheKey = $this->cacheKey($shopId, $source, $featureKey, $valueKey);
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
        ), false);
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
        $lock = $this->acquireSemanticLock($db, $shopId, $source, $featureKey, $valueKey);
        try {
            // Re-read under the semantic lock. A process-local preflight cache cannot
            // protect two concurrent workers that started before either mapping existed.
            $this->assertSemanticIdentityFresh(
                $shopId,
                $source,
                $featureKey,
                $featureName,
                $valueKey,
                $valueName
            );

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
            $cacheKey = $this->cacheKey($shopId, $source, $featureKey, $valueKey);
            $this->pairCache[$cacheKey] = [
                'id_feature' => $featureId,
                'id_feature_value' => $featureValueId,
            ];
            $this->semanticIdentityCache[$cacheKey] = ['name' => trim($featureName), 'value' => trim($valueName)];
        } finally {
            $this->releaseSemanticLock($db, $lock);
        }
    }

    private function assertSemanticIdentityFresh(
        int $shopId,
        string $source,
        string $featureKey,
        string $featureName,
        string $valueKey,
        string $valueName
    ): void {
        $db = \Db::getInstance();
        $row = $db->getRow(sprintf(
            "SELECT " .
            "(SELECT fm.supplier_name FROM `%sli_matterhornim_99dfbf_feature_mapping` fm " .
            "WHERE fm.id_shop=%d AND fm.source='%s' AND fm.supplier_feature_key='%s') AS supplier_name," .
            "(SELECT fvm.supplier_value FROM `%sli_matterhornim_99dfbf_feature_value_mapping` fvm " .
            "WHERE fvm.id_shop=%d AND fvm.source='%s' AND fvm.supplier_feature_key='%s' AND fvm.supplier_value_key='%s') AS supplier_value",
            _DB_PREFIX_, $shopId, pSQL($source), pSQL($featureKey),
            _DB_PREFIX_, $shopId, pSQL($source), pSQL($featureKey), pSQL($valueKey)
        ), false);

        $storedName = is_array($row) && $row['supplier_name'] !== null ? trim((string) $row['supplier_name']) : '';
        $storedValue = is_array($row) && $row['supplier_value'] !== null ? trim((string) $row['supplier_value']) : '';
        $this->assertIdentityMatches($featureKey, $valueKey, $featureName, $valueName, $storedName, $storedValue);

        $cacheKey = $this->cacheKey($shopId, $source, $featureKey, $valueKey);
        $this->semanticIdentityCache[$cacheKey] = [
            'name' => $storedName !== '' ? $storedName : trim($featureName),
            'value' => $storedValue !== '' ? $storedValue : trim($valueName),
        ];
    }

    private function assertIdentityMatches(
        string $featureKey,
        string $valueKey,
        string $featureName,
        string $valueName,
        string $storedName,
        string $storedValue
    ): void {
        if ($storedName !== '' && !hash_equals($storedName, trim($featureName))) {
            throw new \RuntimeException(
                'Feature semantic identity collision for ' . $featureKey . ': stored name "' .
                $storedName . '" differs from supplier name "' . trim($featureName) . '"'
            );
        }
        if ($storedValue !== '' && !hash_equals($storedValue, trim($valueName))) {
            throw new \RuntimeException(
                'Feature semantic identity collision for ' . $featureKey . '/' . $valueKey . ': stored value "' .
                $storedValue . '" differs from supplier value "' . trim($valueName) . '"'
            );
        }
    }

    private function acquireSemanticLock(\Db $db, int $shopId, string $source, string $featureKey, string $valueKey): string
    {
        $scope = $shopId . "\0" . $source . "\0" . $featureKey . "\0" . $valueKey;
        $lock = 'lpimp:featmap:' . substr(hash('sha256', $scope), 0, 40);
        if ((int) $db->getValue("SELECT GET_LOCK('" . pSQL($lock) . "'," . self::LOCK_TIMEOUT_SECONDS . ')', false) !== 1) {
            throw new \RuntimeException('Could not acquire feature semantic mapping lock');
        }
        return $lock;
    }

    private function releaseSemanticLock(\Db $db, string $lock): void
    {
        try { $db->getValue("SELECT RELEASE_LOCK('" . pSQL($lock) . "')", false); } catch (\Throwable) {}
    }

    private function cacheKey(int $shopId, string $source, string $featureKey, string $valueKey): string
    {
        return $shopId . "\0" . $source . "\0" . $featureKey . "\0" . $valueKey;
    }
}
