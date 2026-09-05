<?php
namespace Lp\MatterhornImport\Feature;

use Lp\MatterhornImport\Util\ShopContextManager;

final class FeatureResolver
{
    private const LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(private ShopContextManager $shopContext) {}

    /** @return array{id_feature:int,id_feature_value:int} */
    public function resolveOrCreate(int $shopId, string $name, string $value): array
    {
        $name = trim($name);
        $value = trim($value);
        if ($shopId <= 0 || $name === '' || $value === '') {
            throw new \InvalidArgumentException('Feature auto-create requires shop, feature name and value');
        }
        if (strlen($name) > 128 || strlen($value) > 255) {
            throw new \InvalidArgumentException('Feature name/value exceeds supported length');
        }

        $this->shopContext->activate($shopId);
        $langId = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $shopId);
        if ($langId <= 0) {
            throw new \RuntimeException('Target shop has no valid default language for feature resolution');
        }

        $db = \Db::getInstance();
        $featureLock = $this->acquireLock(
            $db,
            'feature:' . $shopId . ':' . mb_strtolower($name, 'UTF-8')
        );
        try {
            $featureId = $this->findFeature($shopId, $langId, $name);
            if ($featureId <= 0) {
                $featureId = $this->createFeature($shopId, $name);
            }
        } finally {
            $this->releaseLock($db, $featureLock);
        }

        $valueLock = $this->acquireLock(
            $db,
            'value:' . $featureId . ':' . mb_strtolower($value, 'UTF-8')
        );
        try {
            $valueId = $this->findValue($featureId, $langId, $value);
            if ($valueId <= 0) {
                $valueId = $this->createValue($featureId, $shopId, $value);
            }
        } finally {
            $this->releaseLock($db, $valueLock);
        }

        return ['id_feature' => $featureId, 'id_feature_value' => $valueId];
    }

    private function findFeature(int $shopId, int $langId, string $name): int
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT f.id_feature FROM `%sfeature` f " .
            "INNER JOIN `%sfeature_shop` fs ON fs.id_feature=f.id_feature AND fs.id_shop=%d " .
            "INNER JOIN `%sfeature_lang` fl ON fl.id_feature=f.id_feature AND fl.id_lang=%d " .
            "WHERE BINARY fl.name=BINARY '%s' ORDER BY f.id_feature LIMIT 2",
            _DB_PREFIX_, _DB_PREFIX_, $shopId, _DB_PREFIX_, $langId, pSQL($name)
        ), true, false) ?: [];
        if (count($rows) > 1) {
            throw new \RuntimeException('Ambiguous exact feature name in target shop: ' . $name);
        }
        return (int) ($rows[0]['id_feature'] ?? 0);
    }

    private function findValue(int $featureId, int $langId, string $value): int
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT fv.id_feature_value FROM `%sfeature_value` fv " .
            "INNER JOIN `%sfeature_value_lang` fvl ON fvl.id_feature_value=fv.id_feature_value AND fvl.id_lang=%d " .
            "WHERE fv.id_feature=%d AND fv.custom=0 AND BINARY fvl.value=BINARY '%s' ORDER BY fv.id_feature_value LIMIT 2",
            _DB_PREFIX_, _DB_PREFIX_, $langId, $featureId, pSQL($value)
        ), true, false) ?: [];
        if (count($rows) > 1) {
            throw new \RuntimeException('Ambiguous exact feature value for feature ' . $featureId . ': ' . $value);
        }
        return (int) ($rows[0]['id_feature_value'] ?? 0);
    }

    private function createFeature(int $shopId, string $name): int
    {
        $feature = new \Feature();
        foreach (\Language::getLanguages(false, $shopId) as $lang) {
            $feature->name[(int) $lang['id_lang']] = $name;
        }
        if (!$feature->add()) {
            throw new \RuntimeException('Could not create feature: ' . $name);
        }
        $featureId = (int) $feature->id;
        if ($featureId <= 0 || !\Db::getInstance()->execute(sprintf(
            "INSERT IGNORE INTO `%sfeature_shop` (`id_feature`,`id_shop`) VALUES (%d,%d)",
            _DB_PREFIX_, $featureId, $shopId
        ))) {
            throw new \RuntimeException('Could not associate created feature to target shop: ' . $name);
        }
        return $featureId;
    }

    private function createValue(int $featureId, int $shopId, string $value): int
    {
        $featureValue = new \FeatureValue();
        $featureValue->id_feature = $featureId;
        $featureValue->custom = false;
        foreach (\Language::getLanguages(false, $shopId) as $lang) {
            $featureValue->value[(int) $lang['id_lang']] = $value;
        }
        if (!$featureValue->add()) {
            throw new \RuntimeException('Could not create feature value for feature ' . $featureId);
        }
        return (int) $featureValue->id;
    }

    private function acquireLock(\Db $db, string $scope): string
    {
        $name = 'lpimp:feat:' . substr(hash('sha256', $scope), 0, 40);
        if ((int) $db->getValue(
            "SELECT GET_LOCK('" . pSQL($name) . "'," . self::LOCK_TIMEOUT_SECONDS . ')',
            false
        ) !== 1) {
            throw new \RuntimeException('Could not acquire feature resolver lock');
        }
        return $name;
    }

    private function releaseLock(\Db $db, string $name): void
    {
        try {
            $db->getValue("SELECT RELEASE_LOCK('" . pSQL($name) . "')", false);
        } catch (\Throwable) {
        }
    }
}