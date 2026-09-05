<?php
namespace Lp\MatterhornImport\Feature;

use Lp\MatterhornImport\DTO\ProductData;

final class FeatureNormalizer
{
    /** @return list<array{key:string,value_key:string,name:string,value:string}> */
    public function normalize(ProductData $product): array
    {
        if (!array_key_exists('features', $product->extra)) {
            return [];
        }
        $raw = $product->extra['features'];
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Product features payload must be an array for ' . $product->sourceKey);
        }

        $normalized = [];
        $seen = [];
        foreach (array_values($raw) as $index => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException(sprintf('Feature #%d must be an array for %s', $index + 1, $product->sourceKey));
            }
            $key = trim((string) ($row['key'] ?? ''));
            $valueKey = trim((string) ($row['value_key'] ?? ''));
            if ($key === '' || $valueKey === '') {
                throw new \InvalidArgumentException(sprintf('Feature #%d requires non-empty key and value_key for %s', $index + 1, $product->sourceKey));
            }
            $identity = $key . "\0" . $valueKey;
            if (isset($seen[$identity])) {
                throw new \InvalidArgumentException(sprintf('Duplicate supplier feature/value pair %s/%s for %s', $key, $valueKey, $product->sourceKey));
            }
            $seen[$identity] = true;

            $name = trim((string) ($row['name'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if (strlen($key) > 191 || strlen($valueKey) > 191) {
                throw new \InvalidArgumentException('Feature supplier key exceeds 191 bytes for ' . $product->sourceKey);
            }
            if (strlen($name) > 128) {
                throw new \InvalidArgumentException('Feature name exceeds 128 bytes for ' . $product->sourceKey);
            }
            if (strlen($value) > 255) {
                throw new \InvalidArgumentException('Feature value exceeds 255 bytes for ' . $product->sourceKey);
            }

            $normalized[] = ['key' => $key, 'value_key' => $valueKey, 'name' => $name, 'value' => $value];
        }

        usort($normalized, static fn(array $a, array $b): int => [$a['key'], $a['value_key']] <=> [$b['key'], $b['value_key']]);
        return $normalized;
    }
}
