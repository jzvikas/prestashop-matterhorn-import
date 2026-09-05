<?php
namespace Lp\MatterhornImport\Category;

use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Product\ProductShopAssociationManager;
use Lp\MatterhornImport\Repository\CategoryMappingRepository;
use Lp\MatterhornImport\Util\ShopContextManager;

final class CategorySynchronizer
{
    /** @var array<string,list<int>> */
    private array $hierarchyCache = [];

    public function __construct(
        private CategoryMappingRepository $mapping,
        private CategoryAutoMapper $autoMapper,
        private ShopContextManager $shopContext,
        private ProductShopAssociationManager $associations
    ) {}

    public function sync(int $productId, ProductData $data, int $shopId): void
    {
        if (!array_key_exists('categories', $data->extra)) { return; }
        $this->shopContext->activate($shopId);
        $this->autoMapper->prepare($data, $shopId);
        $keys = [];
        foreach ((array) $data->extra['categories'] as $category) {
            $key = trim(is_array($category) ? (string) ($category['key'] ?? '') : (string) $category);
            if ($key !== '') { $keys[$key] = $key; }
        }
        $keys = array_values($keys);
        if ($keys === []) { throw new \RuntimeException('Category domain is present but contains no supplier category keys'); }
        $resolved = $this->mapping->resolveActiveCategoryIds($keys, $shopId);
        $missing = array_values(array_diff($keys, array_keys($resolved)));
        if ($missing !== []) { throw new \RuntimeException('Unmapped supplier categories: ' . implode(', ', array_slice($missing, 0, 20))); }
        $leafIds = array_values(array_unique(array_map('intval', array_values($resolved))));
        $categoryIds = $this->expandHierarchy($leafIds, $shopId);
        if ($categoryIds === []) { throw new \RuntimeException('Resolved category hierarchy is empty'); }

        // category_product and the product's default-category field are not safely isolated
        // per shop. Never mutate them for a product shared with another shop.
        $this->associations->assertExclusiveGlobalOwnership($productId, $shopId);

        $product = new \Product($productId, false, null, $shopId);
        if (!\Validate::isLoadedObject($product)) { throw new \RuntimeException('Product not found for category sync: ' . $productId); }
        $product->id_category_default = $leafIds[0];
        if (!$product->update()) { throw new \RuntimeException('Could not update default category for product ' . $productId); }
        if (!$product->updateCategories($categoryIds)) { throw new \RuntimeException('Could not update product categories for product ' . $productId); }
    }

    /** @param list<int> $leafIds @return list<int> */
    private function expandHierarchy(array $leafIds, int $shopId): array
    {
        $keyIds = array_values(array_unique(array_filter(array_map('intval', $leafIds), static fn(int $id): bool => $id > 0)));
        sort($keyIds, SORT_NUMERIC);
        if ($keyIds === []) { return []; }

        $rootId = (int) \Configuration::get('PS_ROOT_CATEGORY', null, null, $shopId);
        if ($rootId <= 0) { $rootId = (int) \Configuration::get('PS_ROOT_CATEGORY'); }
        $current = $this->liveHierarchy($keyIds, $shopId, $rootId);
        $cacheKey = $shopId . ':' . implode(',', $keyIds);
        if (isset($this->hierarchyCache[$cacheKey]) && $this->hierarchyCache[$cacheKey] === $current) {
            return $this->hierarchyCache[$cacheKey];
        }
        return $this->hierarchyCache[$cacheKey] = $current;
    }

    /** @param list<int> $leafIds @return list<int> */
    private function liveHierarchy(array $leafIds, int $shopId, int $rootId): array
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT leaf.id_category AS leaf_id,parent.id_category " .
            "FROM `%scategory` leaf " .
            "INNER JOIN `%scategory_shop` leaf_shop ON leaf_shop.id_category=leaf.id_category AND leaf_shop.id_shop=%d " .
            "INNER JOIN `%scategory` parent ON leaf.nleft BETWEEN parent.nleft AND parent.nright " .
            "INNER JOIN `%scategory_shop` parent_shop ON parent_shop.id_category=parent.id_category AND parent_shop.id_shop=%d " .
            "WHERE leaf.id_category IN (%s) AND parent.id_category<>%d " .
            "ORDER BY leaf.id_category,parent.nleft,parent.id_category",
            _DB_PREFIX_, _DB_PREFIX_, $shopId, _DB_PREFIX_, _DB_PREFIX_, $shopId,
            implode(',', array_map('intval', $leafIds)), $rootId
        ), true, false);
        if (!is_array($rows)) {
            throw new \RuntimeException('Could not inspect live target-shop category hierarchy');
        }

        $seenLeaves = [];
        $ids = [];
        foreach ($rows as $row) {
            $leafId = (int) ($row['leaf_id'] ?? 0);
            $categoryId = (int) ($row['id_category'] ?? 0);
            if ($leafId > 0) { $seenLeaves[$leafId] = true; }
            if ($categoryId > 0 && $categoryId !== $rootId) { $ids[$categoryId] = $categoryId; }
        }
        foreach ($leafIds as $leafId) {
            if (!isset($seenLeaves[$leafId])) {
                throw new \RuntimeException('Mapped category is unavailable in target shop: ' . $leafId);
            }
        }
        return array_values($ids);
    }
}
