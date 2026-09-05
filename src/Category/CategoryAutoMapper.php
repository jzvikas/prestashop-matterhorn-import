<?php
namespace Lp\MatterhornImport\Category;

use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Repository\CategoryMappingRepository;
use Lp\MatterhornImport\Util\ShopContextManager;

final class CategoryAutoMapper
{
    /** @var array<int,array<string,int>> */
    private array $pathMap = [];
    /** @var array<int,array<string,string>> */
    private array $preparedMetadata = [];
    /** @var array<string,bool> */
    private array $availabilityCache = [];

    public function __construct(private CategoryMappingRepository $mapping, private ShopContextManager $shopContext) {}

    public function prepare(ProductData $data, int $shopId): void
    {
        if (!array_key_exists('categories', $data->extra)) { return; }
        $this->shopContext->activate($shopId);
        foreach ((array) $data->extra['categories'] as $raw) {
            if (!is_array($raw)) { continue; }
            $key = trim((string) ($raw['key'] ?? ''));
            $name = trim((string) ($raw['name'] ?? ''));
            $parentKey = isset($raw['parent_key']) ? trim((string) $raw['parent_key']) : null;
            $path = trim((string) ($raw['path'] ?? ''));
            if ($key === '') { continue; }
            if ($name === '' && $path !== '') {
                $parts = $this->splitPath($path);
                $name = $parts === [] ? $key : (string) end($parts);
            }
            if ($name === '') { $name = $key; }
            if ($path === '') { $path = $name; }

            $metadataFingerprint = hash('xxh3', json_encode([
                'name' => $name,
                'parent_key' => $parentKey ?: null,
                'path' => $path,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $previousFingerprint = $this->preparedMetadata[$shopId][$key] ?? null;
            if ($previousFingerprint !== null && !hash_equals($previousFingerprint, $metadataFingerprint)) {
                throw new \RuntimeException('Conflicting Matterhorn category metadata for supplier key ' . $key);
            }
            if ($previousFingerprint === null) {
                $this->mapping->upsertMetadata($shopId, $key, $name, $parentKey ?: null, $path, true);
                $this->preparedMetadata[$shopId][$key] = $metadataFingerprint;
            }

            $current = $this->mapping->resolveActiveCategoryIds([$key], $shopId)[$key] ?? 0;
            if ($current > 0 && $this->categoryExistsInShop($current, $shopId)) { continue; }
            $normalizedPath = $this->normalizePath($path);
            $categoryId = $this->pathMap($shopId)[$normalizedPath] ?? 0;
            if ($categoryId <= 0 && !empty($raw['auto_create'])) { $categoryId = $this->createPath($path, $shopId); }
            if ($categoryId > 0) {
                $this->availabilityCache[$this->availabilityKey($shopId, $categoryId)] = true;
                $this->mapping->assign($shopId, $key, $categoryId, true);
            }
        }
    }

    /** @return array<string,int> */
    private function pathMap(int $shopId): array
    {
        if (isset($this->pathMap[$shopId])) { return $this->pathMap[$shopId]; }
        $langId = $this->languageId($shopId);
        $rootId = $this->rootCategoryId($shopId);
        $homeId = $this->homeCategoryId($shopId);
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT leaf.id_category,GROUP_CONCAT(parent_lang.name ORDER BY parent.nleft SEPARATOR ' > ') AS category_path " .
            "FROM `%scategory` leaf INNER JOIN `%scategory_shop` leaf_shop ON leaf_shop.id_category=leaf.id_category AND leaf_shop.id_shop=%d " .
            "INNER JOIN `%scategory` parent ON leaf.nleft BETWEEN parent.nleft AND parent.nright " .
            "INNER JOIN `%scategory_lang` parent_lang ON parent_lang.id_category=parent.id_category AND parent_lang.id_lang=%d AND parent_lang.id_shop=%d " .
            "WHERE parent.id_category NOT IN (%d,%d) GROUP BY leaf.id_category",
            _DB_PREFIX_, _DB_PREFIX_, $shopId, _DB_PREFIX_, $langId, $shopId, $rootId, $homeId
        )) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $path = $this->normalizePath((string) ($row['category_path'] ?? ''));
            $id = (int) ($row['id_category'] ?? 0);
            if ($path !== '' && $id > 0 && !isset($map[$path])) {
                $map[$path] = $id;
                $this->availabilityCache[$this->availabilityKey($shopId, $id)] = true;
            }
        }
        return $this->pathMap[$shopId] = $map;
    }

