<?php
namespace Lp\MatterhornImport\Combination;

use Lp\MatterhornImport\Attribute\AttributeResolver;
use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Repository\AttributeMappingRepository;

final class CombinationAttributeResolver
{
    public function __construct(private AttributeMappingRepository $mapping, private AttributeResolver $resolver) {}

    public function resolve(ProductData $product, int $shopId, string $source): ProductData
    {
        if (!array_key_exists('combinations', $product->extra)) { return $product; }
        $rows = $product->extra['combinations'];
        if (!is_array($rows)) { throw new \InvalidArgumentException('Product combinations payload must be an array for ' . $product->sourceKey); }

        $autoCreate = !empty($product->extra['combination_attributes_auto_create']);
        $resolvedRows = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) { throw new \InvalidArgumentException(sprintf('Combination #%d must be an array for %s', $index + 1, $product->sourceKey)); }
            $rawAttributes = $row['attribute_ids'] ?? $row['attributes'] ?? null;
            if (!is_array($rawAttributes) || $rawAttributes === []) {
                throw new \InvalidArgumentException(sprintf('Combination #%d requires attributes for %s', $index + 1, $product->sourceKey));
            }

            $attributeIds = [];
            foreach (array_values($rawAttributes) as $attributeIndex => $rawAttribute) {
                if (is_int($rawAttribute) || (is_string($rawAttribute) && ctype_digit($rawAttribute))) {
                    $id = (int) $rawAttribute;
                    if ($id <= 0 || !$this->attributeAvailableInShop($id, $shopId)) {
                        throw new \InvalidArgumentException(sprintf('Combination #%d contains attribute id %d unavailable in shop %d for %s', $index + 1, $id, $shopId, $product->sourceKey));
                    }
                    $attributeIds[] = $id;
                    continue;
                }
                if (!is_array($rawAttribute)) {
                    throw new \InvalidArgumentException(sprintf('Combination #%d attribute #%d must be an ID or supplier attribute object for %s', $index + 1, $attributeIndex + 1, $product->sourceKey));
                }

                $groupKey = trim((string) ($rawAttribute['group_key'] ?? ''));
                $valueKey = trim((string) ($rawAttribute['value_key'] ?? ''));
                if ($groupKey === '' || $valueKey === '') {
                    throw new \InvalidArgumentException(sprintf('Combination #%d attribute #%d requires group_key and value_key for %s', $index + 1, $attributeIndex + 1, $product->sourceKey));
                }

                $resolved = $this->mapping->resolvePair($shopId, $source, $groupKey, $valueKey);
                if ($resolved !== null && !$this->attributeAvailableInShop((int) $resolved['id_attribute'], $shopId)) { $resolved = null; }
                if ($resolved === null) {
                    if (!$autoCreate) { throw new \RuntimeException('Unmapped or stale supplier combination attribute: ' . $groupKey . '/' . $valueKey); }
                    $groupName = trim((string) ($rawAttribute['group_name'] ?? ''));
                    $valueName = trim((string) ($rawAttribute['value'] ?? ''));
                    if ($groupName === '' || $valueName === '') {
                        throw new \RuntimeException('Combination attribute auto-create requires group_name/value for ' . $groupKey . '/' . $valueKey);
                    }
                    $resolved = $this->resolver->resolveOrCreate($shopId, $groupName, $valueName);
                    $this->mapping->saveResolved(
                        $shopId, $source, $groupKey, $groupName, $valueKey, $valueName,
                        $resolved['id_attribute_group'], $resolved['id_attribute']
                    );
                }
                $attributeIds[] = (int) $resolved['id_attribute'];
            }

            $attributeIds = array_values(array_unique(array_filter(array_map('intval', $attributeIds), static fn(int $id): bool => $id > 0)));
            sort($attributeIds, SORT_NUMERIC);
            if ($attributeIds === []) { throw new \RuntimeException(sprintf('Combination #%d resolved to no attributes for %s', $index + 1, $product->sourceKey)); }
            $row['attribute_ids'] = $attributeIds;
            unset($row['attributes']);
            $resolvedRows[] = $row;
        }

        $extra = $product->extra;
        $extra['combinations'] = $resolvedRows;
        return new ProductData($product->sourceKey, $product->reference, $product->name, $product->price, $product->quantity, $product->active, $product->images, $extra);
    }

    private function attributeAvailableInShop(int $attributeId, int $shopId): bool
    {
        if ($attributeId <= 0 || $shopId <= 0) { return false; }
        return (bool) \Db::getInstance()->getValue(sprintf(
            "SELECT 1 FROM `%sattribute` a " .
            "INNER JOIN `%sattribute_shop` ash ON ash.id_attribute=a.id_attribute AND ash.id_shop=%d " .
            "INNER JOIN `%sattribute_group_shop` ags ON ags.id_attribute_group=a.id_attribute_group AND ags.id_shop=%d " .
            "WHERE a.id_attribute=%d",
            _DB_PREFIX_, _DB_PREFIX_, $shopId, _DB_PREFIX_, $shopId, $attributeId
        ));
    }
}
