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
            $sourceRows = $db->executeS(
                'SELECT id_shop FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product=' . $productId . ' ORDER BY id_shop ASC LIMIT 1',
                true,
                false
            ) ?: [];
            $sourceShopId = isset($sourceRows[0]['id_shop']) ? (int) $sourceRows[0]['id_shop'] : 0;
            if ($sourceShopId <= 0) {
                throw new \RuntimeException('Product #' . $productId . ' has no source shop association that can be copied to shop #' . $shopId);
            }
            $this->copyShopRows('product_shop', $productId, $sourceShopId, $shopId);
            $this->copyShopRows('product_lang', $productId, $sourceShopId, $shopId);
        }

        // A valid product_shop row does not prove all active target-shop product_lang rows exist.
        // Repair each missing language independently so stock/category-only updates also recover
        // partially corrupted multishop associations.
        $this->ensureLanguageRows($productId, $shopId);

        if (!$this->hasAssociation($productId, $shopId)) {
            throw new \RuntimeException('Could not restore product #' . $productId . ' association to shop #' . $shopId);
        }
        $missing = $this->missingLanguageIds($productId, $shopId);
        if ($missing !== []) {
            throw new \RuntimeException(
                'Could not restore product #' . $productId . ' language associations to shop #' . $shopId .
                ': missing language ids ' . implode(',', $missing)
            );
        }
    }

    public function hasAssociation(int $productId, int $shopId): bool
    {
        return $this->rowExists(
            'SELECT id_product FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product=' . $productId .
            ' AND id_shop=' . $shopId . ' LIMIT 1'
        );
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

    /**
     * PrestaShop keeps duplicated copies of selected shop fields in ps_product and ps_product_shop.
     * Product::update() may overwrite the ps_product copy even when a non-default shop is targeted.
     * Restore only the small allow-list of fields Matterhorn changes on shared products.
     *
     * @param list<string> $fields
     */
    public function restoreDefaultShopShadows(int $productId, int $updatedShopId, array $fields): void
    {
        if ($productId <= 0 || $updatedShopId <= 0) { throw new \InvalidArgumentException('Product and shop IDs must be positive'); }
        $allowed = ['price' => true, 'active' => true];
        $fields = array_values(array_unique(array_filter(
            array_map(static fn(mixed $field): string => trim((string) $field), $fields),
            static fn(string $field): bool => isset($allowed[$field])
        )));
        if ($fields === []) { return; }

        $db = \Db::getInstance();
        $defaultShopId = (int) $db->getValue(
            'SELECT id_shop_default FROM `' . _DB_PREFIX_ . 'product` WHERE id_product=' . $productId,
            false
        );
        if ($defaultShopId <= 0) { throw new \RuntimeException('Product #' . $productId . ' has no valid default shop'); }
        if ($defaultShopId === $updatedShopId) { return; }

        $quoted = implode(',', array_map(static fn(string $field): string => '`' . $field . '`', $fields));
        $row = $db->getRow(
            'SELECT ' . $quoted . ' FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product=' . $productId . ' AND id_shop=' . $defaultShopId,
            false
        );
        if (!is_array($row) || $row === []) {
            throw new \RuntimeException('Product #' . $productId . ' default-shop shadow source is missing for shop #' . $defaultShopId);
        }

        $data = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) { throw new \RuntimeException('Missing product-shop shadow field ' . $field); }
            $data[$field] = $field === 'active' ? (int) $row[$field] : (float) $row[$field];
        }
        if (!$db->update('product', $data, 'id_product=' . $productId)) {
            throw new \RuntimeException('Could not restore default-shop product shadow fields for product #' . $productId);
        }
    }

    private function ensureLanguageRows(int $productId, int $shopId): void
    {
        $missing = $this->missingLanguageIds($productId, $shopId);
        if ($missing === []) { return; }

        $db = \Db::getInstance();
        $defaultLangId = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $shopId);
        foreach ($missing as $langId) {
            // Prefer the same language from another shop. If the language was just enabled and
            // does not exist anywhere yet, clone the best available row structurally; later core
            // persistence can overwrite translated/default fields for the source language.
            $sourceRows = $db->executeS(
                'SELECT id_shop,id_lang FROM `' . _DB_PREFIX_ . 'product_lang`' .
                ' WHERE id_product=' . $productId . ' AND id_lang=' . $langId .
                ' AND id_shop<>' . $shopId . ' ORDER BY id_shop ASC LIMIT 1',
                true,
                false
            ) ?: [];
            if ($sourceRows === []) {
                $sourceRows = $db->executeS(
                    'SELECT id_shop,id_lang FROM `' . _DB_PREFIX_ . 'product_lang`' .
                    ' WHERE id_product=' . $productId .
                    ' ORDER BY (id_shop=' . $shopId . ') DESC,' .
                    ' (id_lang=' . max(0, $defaultLangId) . ') DESC,id_shop ASC,id_lang ASC LIMIT 1',
                    true,
                    false
                ) ?: [];
            }

            $source = $sourceRows[0] ?? null;
            $sourceShopId = is_array($source) ? (int) ($source['id_shop'] ?? 0) : 0;
            $sourceLangId = is_array($source) ? (int) ($source['id_lang'] ?? 0) : 0;
            if ($sourceShopId <= 0 || $sourceLangId <= 0) {
                throw new \RuntimeException(
                    'Product #' . $productId . ' has no language row that can seed language #' . $langId .
                    ' in shop #' . $shopId
                );
            }
            $this->copyLanguageRow($productId, $sourceShopId, $sourceLangId, $shopId, $langId);
        }
    }

    /** @return list<int> */
    private function missingLanguageIds(int $productId, int $shopId): array
    {
        $required = [];
        foreach (\Language::getLanguages(false, $shopId) as $language) {
            $langId = (int) ($language['id_lang'] ?? 0);
            if ($langId > 0) { $required[$langId] = true; }
        }
        if ($required === []) { throw new \RuntimeException('Target shop #' . $shopId . ' has no active languages'); }

        $rows = \Db::getInstance()->executeS(
            'SELECT id_lang FROM `' . _DB_PREFIX_ . 'product_lang` WHERE id_product=' . $productId .
            ' AND id_shop=' . $shopId . ' ORDER BY id_lang',
            true,
            false
        ) ?: [];
        foreach ($rows as $row) { unset($required[(int) ($row['id_lang'] ?? 0)]); }

        $missing = array_map('intval', array_keys($required));
        sort($missing, SORT_NUMERIC);
        return array_values($missing);
    }

    private function rowExists(string $sql): bool
    {
        $rows = \Db::getInstance()->executeS($sql, true, false) ?: [];
        return isset($rows[0]);
    }

    private function copyShopRows(string $table, int $productId, int $sourceShopId, int $targetShopId): void
    {
        if (!in_array($table, ['product_shop', 'product_lang'], true)) { throw new \InvalidArgumentException('Unsupported product association table: ' . $table); }
        $db = \Db::getInstance();
        $fullTable = _DB_PREFIX_ . $table;
        $names = $this->tableColumns($fullTable);
        $insertColumns = implode(',', array_map(static fn(string $name): string => '`' . $name . '`', $names));
        $selectColumns = implode(',', array_map(static fn(string $name): string => $name === 'id_shop' ? (string) $targetShopId : '`' . $name . '`', $names));
        $sql = 'INSERT IGNORE INTO `' . $fullTable . '` (' . $insertColumns . ') SELECT ' . $selectColumns . ' FROM `' . $fullTable . '` WHERE id_product=' . $productId . ' AND id_shop=' . $sourceShopId;
        if (!$db->execute($sql)) {
            throw new \RuntimeException('Could not copy ' . $table . ' association for product #' . $productId . ' from shop #' . $sourceShopId . ' to shop #' . $targetShopId);
        }
    }

    private function copyLanguageRow(
        int $productId,
        int $sourceShopId,
        int $sourceLangId,
        int $targetShopId,
        int $targetLangId
    ): void {
        $db = \Db::getInstance();
        $fullTable = _DB_PREFIX_ . 'product_lang';
        $names = $this->tableColumns($fullTable);
        $insertColumns = implode(',', array_map(static fn(string $name): string => '`' . $name . '`', $names));
        $selectColumns = implode(',', array_map(
            static function (string $name) use ($targetShopId, $targetLangId): string {
                return match ($name) {
                    'id_shop' => (string) $targetShopId,
                    'id_lang' => (string) $targetLangId,
                    default => '`' . $name . '`',
                };
            },
            $names
        ));
        $sql = 'INSERT IGNORE INTO `' . $fullTable . '` (' . $insertColumns . ') SELECT ' . $selectColumns .
            ' FROM `' . $fullTable . '` WHERE id_product=' . $productId . ' AND id_shop=' . $sourceShopId .
            ' AND id_lang=' . $sourceLangId;
        if (!$db->execute($sql)) {
            throw new \RuntimeException(
                'Could not recover product #' . $productId . ' language #' . $targetLangId . ' in shop #' . $targetShopId
            );
        }
    }

    /** @return list<string> */
    private function tableColumns(string $fullTable): array
    {
        $columns = \Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $fullTable . '`', true, false) ?: [];
        $names = [];
        foreach ($columns as $column) {
            $name = (string) ($column['Field'] ?? '');
            if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/D', $name)) {
                throw new \RuntimeException('Unsafe or empty column in ' . $fullTable);
            }
            $names[] = $name;
        }
        if (!in_array('id_product', $names, true) || !in_array('id_shop', $names, true)) {
            throw new \RuntimeException('Unexpected PrestaShop association schema: ' . $fullTable);
        }
        return $names;
    }
}