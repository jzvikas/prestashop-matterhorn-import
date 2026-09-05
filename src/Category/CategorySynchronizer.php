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
        $cacheKey = $shopId . ':' . implode(',', $keyIds);
        if (isset($this->hierarchyCache[$cacheKey])) {
            return $this->hierarchyCache[$cacheKey];
        }

        $langId = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $shopId);
        if ($langId <= 0) { $langId = (int) \Configuration::get('PS_LANG_DEFAULT'); }
        $rootId = (int) \Configuration::get('PS_ROOT_CATEGORY', null, null, $shopId);
        if ($rootId <= 0) { $rootId = (int) \Configuration::get('PS_ROOT_CATEGORY'); }
        $ids = [];
        foreach ($keyIds as $leafId) {
            $category = new \Category($leafId, $langId, $shopId);
            if (!\Validate::isLoadedObject($category) || !$category->existsInShop($shopId)) { throw new \RuntimeException('Mapped category is unavailable in target shop: ' . $leafId); }
            foreach ($category->getParentsCategories($langId) as $row) {
                $id = (int) ($row['id_category'] ?? 0);
                if ($id > 0 && $id !== $rootId) { $ids[$id] = $id; }
            }
        }
        return $this->hierarchyCache[$cacheKey] = array_values($ids);
    }
}
