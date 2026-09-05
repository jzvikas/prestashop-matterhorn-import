<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Mapper\MatterhornProductMapper;
use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
use Lp\MatterhornImport\Matterhorn\MatterhornHtmlSanitizer;
use Lp\MatterhornImport\Matterhorn\MatterhornSizeResolver;
use Lp\MatterhornImport\Source\MatterhornXmlSource;

function check(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$fixture = __DIR__ . '/fixtures/matterhorn-sample.xml';
$source = new MatterhornXmlSource($fixture);
$rows = iterator_to_array($source->rows(), false);
check(count($rows) === 3, 'parser must stream exactly three fixture products');
check($rows[0]['id'] === '206161', 'first product id');
check(count($rows[0]['images']) === 4, 'duplicate image URL must be removed while preserving order');
check(count($rows[0]['options']) === 2, 'nested options must be parsed');
check($rows[0]['options'][0]['available_in'] === '3', 'avaible_in stays raw supplier metadata');

$resumed = iterator_to_array($source->rowsFrom(1), false);
check(count($resumed) === 2 && $resumed[0]['id'] === '34375', 'record checkpoint resume');
check(hash_equals($source->fingerprint(), $source->fingerprint()), 'source fingerprint must be deterministic');

$mapper = new MatterhornProductMapper(new MatterhornSizeResolver(), new MatterhornCategoryPathNormalizer(), new MatterhornHtmlSanitizer());
$product = $mapper->map($rows[0]);
check($product->sourceKey === '206161', 'supplier id must be source key');
check($product->reference === 'MH-206161', 'product reference prefix');
check($product->price === 14.9, 'price mapping');
check($product->quantity === 0, 'variant product base quantity stays zero');
check(($product->extra['manufacturer']['name'] ?? '') === 'Axami', 'manufacturer mapping');
check(($product->extra['categories'][0]['key'] ?? '') === 'matterhorn-category:3', 'stable category key');
check(($product->extra['categories'][0]['path'] ?? '') === 'WOMEN > Women`s Lingerie > Knickers, Slips, Thongs > Briefs', 'category path normalization');
check(count($product->extra['features'] ?? []) === 2, 'Color/Type feature mapping');
check(count($product->extra['combinations'] ?? []) === 2, 'combination mapping');
$first = $product->extra['combinations'][0];
check(($first['reference'] ?? '') === 'M1188149', 'combination supplier option reference');
check(($first['attributes'][0]['group_key'] ?? '') === 'matterhorn:size', 'Size group supplier key');
check(($first['attributes'][0]['value_key'] ?? '') === 'matterhorn:size:xs', 'Size value supplier key');
check(($first['quantity'] ?? -1) === 2, 'combination stock');
check(($first['ean13'] ?? '') === '5902934981668', 'combination EAN');
check(($first['matterhorn_available_in'] ?? '') === '3', 'avaible_in must survive mapping as supplier metadata');
check(($first['default'] ?? false) === true, 'stable sorted first option is default');
check(!class_exists('Context', false), 'pure READ mapper must not require PrestaShop Context');

$stockExtra = $product->extra;
$stockExtra['combinations'][0]['quantity'] = 7;
$stockChanged = new ProductData($product->sourceKey, $product->reference, $product->name, $product->price, $product->quantity, $product->active, $product->images, $stockExtra);
check($product->combinationStockHash() !== $stockChanged->combinationStockHash(), 'stock change changes combination_stock hash');
foreach (['core','price','stock','attribute','feature','category','combination','specific_price','image'] as $domain) {
    check($product->domainHashes()[$domain] === $stockChanged->domainHashes()[$domain], 'stock change leaves ' . $domain . ' unchanged');
}

$availabilityRow = $rows[0];
$availabilityRow['options'][0]['available_in'] = '9';
$availabilityChanged = $mapper->map($availabilityRow);
check(($availabilityChanged->extra['combinations'][0]['matterhorn_available_in'] ?? '') === '9', 'changed avaible_in metadata must be preserved');
check($product->payloadHash() !== $availabilityChanged->payloadHash(), 'avaible_in metadata change must remain observable in payload hash');
check($product->domainHashes() === $availabilityChanged->domainHashes(), 'avaible_in metadata must not affect catalog domain hashes');

$priceChanged = new ProductData($product->sourceKey, $product->reference, $product->name, 15.9, $product->quantity, $product->active, $product->images, $product->extra);
check($product->priceHash() !== $priceChanged->priceHash(), 'price change changes price hash');
check($product->coreHash() === $priceChanged->coreHash(), 'price change leaves core hash unchanged');
check($product->combinationHash() === $priceChanged->combinationHash(), 'price change leaves combinations unchanged');

$blankEanRow = $rows[0];
$blankEanRow['options'][0]['ean'] = '';
$blankEan = $mapper->map($blankEanRow);
$badEanRow = $rows[0];
$badEanRow['options'][0]['ean'] = 'BAD-EAN';
$badEan = $mapper->map($badEanRow);
check(($badEan->extra['combinations'][0]['ean13'] ?? 'x') === '', 'malformed optional EAN is blank, not fatal');
check(count($badEan->extra['supplier_warnings'] ?? []) === 1 && str_contains((string) $badEan->extra['supplier_warnings'][0], 'invalid optional EAN13'), 'malformed optional EAN must be observable');
check($badEan->payloadHash() !== $blankEan->payloadHash(), 'warning metadata must remain observable in payload hash');
check($badEan->domainHashes() === $blankEan->domainHashes(), 'warning-only malformed EAN normalization must not dirty catalog domains');

$nonHttpImageRow = $rows[0];
$nonHttpImageRow['images'][] = 'ftp://supplier.invalid/image.jpg';
$nonHttpImageRow['images'][] = 'ftp://supplier.invalid/image.jpg';
$nonHttpImage = $mapper->map($nonHttpImageRow);
check($nonHttpImage->images === $product->images, 'non-HTTP supplier image must be skipped without changing desired valid manifest');
check(count($nonHttpImage->extra['supplier_warnings'] ?? []) === 1 && str_contains((string) $nonHttpImage->extra['supplier_warnings'][0], 'non-HTTP URL was skipped'), 'non-HTTP supplier image must be observable once even when duplicated');
check($nonHttpImage->payloadHash() !== $product->payloadHash(), 'invalid-image warning must remain observable in payload hash');
check($nonHttpImage->domainHashes() === $product->domainHashes(), 'skipped invalid image URL must not dirty catalog domains');

$oversizedImageRow = $rows[0];
$oversizedImageRow['images'][] = 'https://supplier.invalid/' . str_repeat('a', 16384);
$oversizedImage = $mapper->map($oversizedImageRow);
check($oversizedImage->images === $product->images, 'oversized supplier image URL must be skipped without changing desired valid manifest');
check(count($oversizedImage->extra['supplier_warnings'] ?? []) === 1 && str_contains((string) $oversizedImage->extra['supplier_warnings'][0], 'exceeds 16384 bytes'), 'oversized supplier image URL must be observable');
check($oversizedImage->payloadHash() !== $product->payloadHash(), 'oversized-image warning must remain observable in payload hash');
check($oversizedImage->domainHashes() === $product->domainHashes(), 'skipped oversized image URL must not dirty catalog domains');

$zeroStockRow = $rows[0];
$zeroStockRow['options'][0]['stock'] = '0';
$zeroStock = $mapper->map($zeroStockRow);
$negativeStockRow = $rows[0];
$negativeStockRow['options'][0]['stock'] = '-4';
$negativeStock = $mapper->map($negativeStockRow);
check(($negativeStock->extra['combinations'][0]['quantity'] ?? -1) === 0, 'negative stock must normalize to zero');
check(count($negativeStock->extra['supplier_warnings'] ?? []) === 1 && str_contains((string) $negativeStock->extra['supplier_warnings'][0], 'negative stock'), 'negative stock normalization must be observable');
check($negativeStock->payloadHash() !== $zeroStock->payloadHash(), 'negative-stock warning must remain observable in payload hash');
check($negativeStock->domainHashes() === $zeroStock->domainHashes(), 'warning-only negative stock normalization must not dirty catalog domains');

$duplicateId = $rows[0]; $duplicateId['options'][1]['id'] = $duplicateId['options'][0]['id'];
try { $mapper->map($duplicateId); check(false, 'duplicate option id must fail'); }
catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(), 'Duplicate Matterhorn option id'), 'duplicate option id error clarity'); }

