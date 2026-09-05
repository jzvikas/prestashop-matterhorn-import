<?php
namespace Lp\MatterhornImport\Util;

final class ShopContextManager
{
    private ?int $cachedShopId = null;
    private ?\Shop $shop = null;
    private ?\Language $language = null;
    private ?\Currency $currency = null;
    private ?\Country $country = null;

    public function activate(int $shopId): void
    {
        if ($shopId <= 0) {
            throw new \InvalidArgumentException('Shop ID must be positive');
        }

        \Shop::setContext(\Shop::CONTEXT_SHOP, $shopId);
        if ($this->cachedShopId !== $shopId || $this->shop === null) {
            $this->load($shopId);
        }
        if ($this->shop === null || $this->language === null || $this->currency === null || $this->country === null) {
            throw new \RuntimeException('Target shop runtime context is incomplete: ' . $shopId);
        }

        \Shop::setContext(\Shop::CONTEXT_SHOP, $shopId);
        $context = \Context::getContext();
        $context->shop = $this->shop;
        $context->language = $this->language;
        $context->currency = $this->currency;
        $context->country = $this->country;
    }

    private function load(int $shopId): void
    {
        if ((int) \Shop::getContextShopID() !== $shopId) {
            throw new \RuntimeException('PrestaShop shop context was not initialized before runtime loading: ' . $shopId);
        }
        $shop = new \Shop($shopId);
        if (!\Validate::isLoadedObject($shop)) {
            throw new \RuntimeException('PrestaShop shop not found: ' . $shopId);
        }
        $shopGroupId = (int) $shop->id_shop_group;
        if ($shopGroupId <= 0) {
            throw new \RuntimeException('PrestaShop shop group not found for shop: ' . $shopId);
        }

        $this->shop = $shop;
        $context = \Context::getContext();
        $context->shop = $shop;
        $this->language = $this->loadShopObject(
            \Language::class,
            $this->configurationId('PS_LANG_DEFAULT', $shopGroupId, $shopId),
            'default language', 'lang', 'lang_shop', 'id_lang', $shopId
        );
        $context->language = $this->language;
        $this->currency = $this->loadShopObject(
            \Currency::class,
            $this->configurationId('PS_CURRENCY_DEFAULT', $shopGroupId, $shopId),
            'default currency', 'currency', 'currency_shop', 'id_currency', $shopId
        );
        $context->currency = $this->currency;
        $this->country = $this->loadShopObject(
            \Country::class,
            $this->configurationId('PS_COUNTRY_DEFAULT', $shopGroupId, $shopId),
            'default country', 'country', 'country_shop', 'id_country', $shopId
        );
        $context->country = $this->country;
        $this->cachedShopId = $shopId;
    }

    private function configurationId(string $key, int $shopGroupId, int $shopId): int
    {
        $id = (int) \Configuration::get($key, null, $shopGroupId, $shopId);
        if ($id > 0) { return $id; }
        $id = (int) \Configuration::get($key);
        if ($id > 0) { return $id; }

        $db = \Db::getInstance();
        $value = $db->getValue(sprintf(
            "SELECT `value` FROM `%sconfiguration` WHERE `name`='%s' AND id_shop IS NULL AND id_shop_group IS NULL ORDER BY id_configuration DESC",
            _DB_PREFIX_, pSQL($key)
        ), false);
        if ($value === false && $db->getMsgError() !== '') {
            throw new \RuntimeException('Could not resolve inherited shop configuration ' . $key . ': ' . $db->getMsgError());
        }
        return $value === false ? 0 : (int) $value;
    }

    /**
     * @template T of \ObjectModel
     * @param class-string<T> $class
     * @return T
     */
    private function loadShopObject(
        string $class,
        int $configuredId,
        string $label,
        string $objectTable,
        string $associationTable,
        string $idColumn,
        int $shopId
    ): \ObjectModel {
        if ($configuredId > 0) {
            $configured = new $class($configuredId);
            if (\Validate::isLoadedObject($configured)) { return $configured; }
        }

        $entityIds = \Shop::getEntityIds($objectTable, $shopId, true, true);
        if (is_array($entityIds)) {
            usort($entityIds, static fn(array $left, array $right): int => (int) ($left[$idColumn] ?? 0) <=> (int) ($right[$idColumn] ?? 0));
            foreach ($entityIds as $entityRow) {
                $entityId = (int) ($entityRow[$idColumn] ?? 0);
                if ($entityId <= 0) { continue; }
                $entity = new $class($entityId);
                if (!\Validate::isLoadedObject($entity)) { continue; }
                if (property_exists($entity, 'active') && !(bool) $entity->active) { continue; }
                if (property_exists($entity, 'deleted') && (bool) $entity->deleted) { continue; }
                return $entity;
            }
        }

        $db = \Db::getInstance();
        $associationCount = $db->getValue(sprintf(
            "SELECT COUNT(*) FROM `%s%s` WHERE id_shop=%d",
            _DB_PREFIX_, bqSQL($associationTable), $shopId
        ), false);
        if ($associationCount === false) {
            throw new \RuntimeException('Could not inspect shop ' . $label . ' associations: ' . $db->getMsgError());
        }

        if ((int) $associationCount > 0) {
            $fallbackSql = sprintf(
                "SELECT o.`%s` FROM `%s%s` o INNER JOIN `%s%s` s ON s.`%s`=o.`%s` AND s.id_shop=%d WHERE o.active=1 ORDER BY o.`%s` ASC",
                bqSQL($idColumn), _DB_PREFIX_, bqSQL($objectTable), _DB_PREFIX_, bqSQL($associationTable),
                bqSQL($idColumn), bqSQL($idColumn), $shopId, bqSQL($idColumn)
            );
        } else {
            $fallbackSql = sprintf(
                "SELECT `%s` FROM `%s%s` WHERE active=1 ORDER BY `%s` ASC",
                bqSQL($idColumn), _DB_PREFIX_, bqSQL($objectTable), bqSQL($idColumn)
            );
        }

        $fallbackId = $db->getValue($fallbackSql, false);
        if ($fallbackId === false) {
            $detail = $db->getMsgError();
            throw new \RuntimeException('Missing shop ' . $label . ' configuration/association' . ($detail !== '' ? ': ' . $detail : ''));
        }
        if ((int) $fallbackId <= 0) {
            throw new \RuntimeException('Missing active shop ' . $label . ' object');
        }
        $object = new $class((int) $fallbackId);
        if (!\Validate::isLoadedObject($object)) {
            throw new \RuntimeException('Invalid shop ' . $label . ' fallback object #' . (int) $fallbackId);
        }
        return $object;
    }
}
