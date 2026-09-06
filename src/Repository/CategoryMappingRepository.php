<?php
namespace Lp\MatterhornImport\Repository;

final class CategoryMappingRepository
{
    private const PRELOAD_CHUNK = 500;
    /** @var array<int,array<string,array<string,mixed>|null>> */
    private array $cache = [];

    /** @param list<string> $supplierKeys @return array<string,int> */
    public function resolveActiveCategoryIds(array $supplierKeys, int $shopId): array
    {
        $supplierKeys = array_values(array_unique(array_filter(
            array_map(static fn(mixed $key): string => trim((string) $key), $supplierKeys),
            static fn(string $key): bool => $key !== ''
        )));
        if ($supplierKeys === []) { return []; }
        $this->preload($supplierKeys, $shopId);
        $resolved = [];
        foreach ($supplierKeys as $key) {
            $row = $this->cache[$shopId][$key] ?? null;
            if (is_array($row) && (int) ($row['active'] ?? 0) === 1 && (int) ($row['id_category'] ?? 0) > 0) {
                $resolved[$key] = (int) $row['id_category'];
            }
        }
        return $resolved;
    }

    /** @param list<string> $supplierKeys */
    public function preload(array $supplierKeys, int $shopId): void
    {
        if ($shopId <= 0) { throw new \InvalidArgumentException('Category mapping requires a concrete shop'); }
        $pending = [];
        foreach ($supplierKeys as $key) {
            $key = trim((string) $key);
            if ($key !== '' && !array_key_exists($key, $this->cache[$shopId] ?? [])) { $pending[$key] = $key; }
        }
        if ($pending === []) { return; }

        foreach (array_chunk(array_values($pending), self::PRELOAD_CHUNK) as $chunk) {
            $quoted = array_map(static fn(string $key): string => "'" . pSQL($key) . "'", $chunk);
            $rows = \Db::getInstance()->executeS(sprintf(
                "SELECT supplier_key,id_category,active FROM `%sli_matterhornim_99dfbf_category_mapping` WHERE id_shop=%d AND supplier_key IN (%s)",
                _DB_PREFIX_, $shopId, implode(',', $quoted)
            ), true, false) ?: [];
            foreach ($chunk as $key) { $this->cache[$shopId][$key] = null; }
            foreach ($rows as $row) {
                $key = (string) ($row['supplier_key'] ?? '');
                if ($key !== '') { $this->cache[$shopId][$key] = $row; }
            }
        }
    }

    public function upsertMetadata(int $shopId, string $supplierKey, string $supplierName, ?string $supplierParentKey, ?string $supplierPath, bool $active = true): void
    {
        $supplierKey = trim($supplierKey);
        if ($shopId <= 0 || $supplierKey === '') { throw new \InvalidArgumentException('Category mapping requires shop and supplier key'); }
        $sql = sprintf(
            "INSERT INTO `%sli_matterhornim_99dfbf_category_mapping` (`id_shop`,`supplier_key`,`supplier_parent_key`,`supplier_name`,`supplier_path`,`id_category`,`active`,`updated_at`) VALUES (%d,'%s',%s,'%s',%s,NULL,%d,'%s') ON DUPLICATE KEY UPDATE `supplier_parent_key`=VALUES(`supplier_parent_key`),`supplier_name`=VALUES(`supplier_name`),`supplier_path`=VALUES(`supplier_path`),`updated_at`=VALUES(`updated_at`)",
            _DB_PREFIX_, $shopId, pSQL($supplierKey),
            $supplierParentKey === null ? 'NULL' : "'" . pSQL($supplierParentKey) . "'",
            pSQL($supplierName), $supplierPath === null ? 'NULL' : "'" . pSQL($supplierPath, true) . "'",
            $active ? 1 : 0, date('Y-m-d H:i:s')
        );
        if (!\Db::getInstance()->execute($sql)) { throw new \RuntimeException('Category mapping metadata upsert failed'); }
        unset($this->cache[$shopId][$supplierKey]);
    }

