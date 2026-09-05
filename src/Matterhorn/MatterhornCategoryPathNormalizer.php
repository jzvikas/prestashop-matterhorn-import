<?php
namespace Lp\MatterhornImport\Matterhorn;

final class MatterhornCategoryPathNormalizer
{
    private const MAX_CATEGORY_SEGMENT_CHARS = 128;
    private const MAX_CATEGORY_PATH_DEPTH = 32;
    private const MAX_SUPPLIER_KEY_CHARS = 191;

    public function normalize(string $path): string
    {
        $parts = preg_split('#/+#u', trim($path)) ?: [];
        $parts = array_values(array_filter(array_map(
            static fn(string $part): string => trim($part),
            $parts
        ), static fn(string $part): bool => $part !== ''));
        if (count($parts) > self::MAX_CATEGORY_PATH_DEPTH) {
            throw new \InvalidArgumentException(
                'Matterhorn category path depth exceeds operational limit of ' . self::MAX_CATEGORY_PATH_DEPTH
            );
        }
        foreach ($parts as $part) {
            if (mb_strlen($part, 'UTF-8') > self::MAX_CATEGORY_SEGMENT_CHARS) {
                throw new \InvalidArgumentException(
                    'Matterhorn category path segment exceeds PrestaShop ' .
                    self::MAX_CATEGORY_SEGMENT_CHARS . '-character limit'
                );
            }
        }
        return implode(' > ', $parts);
    }

    public function key(string $supplierCategoryId): string
    {
        $id = trim($supplierCategoryId);
        if ($id === '') {
            throw new \InvalidArgumentException('Matterhorn category id cannot be empty');
        }
        $key = 'matterhorn-category:' . $id;
        if (mb_strlen($key, 'UTF-8') > self::MAX_SUPPLIER_KEY_CHARS) {
            throw new \InvalidArgumentException(
                'Matterhorn category supplier key exceeds module ' . self::MAX_SUPPLIER_KEY_CHARS . '-character limit'
            );
        }
        return $key;
    }
}
