<?php
namespace Lp\MatterhornImport\Mapper;

use Lp\MatterhornImport\Config\MatterhornPolicy;
use Lp\MatterhornImport\Contract\ProductMapperInterface;
use Lp\MatterhornImport\Contract\SizeResolverInterface;
use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
use Lp\MatterhornImport\Matterhorn\MatterhornHtmlSanitizer;

final class MatterhornProductMapper implements ProductMapperInterface
{
    private const MAX_IMAGE_URL_BYTES = 16384;
    private const MAX_PRESTASHOP_REFERENCE_BYTES = 64;
    private const MAX_PRESTASHOP_STOCK = 2147483647;
    private const PRESTASHOP_PRICE_PATTERN = '/^[0-9]{1,10}(?:\.[0-9]{1,9})?$/D';
    private const PRESTASHOP_CATALOG_TEXT_PATTERN = '/^[^<>{}]*$/u';
    private const MAX_PRODUCT_NAME_CHARS = 128;
    private const MAX_MANUFACTURER_NAME_CHARS = 64;
    private const MAX_CATEGORY_NAME_CHARS = 128;
    private const MAX_FEATURE_VALUE_CHARS = 255;
    private const MAX_FEATURE_IDENTITY_CHARS = 160;

    public function __construct(
        private readonly SizeResolverInterface $sizes,
        private readonly MatterhornCategoryPathNormalizer $categories,
        private readonly MatterhornHtmlSanitizer $html,
        private readonly ?MatterhornPolicy $policy = null,
    ) {}