    public function assign(int $shopId, string $supplierKey, int $categoryId, bool $active = true): void
    {
        $supplierKey = trim($supplierKey);
        if ($shopId <= 0 || $supplierKey === '' || $categoryId <= 0) {
            throw new \InvalidArgumentException('Category assignment requires shop, supplier key and category');
        }
        $this->assertCategoryInShop($categoryId, $shopId);
        $db = \Db::getInstance();
        if (!$db->update(
            'li_matterhornim_99dfbf_category_mapping',
            ['id_category' => $categoryId, 'active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')],
            sprintf("id_shop=%d AND supplier_key='%s'", $shopId, pSQL($supplierKey)),
            0,
            true,
            false
        )) { throw new \RuntimeException('Category mapping assignment failed'); }

        $row = $db->getRow(sprintf(
            "SELECT id_category,active FROM `%sli_matterhornim_99dfbf_category_mapping` WHERE id_shop=%d AND supplier_key='%s'",
            _DB_PREFIX_, $shopId, pSQL($supplierKey)
        ), false);
        if (!$row || (int) ($row['id_category'] ?? 0) !== $categoryId || (int) ($row['active'] ?? 0) !== ($active ? 1 : 0)) {
            unset($this->cache[$shopId][$supplierKey]);
            throw new \RuntimeException('Category mapping assignment could not be verified after write');
        }