$duplicateSemantic = $rows[0]; $duplicateSemantic['options'][1]['name'] = 'XS';
try { $mapper->map($duplicateSemantic); check(false, 'duplicate semantic size must fail'); }
catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(), 'Duplicate semantic size'), 'duplicate semantic size error clarity'); }

$longProductId = $rows[0];
$longProductId['id'] = str_repeat('9', 62);
try { $mapper->map($longProductId); check(false, 'product reference over 64 bytes must fail in READ'); }
catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(), 'reference exceeds PrestaShop 64-byte limit'), 'product reference bound error clarity'); }

$longOptionReference = $rows[0];
$longOptionReference['options'][0]['id'] = str_repeat('O', 65);
try { $mapper->map($longOptionReference); check(false, 'combination reference over 64 bytes must fail in READ'); }
catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(), 'option reference exceeds PrestaShop 64-byte limit'), 'combination reference bound error clarity'); }

$longManufacturer = $rows[0];
$longManufacturer['brand'] = str_repeat('M', 65);
try { $mapper->map($longManufacturer); check(false, 'manufacturer over 64 characters must fail in READ'); }
catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(), 'manufacturer name exceeds PrestaShop 64-character limit'), 'manufacturer bound error clarity'); }

$longCategoryName = $rows[0];
$longCategoryName['category']['name'] = str_repeat('C', 129);
try { $mapper->map($longCategoryName); check(false, 'category name over 128 characters must fail in READ'); }
catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(), 'category name exceeds PrestaShop 128-character limit'), 'category-name bound error clarity'); }

