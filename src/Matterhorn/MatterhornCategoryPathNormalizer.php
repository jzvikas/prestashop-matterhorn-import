<?php
namespace Lp\MatterhornImport\Matterhorn;

final class MatterhornCategoryPathNormalizer
{
    public function normalize(string $path): string
    {
        $parts = preg_split('#/+#u', trim($path)) ?: [];
        $parts = array_values(array_filter(array_map(
            static fn(string $part): string => trim($part),
            $parts
        ), static fn(string $part): bool => $part !== ''));
        return implode(' > ', $parts);
    }

    public function key(string $supplierCategoryId): string
    {
        $id = trim($supplierCategoryId);
        if ($id === '') {
            throw new \InvalidArgumentException('Matterhorn category id cannot be empty');
        }
        return 'matterhorn-category:' . $id;
    }
}
