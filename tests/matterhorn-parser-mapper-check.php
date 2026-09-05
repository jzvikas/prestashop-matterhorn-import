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
check(($first['default'] ?? false) === true, 'stable sorted first option is default');
check(!class_exists('Context', false), 'pure READ mapper must not require PrestaShop Context');

$stockExtra = $product->extra;
$stockExtra['combinations'][0]['quantity'] = 7;
$stockChanged = new ProductData($product->sourceKey, $product->reference, $product->name, $product->price, $product->quantity, $product->active, $product->images, $stockExtra);
check($product->combinationStockHash() !== $stockChanged->combinationStockHash(), 'stock change changes combination_stock hash');
foreach (['core','price','stock','attribute','feature','category','combination','specific_price','image'] as $domain) {
    check($product->domainHashes()[$domain] === $stockChanged->domainHashes()[$domain], 'stock change leaves ' . $domain . ' unchanged');
}

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

$simple = $rows[0]; $simple['options'] = [];
$simpleMapped = $mapper->map($simple);
check($simpleMapped->quantity === 0 && !isset($simpleMapped->extra['combinations']), 'simple product has zero stock and no invented combinations');

$html = new MatterhornHtmlSanitizer();
$clean = $html->sanitize('<div class="ok" onclick="evil()"><strong>Safe</strong><script>alert(1)</script><iframe src="x"></iframe></div>');
check(!str_contains(strtolower($clean), '<script') && !str_contains(strtolower($clean), '<iframe') && !str_contains(strtolower($clean), 'onclick'), 'description sanitizer strips unsafe markup');
check(str_contains($clean, '<strong>Safe</strong>'), 'description sanitizer preserves safe formatting');

echo "Matterhorn parser/mapper checks: OK\n";
