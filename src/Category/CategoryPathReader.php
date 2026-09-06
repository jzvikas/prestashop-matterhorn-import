<?php
namespace Lp\MatterhornImport\Category;

final class CategoryPathReader
{
    /** @return array<int,string> */
    public function paths(int $shopId, int $langId): array
    {
        if ($shopId <= 0 || $langId <= 0) {
            throw new \InvalidArgumentException('Category path reader requires concrete shop and language');
        }

        $rootId = $this->rootCategoryId($shopId);
        $homeId = $this->homeCategoryId($shopId);
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT leaf.id_category AS leaf_id,parent.id_category AS parent_id,parent.nleft,parent_lang.name " .
            "FROM `%1\$scategory` leaf " .
            "INNER JOIN `%1\$scategory_shop` leaf_shop ON leaf_shop.id_category=leaf.id_category AND leaf_shop.id_shop=%2\$d " .
            "INNER JOIN `%1\$scategory` parent ON leaf.nleft BETWEEN parent.nleft AND parent.nright " .
            "INNER JOIN `%1\$scategory_shop` parent_shop ON parent_shop.id_category=parent.id_category AND parent_shop.id_shop=%2\$d " .
            "INNER JOIN `%1\$scategory_lang` parent_lang ON parent_lang.id_category=parent.id_category AND parent_lang.id_lang=%3\$d AND parent_lang.id_shop=%2\$d " .
            "WHERE parent.id_category NOT IN (%4\$d,%5\$d) " .
            "ORDER BY leaf.id_category,parent.nleft,parent.id_category",
            _DB_PREFIX_, $shopId, $langId, $rootId, $homeId
        ), true, false);
        if (!is_array($rows)) {
            throw new \RuntimeException('Could not load target-shop category paths');
        }

        $segments = [];
        foreach ($rows as $row) {
            $leafId = (int) ($row['leaf_id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($leafId <= 0 || $name === '') { continue; }
            $segments[$leafId][] = $name;
        }

        $paths = [];
        foreach ($segments as $leafId => $parts) {
            if ($parts === []) { continue; }
            $paths[(int) $leafId] = implode(' > ', $parts);
        }
        return $paths;
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
