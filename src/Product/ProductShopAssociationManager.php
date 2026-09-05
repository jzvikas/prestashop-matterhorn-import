<?php
namespace Lp\MatterhornImport\Product;

final class ProductShopAssociationManager
{
    public function ensure(int $productId, int $shopId): void
    {
        if ($productId <= 0 || $shopId <= 0) { throw new \InvalidArgumentException('Product and shop IDs must be positive'); }
        $db = \Db::getInstance();
        if (!$this->rowExists('SELECT id_product FROM `' . _DB_PREFIX_ . 'product` WHERE id_product=' . $productId . ' LIMIT 1')) {
            throw new \RuntimeException('Product not found: ' . $productId);
        }
        if (!$this->hasAssociation($productId, $shopId)) {
            $sourceRows = $db->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product=' . $productId . ' ORDER BY id_shop ASC LIMIT 1', true, false) ?: [];
            $sourceShopId = isset($sourceRows[0]['id_shop']) ? (int) $sourceRows[0]['id_shop'] : 0;
            if ($sourceShopId <= 0) { throw new \RuntimeException('Product #' . $productId . ' has no source shop association that can be copied to shop #' . $shopId); }
            $this->copyShopRows('product_shop', $productId, $sourceShopId, $shopId);
            $this->copyShopRows('product_lang', $productId, $sourceShopId, $shopId);
        } elseif (!$this->rowExists('SELECT id_product FROM `' . _DB_PREFIX_ . 'product_lang` WHERE id_product=' . $productId . ' AND id_shop=' . $shopId . ' LIMIT 1')) {
            $sourceRows = $db->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'product_lang` WHERE id_product=' . $productId . ' AND id_shop<>' . $shopId . ' ORDER BY id_shop ASC LIMIT 1', true, false) ?: [];
            $sourceShopId = isset($sourceRows[0]['id_shop']) ? (int) $sourceRows[0]['id_shop'] : 0;
            if ($sourceShopId <= 0) { throw new \RuntimeException('Product #' . $productId . ' has no language association that can be copied to shop #' . $shopId); }
            $this->copyShopRows('product_lang', $productId, $sourceShopId, $shopId);
        }
        if (!$this->hasAssociation($productId, $shopId)) { throw new \RuntimeException('Could not restore product #' . $productId . ' association to shop #' . $shopId); }
        if (!$this->rowExists('SELECT id_product FROM `' . _DB_PREFIX_ . 'product_lang` WHERE id_product=' . $productId . ' AND id_shop=' . $shopId . ' LIMIT 1')) {
            throw new \RuntimeException('Could not restore product #' . $productId . ' language association to shop #' . $shopId);
        }
    }

    public function hasAssociation(int $productId, int $shopId): bool
    {
        return $this->rowExists('SELECT id_product FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product=' . $productId . ' AND id_shop=' . $shopId . ' LIMIT 1');
    }

    public function assertExclusiveGlobalOwnership(int $productId, int $shopId): void
    {
        if ($productId <= 0 || $shopId <= 0) { throw new \InvalidArgumentException('Product and shop IDs must be positive'); }
        $rows = \Db::getInstance()->executeS(
            'SELECT id_shop FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product=' . $productId . ' ORDER BY id_shop',
            true,
            false
        ) ?: [];
        $shops = array_values(array_unique(array_map(static fn(array $row): int => (int) ($row['id_shop'] ?? 0), $rows)));
        $shops = array_values(array_filter($shops, static fn(int $id): bool => $id > 0));
        if ($shops !== [$shopId]) {
            throw new \RuntimeException(
                'Matterhorn refuses to mutate global product fields for product #' . $productId .
                ': expected exclusive shop #' . $shopId . ', associations=' . ($shops === [] ? 'none' : implode(',', $shops))
            );
        }
    }

    private function rowExists(string $sql): bool { $rows = \Db::getInstance()->executeS($sql, true, false) ?: []; return isset($rows[0]); }

    private function copyShopRows(string $table, int $productId, int $sourceShopId, int $targetShopId): void
    {
        if (!in_array($table, ['product_shop', 'product_lang'], true)) { throw new \InvalidArgumentException('Unsupported product association table: ' . $table); }
        $db = \Db::getInstance();
        $fullTable = _DB_PREFIX_ . $table;
        $columns = $db->executeS('SHOW COLUMNS FROM `' . $fullTable . '`', true, false) ?: [];
        $names = [];
        foreach ($columns as $column) {
            $name = (string) ($column['Field'] ?? '');
            if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/D', $name)) { throw new \RuntimeException('Unsafe or empty column in ' . $fullTable); }
            $names[] = $name;
        }
        if (!in_array('id_product', $names, true) || !in_array('id_shop', $names, true)) { throw new \RuntimeException('Unexpected PrestaShop association schema: ' . $fullTable); }
        $insertColumns = implode(',', array_map(static fn(string $name): string => '`' . $name . '`', $names));
        $selectColumns = implode(',', array_map(static fn(string $name): string => $name === 'id_shop' ? (string) $targetShopId : '`' . $name . '`', $names));
        $sql = 'INSERT IGNORE INTO `' . $fullTable . '` (' . $insertColumns . ') SELECT ' . $selectColumns . ' FROM `' . $fullTable . '` WHERE id_product=' . $productId . ' AND id_shop=' . $sourceShopId;
        if (!$db->execute($sql)) { throw new \RuntimeException('Could not copy ' . $table . ' association for product #' . $productId . ' from shop #' . $sourceShopId . ' to shop #' . $targetShopId); }
    }
}