    public function map(array $row): ProductData
    {
        $sourceKey = trim((string) ($row['id'] ?? ''));
        if ($sourceKey === '' || !ctype_digit($sourceKey)) {
            throw new \InvalidArgumentException('Matterhorn product id must be a non-empty numeric value');
        }
        $reference = 'MH-' . $sourceKey;
        if (strlen($reference) > self::MAX_PRESTASHOP_REFERENCE_BYTES) {
            throw new \InvalidArgumentException(
                'Matterhorn product ' . $sourceKey . ' reference exceeds PrestaShop ' .
                self::MAX_PRESTASHOP_REFERENCE_BYTES . '-byte limit'
            );
        }
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') { throw new \InvalidArgumentException('Matterhorn product ' . $sourceKey . ' is missing name'); }
        $this->assertCatalogText($name, 'product name', $sourceKey);
        if (mb_strlen($name, 'UTF-8') > self::MAX_PRODUCT_NAME_CHARS) {
            throw new \InvalidArgumentException(
                'Matterhorn product name exceeds PrestaShop ' . self::MAX_PRODUCT_NAME_CHARS .
                '-character limit for product ' . $sourceKey
            );
        }

        $priceRaw = trim((string) ($row['price'] ?? ''));
        if ($priceRaw === '' || !is_numeric($priceRaw)) { throw new \InvalidArgumentException('Matterhorn product ' . $sourceKey . ' has invalid price'); }
        $price = (float) $priceRaw;
        if (!is_finite($price) || $price < 0.0) { throw new \InvalidArgumentException('Matterhorn product ' . $sourceKey . ' has invalid price'); }
        if (!preg_match(self::PRESTASHOP_PRICE_PATTERN, (string) $price)) {
            throw new \InvalidArgumentException(
                'Matterhorn product ' . $sourceKey . ' price cannot be represented by the PrestaShop price validator'
            );
        }

        $warnings = [];
        foreach ((array) ($row['supplier_warnings'] ?? []) as $warningRaw) {
            $warning = trim((string) $warningRaw);
            if ($warning !== '') { $warnings[] = mb_substr($warning, 0, 1000, 'UTF-8'); }
        }
        $images = [];
        $seenImages = [];
        foreach ((array) ($row['images'] ?? []) as $index => $urlRaw) {
            $url = trim((string) $urlRaw);
            if ($url === '' || isset($seenImages[$url])) { continue; }
            $seenImages[$url] = true;
            if (strlen($url) > self::MAX_IMAGE_URL_BYTES) {
                $warnings[] = 'image #' . ((int) $index + 1) . ' URL exceeds ' . self::MAX_IMAGE_URL_BYTES . ' bytes and was skipped';
                continue;
            }
            $parts = parse_url($url);
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            if (!in_array($scheme, ['http', 'https'], true)) {
                $warnings[] = 'image #' . ((int) $index + 1) . ' non-HTTP URL was skipped';
                continue;
            }
            $images[] = $url;
        }

        $policy = ($this->policy ?? new MatterhornPolicy())->current();
        $categoryAutoCreate = (bool) $policy['category_auto_create'];
        $featureAutoCreate = (bool) $policy['feature_auto_create'];
        $sourceLanguageId = (int) $policy['source_language_id'];

        $extra = ['description' => $this->html->sanitize((string) ($row['description'] ?? ''))];
        $creationDate = trim((string) ($row['creation_date'] ?? ''));
        if ($creationDate !== '') {
            $extra['supplier_metadata'] = ['creation_date' => $creationDate];
        }
        if ($sourceLanguageId > 0) { $extra['source_language_id'] = $sourceLanguageId; }
        $brand = trim((string) ($row['brand'] ?? ''));
        if ($brand !== '') {
            $this->assertCatalogText($brand, 'manufacturer name', $sourceKey);
            if (mb_strlen($brand, 'UTF-8') > self::MAX_MANUFACTURER_NAME_CHARS) {
                throw new \InvalidArgumentException(
                    'Matterhorn manufacturer name exceeds PrestaShop ' . self::MAX_MANUFACTURER_NAME_CHARS .
                    '-character limit for product ' . $sourceKey
                );
            }
            $extra['manufacturer'] = ['name' => $brand, 'auto_create' => true];
        }

        $category = is_array($row['category'] ?? null) ? $row['category'] : [];
        $categoryId = trim((string) ($category['id'] ?? ''));
        $categoryName = trim((string) ($category['name'] ?? ''));
        if ($categoryName !== '') {
            $this->assertCatalogText($categoryName, 'category name', $sourceKey);
            if (mb_strlen($categoryName, 'UTF-8') > self::MAX_CATEGORY_NAME_CHARS) {
                throw new \InvalidArgumentException(
                    'Matterhorn category name exceeds PrestaShop ' . self::MAX_CATEGORY_NAME_CHARS .
                    '-character limit for product ' . $sourceKey
                );
            }
        }
        $categoryPath = $this->categories->normalize((string) ($row['category_path'] ?? ''));
        if ($categoryId !== '') {
            if ($categoryName === '' && $categoryPath === '') {
                $this->assertCatalogText($categoryId, 'category fallback name', $sourceKey);
                if (mb_strlen($categoryId, 'UTF-8') > self::MAX_CATEGORY_NAME_CHARS) {
                    throw new \InvalidArgumentException(
                        'Matterhorn category fallback name exceeds PrestaShop ' . self::MAX_CATEGORY_NAME_CHARS .
                        '-character limit for product ' . $sourceKey
                    );
                }
            }
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
            $this->assertCatalogText($value, $displayName . ' feature value', $sourceKey);
            if (mb_strlen($value, 'UTF-8') > self::MAX_FEATURE_VALUE_CHARS) {
                throw new \InvalidArgumentException(
                    'Matterhorn ' . $displayName . ' value exceeds PrestaShop ' .
                    self::MAX_FEATURE_VALUE_CHARS . '-character limit for product ' . $sourceKey
                );
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
            if (strlen($optionId) > self::MAX_PRESTASHOP_REFERENCE_BYTES) {
                throw new \InvalidArgumentException(
                    'Matterhorn option reference exceeds PrestaShop ' . self::MAX_PRESTASHOP_REFERENCE_BYTES .
                    '-byte limit for product ' . $sourceKey
                );
            }
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
            if ((int) $stock > self::MAX_PRESTASHOP_STOCK) {
                throw new \InvalidArgumentException(
                    'Matterhorn option ' . $optionId . ' stock exceeds PrestaShop maximum of ' .
                    self::MAX_PRESTASHOP_STOCK . ' for product ' . $sourceKey
                );
            }
            if ((int) $stock < 0) {
                $warnings[] = 'option ' . $optionId . ' negative stock ' . (int) $stock . ' normalized to 0';
            }
            $stock = max(0, (int) $stock);
            $ean = trim((string) ($option['ean'] ?? ''));
            if ($ean !== '' && !preg_match('/^\d{13}$/D', $ean)) {
                $warnings[] = 'option ' . $optionId . ' invalid optional EAN13 normalized to blank';
                $ean = '';
            }

            $prepared[] = [
                'attribute' => $attribute,
                'reference' => $optionId,
                'quantity' => $stock,
                'ean13' => $ean,
                'matterhorn_available_in' => trim((string) ($option['available_in'] ?? '')),
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
                    'matterhorn_available_in' => $item['matterhorn_available_in'],
                ];
            }
            $extra['combinations_authoritative'] = true;
            $extra['combination_attributes_auto_create'] = true;
        }
        if ($warnings !== []) {
            $warnings = array_values(array_unique($warnings));
            sort($warnings, SORT_STRING);
            $extra['supplier_warnings'] = $warnings;
        }

        return new ProductData($sourceKey, $reference, ['default' => $name], $price, 0, true, $images, $extra);
    }

    private function assertCatalogText(string $value, string $label, string $sourceKey): void
    {
        if (preg_match(self::PRESTASHOP_CATALOG_TEXT_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException(
                'Matterhorn ' . $label . ' contains characters rejected by PrestaShop (<, >, {, }) for product ' . $sourceKey
            );
        }
    }

    private function identity(string $value): string
    {
        $source = mb_strtolower(trim($value), 'UTF-8');
        $identity = preg_replace('/[^\p{L}\p{N}]+/u', '-', $source) ?? $source;
        $identity = trim($identity, '-');
        if ($identity === '') {
            return 'hash-' . substr(hash('sha256', $source), 0, 32);
        }
        if (mb_strlen($identity, 'UTF-8') > self::MAX_FEATURE_IDENTITY_CHARS) {
            return mb_substr($identity, 0, 120, 'UTF-8') . '-' . substr(hash('sha256', $identity), 0, 32);
        }
        return $identity;
    }
}