$longCategoryPath = $rows[0];
$longCategoryPath['category_path'] = '/' . str_repeat('P', 129);
try { $mapper->map($longCategoryPath); check(false, 'category path segment over 128 characters must fail in READ'); }
catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(), 'category path segment exceeds PrestaShop 128-character limit'), 'category-path bound error clarity'); }

$longCategoryId = $rows[0];
$longCategoryId['category']['id'] = str_repeat('K', 180);
try { $mapper->map($longCategoryId); check(false, 'generated category supplier key over 191 characters must fail in READ'); }
catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(), 'category supplier key exceeds module 191-character limit'), 'category-key bound error clarity'); }

$tooLongFeature = $rows[0];
$tooLongFeature['color'] = str_repeat('F', 256);
try { $mapper->map($tooLongFeature); check(false, 'feature value over 255 characters must fail in READ'); }
catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(), 'Color value exceeds PrestaShop 255-character limit'), 'feature value bound error clarity'); }

$longFeature = $rows[0];
$longFeature['color'] = str_repeat('f', 180);
$longFeatureMapped = $mapper->map($longFeature);
$longFeatureRow = $longFeatureMapped->extra['features'][0] ?? [];
$longFeatureKey = (string) ($longFeatureRow['value_key'] ?? '');
check(($longFeatureRow['value'] ?? '') === str_repeat('f', 180), 'long valid feature display value must remain lossless');
check($longFeatureKey !== '' && mb_strlen($longFeatureKey, 'UTF-8') <= 191, 'long feature semantic key must fit module mapping schema');
$longFeatureMappedAgain = $mapper->map($longFeature);
check($longFeatureKey === (string) ($longFeatureMappedAgain->extra['features'][0]['value_key'] ?? ''), 'long feature semantic key must be deterministic');

$punctuationFeature = $rows[0];
$punctuationFeature['color'] = '!!!';
$punctuationFeatureMapped = $mapper->map($punctuationFeature);
$punctuationKey = (string) ($punctuationFeatureMapped->extra['features'][0]['value_key'] ?? '');
check(str_starts_with($punctuationKey, 'matterhorn:color:hash-'), 'punctuation-only feature value must use non-empty hash identity');

$simple = $rows[0]; $simple['options'] = [];
$simpleMapped = $mapper->map($simple);
check($simpleMapped->quantity === 0 && !isset($simpleMapped->extra['combinations']), 'simple product has zero stock and no invented combinations');

$html = new MatterhornHtmlSanitizer();
$clean = $html->sanitize('<div class="ok" onclick="evil()"><strong>Safe</strong><script>alert(1)</script><iframe src="x"></iframe></div>');
check(!str_contains(strtolower($clean), '<script') && !str_contains(strtolower($clean), '<iframe') && !str_contains(strtolower($clean), 'onclick'), 'description sanitizer strips unsafe markup');
check(str_contains($clean, '<strong>Safe</strong>'), 'description sanitizer preserves safe formatting');

echo "Matterhorn parser/mapper checks: OK\n";