        $this->cache[$shopId][$supplierKey] = [
            'supplier_key' => $supplierKey,
            'id_category' => $categoryId,
            'active' => $active ? 1 : 0,
        ];
    }

    public function updateMapping(int $shopId, string $supplierKey, ?int $categoryId, bool $active): void
    {
        $supplierKey = trim($supplierKey);
        if ($shopId <= 0 || $supplierKey === '') {
            throw new \InvalidArgumentException('Category mapping update requires shop and supplier key');
        }
        if ($categoryId !== null) {
            if ($categoryId <= 0) { throw new \InvalidArgumentException('Mapped category id must be positive'); }
            $this->assertCategoryInShop($categoryId, $shopId);
        }

        $db = \Db::getInstance();
        if (!$db->update(
            'li_matterhornim_99dfbf_category_mapping',
            [
                'id_category' => $categoryId,
                'active' => $active ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            sprintf("id_shop=%d AND supplier_key='%s'", $shopId, pSQL($supplierKey)),
            0,
            true,
            false
        )) {
            throw new \RuntimeException('Could not update category mapping');
        }

        $row = $this->findOne($shopId, $supplierKey);
        $actualCategoryId = $row === null || empty($row['id_category']) ? null : (int) $row['id_category'];
        if ($row === null || $actualCategoryId !== $categoryId || (int) ($row['active'] ?? 0) !== ($active ? 1 : 0)) {
            throw new \RuntimeException('Category mapping update could not be verified after write');
        }
        unset($this->cache[$shopId][$supplierKey]);
    }

    /** @return list<array<string,mixed>> */
    public function findAll(int $shopId, int $langId, int $limit = 5000): array
    {
        $this->assertAdminContext($shopId, $langId);
        $limit = max(1, min(5000, $limit));
        $rootId = $this->rootCategoryId($shopId);
        $homeId = $this->homeCategoryId($shopId);
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT m.*,cl.name AS prestashop_category_name,(SELECT GROUP_CONCAT(pl.name ORDER BY p.nleft SEPARATOR ' > ') FROM `%1\$scategory` leaf INNER JOIN `%1\$scategory` p ON leaf.nleft BETWEEN p.nleft AND p.nright INNER JOIN `%1\$scategory_lang` pl ON pl.id_category=p.id_category AND pl.id_lang=%2\$d AND pl.id_shop=%3\$d WHERE leaf.id_category=m.id_category AND p.id_category NOT IN (%4\$d,%5\$d)) AS prestashop_category_path FROM `%1\$sli_matterhornim_99dfbf_category_mapping` m LEFT JOIN `%1\$scategory_lang` cl ON cl.id_category=m.id_category AND cl.id_lang=%2\$d AND cl.id_shop=%3\$d WHERE m.id_shop=%3\$d ORDER BY COALESCE(NULLIF(m.supplier_path,''),m.supplier_name),m.supplier_key LIMIT %6\$d",
            _DB_PREFIX_, $langId, $shopId, $rootId, $homeId, $limit
        ), true, false);
        if ($rows === false) { throw new \RuntimeException('Could not load Matterhorn category mappings'); }
        return array_values($rows);
    }

    /** @return array<string,mixed>|null */
    public function findOne(int $shopId, string $supplierKey): ?array
    {
        $supplierKey = trim($supplierKey);
        if ($shopId <= 0 || $supplierKey === '') { return null; }
        $row = \Db::getInstance()->getRow(sprintf(
            "SELECT * FROM `%sli_matterhornim_99dfbf_category_mapping` WHERE id_shop=%d AND supplier_key='%s'",
            _DB_PREFIX_, $shopId, pSQL($supplierKey)
        ), false);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function findUnmapped(int $shopId): array
    {
        if ($shopId <= 0) { throw new \InvalidArgumentException('Category mapping requires a concrete shop'); }
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT supplier_key,supplier_name,supplier_path,active FROM `%sli_matterhornim_99dfbf_category_mapping` WHERE id_shop=%d AND (id_category IS NULL OR id_category=0) ORDER BY COALESCE(NULLIF(supplier_path,''),supplier_name),supplier_key",
            _DB_PREFIX_, $shopId
        ), true, false);
        if ($rows === false) { throw new \RuntimeException('Could not load unmapped Matterhorn categories'); }
        return array_values($rows);
    }

    public function countAll(int $shopId): int
    {
        return $this->countWhere($shopId, '1=1');
    }

    public function countUnmapped(int $shopId): int
    {
        return $this->countWhere($shopId, '(id_category IS NULL OR id_category=0)');
    }

    private function countWhere(int $shopId, string $where): int
    {
        if ($shopId <= 0) { throw new \InvalidArgumentException('Category mapping requires a concrete shop'); }
        return (int) \Db::getInstance()->getValue(sprintf(
            "SELECT COUNT(*) FROM `%sli_matterhornim_99dfbf_category_mapping` WHERE id_shop=%d AND %s",
            _DB_PREFIX_, $shopId, $where
        ), false);
    }

    private function assertCategoryInShop(int $categoryId, int $shopId): void
    {
        $exists = (int) \Db::getInstance()->getValue(sprintf(
            "SELECT COUNT(*) FROM `%scategory_shop` WHERE id_category=%d AND id_shop=%d",
            _DB_PREFIX_, $categoryId, $shopId
        ), false);
        if ($exists !== 1) {
            throw new \InvalidArgumentException('Selected PrestaShop category does not belong to the active shop');
        }
    }

    private function assertAdminContext(int $shopId, int $langId): void
    {
        if ($shopId <= 0 || $langId <= 0) {
            throw new \InvalidArgumentException('Category mapping requires concrete shop and language');
        }
    }

    private function rootCategoryId(int $shopId): int
    {
        $shop = \Shop::getShop($shopId);
        $id = is_array($shop) ? (int) ($shop['id_category'] ?? 0) : 0;
        if ($id <= 0) { $id = (int) \Configuration::get('PS_ROOT_CATEGORY', null, null, $shopId); }
        return max(0, $id);
    }

    private function homeCategoryId(int $shopId): int
    {
        $id = (int) \Configuration::get('PS_HOME_CATEGORY', null, null, $shopId);
        if ($id <= 0) { $id = (int) \Configuration::get('PS_HOME_CATEGORY'); }
        return max(0, $id);
    }
}