    private function createPath(string $path, int $shopId): int
    {
        $parts = $this->splitPath($path);
        if ($parts === []) { return 0; }
        $parentId = $this->homeCategoryId($shopId);
        $built = [];
        foreach ($parts as $part) {
            $built[] = $part;
            $normalized = $this->normalizePath(implode(' > ', $built));
            $existing = $this->pathMap($shopId)[$normalized] ?? 0;
            if ($existing > 0) { $parentId = $existing; continue; }
            $existing = $this->findChildCategoryId($parentId, $part, $shopId);
            if ($existing > 0) {
                $parentId = $existing;
                $this->pathMap[$shopId][$normalized] = $existing;
                $this->availabilityCache[$this->availabilityKey($shopId, $existing)] = true;
                continue;
            }
            $category = new \Category();
            $category->id_parent = $parentId;
            $category->id_shop_default = $shopId;
            $category->id_shop_list = [$shopId];
            $category->active = true;
            $category->is_root_category = false;
            $rewrite = trim((string) \Tools::str2url($part));
            if ($rewrite === '') { $rewrite = 'category-' . substr(hash('sha256', $parentId . '|' . $part), 0, 12); }
            foreach (\Language::getLanguages(false, $shopId) as $language) {
                $idLang = (int) ($language['id_lang'] ?? 0);
                if ($idLang <= 0) { continue; }
                $category->name[$idLang] = mb_substr($part, 0, 128, 'UTF-8');
                $category->link_rewrite[$idLang] = $rewrite;
            }
            if (!$category->add() || (int) $category->id <= 0) { throw new \RuntimeException('Could not create category path segment: ' . $part); }
            $parentId = (int) $category->id;
            $this->pathMap[$shopId][$normalized] = $parentId;
            $this->availabilityCache[$this->availabilityKey($shopId, $parentId)] = true;
        }
        return $parentId;
    }

    private function findChildCategoryId(int $parentId, string $name, int $shopId): int
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT c.id_category,cl.name FROM `%scategory` c INNER JOIN `%scategory_shop` cs ON cs.id_category=c.id_category AND cs.id_shop=%d " .
            "INNER JOIN `%scategory_lang` cl ON cl.id_category=c.id_category AND cl.id_lang=%d AND cl.id_shop=%d WHERE c.id_parent=%d",
            _DB_PREFIX_, _DB_PREFIX_, $shopId, _DB_PREFIX_, $this->languageId($shopId), $shopId, $parentId
        )) ?: [];
        $normalized = $this->normalizeSegment($name);
        foreach ($rows as $row) {
            if ($this->normalizeSegment((string) ($row['name'] ?? '')) === $normalized) { return (int) ($row['id_category'] ?? 0); }
        }
        return 0;
    }

    private function categoryExistsInShop(int $categoryId, int $shopId): bool
    {
        $cacheKey = $this->availabilityKey($shopId, $categoryId);
        if (array_key_exists($cacheKey, $this->availabilityCache)) {
            return $this->availabilityCache[$cacheKey];
        }
        $category = new \Category($categoryId, $this->languageId($shopId), $shopId);
        return $this->availabilityCache[$cacheKey] = \Validate::isLoadedObject($category) && $category->existsInShop($shopId);
    }

    private function availabilityKey(int $shopId, int $categoryId): string
    {
        return $shopId . ':' . $categoryId;
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
        if ($id <= 0 || !\Category::categoryExists($id)) { throw new \RuntimeException('Could not resolve target-shop home category'); }
        return $id;
    }

    private function languageId(int $shopId): int
    {
        $id = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $shopId);
        if ($id <= 0) { $id = (int) \Configuration::get('PS_LANG_DEFAULT'); }
        if ($id <= 0) { throw new \RuntimeException('Could not resolve target-shop language for category mapping'); }
        return $id;
    }

    /** @return list<string> */
    private function splitPath(string $path): array
    {
        $parts = preg_split('/\s*>\s*/u', trim($path)) ?: [];
        return array_values(array_filter(array_map(static fn(string $part): string => trim(ltrim(trim($part), '@')), $parts), static fn(string $part): bool => $part !== ''));
    }
    private function normalizePath(string $path): string { return implode(' > ', array_map(fn(string $part): string => $this->normalizeSegment($part), $this->splitPath($path))); }
    private function normalizeSegment(string $value): string
    {
        $value = mb_strtolower(trim(ltrim(trim($value), '@')), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
