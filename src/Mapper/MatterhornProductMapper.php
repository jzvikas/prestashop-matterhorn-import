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
    ) {}

    public function map(array $row): ProductData
    {
        $sourceKey = trim((string) ($row['id'] ?? ''));
        if ($sourceKey === '' || !ctype_digit($sourceKey)) {
            throw new \InvalidArgumentException('Matterhorn product id must be a non-empty numeric value');
        }
        $reference = 'MH-' . $sourceKey;
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') { throw new \InvalidArgumentException('Matterhorn product ' . $sourceKey . ' is missing name'); }
        if (mb_strlen($name, 'UTF-8') > 128) { $name = mb_substr($name, 0, 128, 'UTF-8'); }

        $priceRaw = trim((string) ($row['price'] ?? ''));
        if ($priceRaw === '' || !is_numeric($priceRaw)) { throw new \InvalidArgumentException('Matterhorn product ' . $sourceKey . ' has invalid price'); }
        $price = (float) $priceRaw;
        if (!is_finite($price) || $price < 0.0) { throw new \InvalidArgumentException('Matterhorn product ' . $sourceKey . ' has invalid price'); }

        $images = [];
        $seenImages = [];
        foreach ((array) ($row['images'] ?? []) as $urlRaw) {
            $url = trim((string) $urlRaw);
            if ($url === '' || isset($seenImages[$url])) { continue; }
            $parts = parse_url($url);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if (!in_array($scheme, ['http', 'https'], true)) { throw new \InvalidArgumentException('Matterhorn product ' . $sourceKey . ' contains non-HTTP image URL'); }
            $seenImages[$url] = true;
            $images[] = $url;
        }

        $categoryAutoCreate = $this->configurationBool('MATTERHORNIMPORT_CATEGORY_AUTO_CREATE', true);
        $featureAutoCreate = $this->configurationBool('MATTERHORNIMPORT_FEATURE_AUTO_CREATE', true);
        $sourceLanguageId = $this->configuredSourceLanguageId();

        $extra = ['description' => $this->html->sanitize((string) ($row['description'] ?? ''))];
        if ($sourceLanguageId > 0) { $extra['source_language_id'] = $sourceLanguageId; }
        $brand = trim((string) ($row['brand'] ?? ''));
        if ($brand !== '') { $extra['manufacturer'] = ['name' => $brand, 'auto_create' => true]; }

        $category = is_array($row['category'] ?? null) ? $row['category'] : [];
        $categoryId = trim((string) ($category['id'] ?? ''));
        $categoryName = trim((string) ($category['name'] ?? ''));
        $categoryPath = $this->categories->normalize((string) ($row['category_path'] ?? ''));
        if ($categoryId !== '') {
            $extra['categories'] = [[
                'key' => $this->categories->key($categoryId),
                'name' => $categoryName !== '' ? $categoryName : $categoryId,
                'path' => $categoryPath !== '' ? $categoryPath : ($categoryName !== '' ? $categoryName : $categoryId),
                'auto_create' => $categoryAutoCreate,
            ]];
        }

        $features = [];
        foreach (['color' => 'Color', 'type' => 'Type'] as $rawKey => $displayName) {
            $value = trim((string) ($row[$rawKey] ?? ''));
            if ($value === '') { continue; }
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
            $extra['features_auto_create'] = $featureAutoCreate;
        }

        $options = is_array($row['options'] ?? null) ? array_values($row['options']) : [];
        $prepared = [];
        $seenOptionIds = [];
        $seenSizes = [];
        foreach ($options as $index => $option) {
            if (!is_array($option)) { throw new \InvalidArgumentException('Matterhorn option #' . ($index + 1) . ' is invalid for product ' . $sourceKey); }
            $optionId = trim((string) ($option['id'] ?? ''));
            $size = trim((string) ($option['name'] ?? ''));
            if ($optionId === '') { throw new \InvalidArgumentException('Matterhorn product ' . $sourceKey . ' contains option without id'); }
            if (isset($seenOptionIds[$optionId])) { throw new \InvalidArgumentException('Duplicate Matterhorn option id ' . $optionId . ' for product ' . $sourceKey); }
            $seenOptionIds[$optionId] = true;
            if ($size === '') { throw new \InvalidArgumentException('Matterhorn option ' . $optionId . ' has empty size for product ' . $sourceKey); }

            $attribute = $this->sizes->attribute($size);
            $semantic = (string) ($attribute['value_key'] ?? '');
            if ($semantic === '') { throw new \RuntimeException('Matterhorn Size resolver returned empty semantic key for ' . $size); }
            if (isset($seenSizes[$semantic])) { throw new \InvalidArgumentException('Duplicate semantic size ' . $size . ' for Matterhorn product ' . $sourceKey); }
            $seenSizes[$semantic] = true;

            $stockRaw = trim((string) ($option['stock'] ?? '0'));
            $stock = filter_var($stockRaw, FILTER_VALIDATE_INT);
            if ($stock === false) { throw new \InvalidArgumentException('Matterhorn option ' . $optionId . ' has invalid stock for product ' . $sourceKey); }
            $stock = max(0, (int) $stock);
            $ean = trim((string) ($option['ean'] ?? ''));
            if ($ean !== '' && !preg_match('/^\d{13}$/D', $ean)) { $ean = ''; }

            $prepared[] = [
                'attribute' => $attribute,
                'reference' => $optionId,
                'quantity' => $stock,
                'ean13' => $ean,
            ];
        }

        usort($prepared, static fn(array $a, array $b): int => strcmp((string) $a['reference'], (string) $b['reference']));
        if ($prepared !== []) {
            $extra['combinations'] = [];
            foreach ($prepared as $index => $item) {
                $extra['combinations'][] = [
                    'attributes' => [$item['attribute']],
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
                ];
            }
            $extra['combinations_authoritative'] = true;
            $extra['combination_attributes_auto_create'] = true;
        }

        return new ProductData($sourceKey, $reference, ['default' => $name], $price, 0, true, $images, $extra);
    }

    private function configuredSourceLanguageId(): int
    {
        $context = $this->configurationContext();
        if ($context === null) { return 0; }
        [$shopId, $groupId] = $context;
        $configured = (int) \Configuration::get('MATTERHORNIMPORT_SOURCE_LANGUAGE_ID', null, $groupId, $shopId);
        if ($configured > 0) { return $configured; }
        $fallback = (int) \Configuration::get('PS_LANG_DEFAULT', null, $groupId, $shopId);
        if ($fallback <= 0) { $fallback = (int) \Configuration::get('PS_LANG_DEFAULT'); }
        return max(0, $fallback);
    }

    private function configurationBool(string $key, bool $default): bool
    {
        $context = $this->configurationContext();
        if ($context === null) { return $default; }
        [$shopId, $groupId] = $context;
        $raw = \Configuration::get($key, null, $groupId, $shopId);
        if ($raw === false || $raw === null || $raw === '') { return $default; }
        return (int) $raw !== 0;
    }

    /** @return array{0:int,1:int}|null */
    private function configurationContext(): ?array
    {
        // Keep Source/Mapper unit usage PrestaShop-independent. READ activates the concrete
        // shop before map(), so production runs resolve shop-scoped policy here.
        if (!class_exists('Context', false) || !class_exists('Configuration', false)) { return null; }
        try {
            $shop = \Context::getContext()->shop ?? null;
            if (!$shop instanceof \Shop || (int) $shop->id <= 0) { return null; }
            return [(int) $shop->id, max(0, (int) $shop->id_shop_group)];
        } catch (\Throwable) {
            return null;
        }
    }

    private function identity(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? $value;
        return trim($value, '-');
    }
}
