<?php
namespace Lp\MatterhornImport\Mapper;

use Lp\MatterhornImport\Contract\ProductMapperInterface;
use Lp\MatterhornImport\Contract\SizeResolverInterface;
use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
use Lp\MatterhornImport\Matterhorn\MatterhornHtmlSanitizer;

final class MatterhornProductMapper implements ProductMapperInterface
{
    public function __construct(
        private readonly SizeResolverInterface $sizes,
        private readonly MatterhornCategoryPathNormalizer $categories,
        private readonly MatterhornHtmlSanitizer $html,
        private readonly bool $categoryAutoCreate = true,
        private readonly bool $featureAutoCreate = true,
    ) {
    }

    public function map(array $row): ProductData
    {
        $sourceKey = trim((string) ($row['id'] ?? ''));
        if ($sourceKey === '') {
            throw new \InvalidArgumentException('Matterhorn product is missing id');
        }
        if (!ctype_digit($sourceKey)) {
            throw new \InvalidArgumentException('Matterhorn product id must be numeric: ' . $sourceKey);
        }

        $reference = 'MH-' . $sourceKey;
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Matterhorn product ' . $sourceKey . ' is missing name');
        }
        if (mb_strlen($name, 'UTF-8') > 128) {
            $name = mb_substr($name, 0, 128, 'UTF-8');
        }

        $priceRaw = trim((string) ($row['price'] ?? ''));
        if ($priceRaw === '' || !is_numeric($priceRaw)) {
            throw new \InvalidArgumentException('Matterhorn product ' . $sourceKey . ' has invalid price');
        }
        $price = (float) $priceRaw;
        if (!is_finite($price) || $price < 0.0) {
            throw new \InvalidArgumentException('Matterhorn product ' . $sourceKey . ' has invalid price');
        }

        $images = [];
        $seenImages = [];
        foreach ((array) ($row['images'] ?? []) as $urlRaw) {
            $url = trim((string) $urlRaw);
            if ($url === '' || isset($seenImages[$url])) {
                continue;
            }
            $parts = parse_url($url);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new \InvalidArgumentException('Matterhorn product ' . $sourceKey . ' contains non-HTTP image URL');
            }
            $seenImages[$url] = true;
            $images[] = $url;
        }

        $extra = [
            'description' => $this->html->sanitize((string) ($row['description'] ?? '')),
        ];

        $brand = trim((string) ($row['brand'] ?? ''));
        if ($brand !== '') {
            $extra['manufacturer'] = ['name' => $brand, 'auto_create' => true];
        }

        $category = is_array($row['category'] ?? null) ? $row['category'] : [];
        $categoryId = trim((string) ($category['id'] ?? ''));
        $categoryName = trim((string) ($category['name'] ?? ''));
        $categoryPath = $this->categories->normalize((string) ($row['category_path'] ?? ''));
        if ($categoryId !== '') {
            $extra['categories'] = [[
                'key' => $this->categories->key($categoryId),
                'name' => $categoryName !== '' ? $categoryName : $categoryId,
                'path' => $categoryPath !== '' ? $categoryPath : ($categoryName !== '' ? $categoryName : $categoryId),
                'auto_create' => $this->categoryAutoCreate,
            ]];
        }

        $features = [];
        foreach (['color' => 'Color', 'type' => 'Type'] as $rawKey => $displayName) {
            $value = trim((string) ($row[$rawKey] ?? ''));
            if ($value === '') {
                continue;
            }
            $features[] = [
                'key' => 'matterhorn:' . $rawKey,
                'value_key' => 'matterhorn:' . $rawKey . ':' . $this->identity($value),
                'name' => $displayName,
                'value' => $value,
            ];
        }
        if ($features !== []) {
            $extra['features'] = $features;
            $extra['features_authoritative'] = true;
            $extra['features_auto_create'] = $this->featureAutoCreate;
        }

        $options = is_array($row['options'] ?? null) ? array_values($row['options']) : [];
        $combinations = [];
        $seenOptionIds = [];
        $seenAttributeIds = [];
        $prepared = [];
        foreach ($options as $index => $option) {
            if (!is_array($option)) {
                throw new \InvalidArgumentException('Matterhorn option #' . ($index + 1) . ' is invalid for product ' . $sourceKey);
            }
            $optionId = trim((string) ($option['id'] ?? ''));
            $size = trim((string) ($option['name'] ?? ''));
            if ($optionId === '') {
                throw new \InvalidArgumentException('Matterhorn product ' . $sourceKey . ' contains option without id');
            }
            if (isset($seenOptionIds[$optionId])) {
                throw new \InvalidArgumentException('Duplicate Matterhorn option id ' . $optionId . ' for product ' . $sourceKey);
            }
            $seenOptionIds[$optionId] = true;
            if ($size === '') {
                throw new \InvalidArgumentException('Matterhorn option ' . $optionId . ' has empty size for product ' . $sourceKey);
            }
            $attributeId = $this->sizes->resolve($size);
            if ($attributeId <= 0) {
                throw new \RuntimeException('Could not resolve Matterhorn size ' . $size . ' for product ' . $sourceKey);
            }
            if (isset($seenAttributeIds[$attributeId])) {
                throw new \InvalidArgumentException('Duplicate semantic size ' . $size . ' for Matterhorn product ' . $sourceKey);
            }
            $seenAttributeIds[$attributeId] = true;

            $stockRaw = trim((string) ($option['stock'] ?? '0'));
            $stock = filter_var($stockRaw, FILTER_VALIDATE_INT);
            if ($stock === false) {
                throw new \InvalidArgumentException('Matterhorn option ' . $optionId . ' has invalid stock for product ' . $sourceKey);
            }
            $stock = max(0, (int) $stock);

            $ean = trim((string) ($option['ean'] ?? ''));
            if ($ean !== '' && !preg_match('/^\d{13}$/D', $ean)) {
                $ean = '';
            }
            $availableRaw = trim((string) ($option['available_in'] ?? ''));
            $availableIn = $availableRaw !== '' && filter_var($availableRaw, FILTER_VALIDATE_INT) !== false
                ? (int) $availableRaw
                : null;

            $prepared[] = [
                'attribute_id' => $attributeId,
                'reference' => $optionId,
                'quantity' => $stock,
                'ean13' => $ean,
                'available_in' => $availableIn,
            ];
        }

        usort($prepared, static fn(array $a, array $b): int => strcmp($a['reference'], $b['reference']));
        foreach ($prepared as $index => $item) {
            $combinations[] = [
                'attribute_ids' => [$item['attribute_id']],
                'reference' => $item['reference'],
                'quantity' => $item['quantity'],
                'price_impact' => 0.0,
                'weight_impact' => 0.0,
                'wholesale_price' => 0.0,
                'minimal_quantity' => 1,
                'ean13' => $item['ean13'],
                'upc' => '',
                'mpn' => '',
                'default' => $index === 0,
                'matterhorn_available_in' => $item['available_in'],
            ];
        }
        if ($combinations !== []) {
            $extra['combinations'] = $combinations;
            $extra['combinations_authoritative'] = true;
        }

        return new ProductData(
            $sourceKey,
            $reference,
            ['default' => $name],
            $price,
            0,
            true,
            $images,
            $extra
        );
    }

    private function identity(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? $value;
        return trim($value, '-');
    }
}
