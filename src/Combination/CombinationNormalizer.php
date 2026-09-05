<?php
namespace Lp\MatterhornImport\Combination;

use Lp\MatterhornImport\DTO\ProductData;

final class CombinationNormalizer
{
    public function normalize(ProductData $product): array
    {
        if (!array_key_exists('combinations', $product->extra)) {
            return [];
        }
        $raw = $product->extra['combinations'];
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Product combinations payload must be an array for ' . $product->sourceKey);
        }
        $normalized = [];
        $seen = [];
        foreach (array_values($raw) as $index => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException(sprintf('Combination #%d must be an array for %s', $index + 1, $product->sourceKey));
            }
            $attributeIds = $this->attributeIds($row, $product->sourceKey, $index);
            $semanticKey = $this->semanticKey($attributeIds);
            if (isset($seen[$semanticKey])) {
                throw new \InvalidArgumentException(sprintf('Duplicate semantic combination for %s at rows %d and %d', $product->sourceKey, $seen[$semanticKey] + 1, $index + 1));
            }
            $seen[$semanticKey] = $index;

            $reference = trim((string) ($row['reference'] ?? ''));
            $ean13 = trim((string) ($row['ean13'] ?? ''));
            $upc = trim((string) ($row['upc'] ?? ''));
            $mpn = trim((string) ($row['mpn'] ?? ''));
            $this->assertMaxLength($reference, 64, 'reference', $product->sourceKey, $index);
            $this->assertMaxLength($ean13, 13, 'ean13', $product->sourceKey, $index);
            $this->assertMaxLength($upc, 12, 'upc', $product->sourceKey, $index);
            $this->assertMaxLength($mpn, 40, 'mpn', $product->sourceKey, $index);
            $quantity = $this->intValue($row['quantity'] ?? 0, 'quantity', $product->sourceKey, $index);
            $minimalQuantity = $this->intValue($row['minimal_quantity'] ?? 1, 'minimal_quantity', $product->sourceKey, $index);
            if ($minimalQuantity < 1) {
                throw new \InvalidArgumentException(sprintf('Combination #%d minimal_quantity must be >= 1 for %s', $index + 1, $product->sourceKey));
            }
            $priceImpact = $this->floatValue($row['price_impact'] ?? 0.0, 'price_impact', $product->sourceKey, $index);
            $weightImpact = $this->floatValue($row['weight_impact'] ?? 0.0, 'weight_impact', $product->sourceKey, $index);
            $wholesalePrice = $this->floatValue($row['wholesale_price'] ?? 0.0, 'wholesale_price', $product->sourceKey, $index);
            $structure = [
                'semantic_key' => $semanticKey,
                'attribute_ids' => $attributeIds,
                'reference' => $reference,
                'price_impact' => $priceImpact,
                'weight_impact' => $weightImpact,
                'wholesale_price' => $wholesalePrice,
                'minimal_quantity' => $minimalQuantity,
                'ean13' => $ean13,
                'upc' => $upc,
                'mpn' => $mpn,
            ];
            $normalized[] = $structure + [
                'quantity' => $quantity,
                'default' => !empty($row['default']),
                'structure_hash' => $this->hash($structure),
                'stock_hash' => $this->hash(['semantic_key' => $semanticKey, 'quantity' => $quantity]),
            ];
        }
        usort($normalized, static fn(array $a, array $b): int => strcmp($a['semantic_key'], $b['semantic_key']));
        return $normalized;
    }

    public function semanticKey(array $attributeIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $attributeIds)));
        sort($ids, SORT_NUMERIC);
        return hash('sha256', implode(':', $ids));
    }

    private function attributeIds(array $row, string $sourceKey, int $index): array
    {
        $raw = $row['attribute_ids'] ?? $row['attributes'] ?? null;
        if (!is_array($raw) || $raw === []) {
            throw new \InvalidArgumentException(sprintf('Combination #%d requires non-empty attribute_ids for %s', $index + 1, $sourceKey));
        }
        $ids = [];
        foreach ($raw as $value) {
            if (is_int($value)) {
                $id = $value;
            } elseif (is_string($value) && ctype_digit($value)) {
                $id = (int) $value;
            } else {
                throw new \InvalidArgumentException(sprintf('Combination #%d contains unresolved attribute id for %s; map supplier attributes first', $index + 1, $sourceKey));
            }
            if ($id <= 0) {
                throw new \InvalidArgumentException(sprintf('Combination #%d contains invalid attribute id for %s', $index + 1, $sourceKey));
            }
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function intValue(mixed $value, string $field, string $sourceKey, int $index): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false) {
            throw new \InvalidArgumentException(sprintf('Combination #%d %s must be an integer for %s', $index + 1, $field, $sourceKey));
        }
        return (int) $validated;
    }

    private function floatValue(mixed $value, string $field, string $sourceKey, int $index): float
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('Combination #%d %s must be numeric for %s', $index + 1, $field, $sourceKey));
        }
        $float = (float) $value;
        if (!is_finite($float)) {
            throw new \InvalidArgumentException(sprintf('Combination #%d %s must be finite for %s', $index + 1, $field, $sourceKey));
        }
        return $float;
    }

    private function assertMaxLength(string $value, int $maxLength, string $field, string $sourceKey, int $index): void
    {
        if (strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(sprintf('Combination #%d %s exceeds %d bytes for %s', $index + 1, $field, $maxLength, $sourceKey));
        }
    }

    private function hash(array $value): string
    {
        return hash('xxh3', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
