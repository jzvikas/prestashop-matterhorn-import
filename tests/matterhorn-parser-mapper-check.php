<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Lp\MatterhornImport\Contract\SizeResolverInterface;
use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Mapper\MatterhornProductMapper;
use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
use Lp\MatterhornImport\Matterhorn\MatterhornHtmlSanitizer;
use Lp\MatterhornImport\Source\MatterhornXmlSource;

final class FixtureSizeResolver implements SizeResolverInterface
{
    private array $ids = ['XS' => 101, 'M' => 102, 'S/M' => 103, 'L/XL' => 104];
    public function resolve(string $size): int
    {
        return $this->ids[$size] ?? throw new RuntimeException('Unknown fixture size: ' . $size);
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$fixture = __DIR__ . '/fixtures/matterhorn-sample.xml';
$source = new MatterhornXmlSource($fixture);
$rows = iterator_to_array($source->rows(), false);
check(count($rows) === 3, 'parser must stream exactly three fixture products');
check($rows[0]['id'] === '206161', 'first product id');
check(count($rows[0]['images']) === 4, 'duplicate image URL must be removed while preserving order');
check($rows[0]['images'][0] === 'https://matterhorn-wholesale.com/pics_source/1057030.jpg', 'first image order');
check(count($rows[0]['options']) === 2, 'nested options must be parsed');
check($rows[0]['options'][0]['id'] === 'M1188149', 'option id');
check($rows[0]['options'][0]['name'] === 'XS', 'option size');
check($rows[0]['options'][0]['stock'] === '2', 'option stock raw value');
check($rows[0]['options'][0]['ean'] === '5902934981668', 'option EAN');

$resumed = iterator_to_array($source->rowsFrom(1), false);
check(count($resumed) === 2 && $resumed[0]['id'] === '34375', 'record checkpoint resume');
check(hash_equals($source->fingerprint(), $source->fingerprint()), 'source fingerprint must be deterministic');

$categories = new MatterhornCategoryPathNormalizer();
$html = new MatterhornHtmlSanitizer();
$mapper = new MatterhornProductMapper(new FixtureSizeResolver(), $categories, $html);
$product = $mapper->map($rows[0]);
check($product->sourceKey === '206161', 'supplier id must be source key');
check($product->reference === 'MH-206161', 'product reference prefix');
check($product->price === 14.9, 'price mapping');
check($product->quantity === 0, 'variant product base quantity stays zero');
check(($product->extra['manufacturer']['name'] ?? '') === 'Axami', 'manufacturer mapping');
check(($product->extra['categories'][0]['key'] ?? '') === 'matterhorn-category:3', 'stable category key');
check(($product->extra['categories'][0]['path'] ?? '') === 'WOMEN > Women`s Lingerie > Knickers, Slips, Thongs > Briefs', 'category path normalization');
check(($product->extra['features'][0]['key'] ?? '') === 'matterhorn:color', 'Color feature mapping');
check(($product->extra['features'][1]['key'] ?? '') === 'matterhorn:type', 'Type feature mapping');
check(count($product->extra['combinations'] ?? []) === 2, 'combination mapping');
check(($product->extra['combinations'][0]['reference'] ?? '') === 'M1188149', 'combination supplier option reference');
check(($product->extra['combinations'][0]['attribute_ids'][0] ?? 0) === 101, 'resolved numeric size attribute');
check(($product->extra['combinations'][0]['quantity'] ?? -1) === 2, 'combination stock');
check(($product->extra['combinations'][0]['ean13'] ?? '') === '5902934981668', 'combination EAN');
check(($product->extra['combinations'][0]['default'] ?? false) === true, 'stable sorted first option is default');

$stockExtra = $product->extra;
$stockExtra['combinations'][0]['quantity'] = 7;
$stockChanged = new ProductData(
    $product->sourceKey, $product->reference, $product->name, $product->price,
    $product->quantity, $product->active, $product->images, $stockExtra
);
check($product->combinationStockHash() !== $stockChanged->combinationStockHash(), 'stock change must change combination_stock hash');
check($product->combinationHash() === $stockChanged->combinationHash(), 'stock change must not change combination structure hash');
check($product->coreHash() === $stockChanged->coreHash(), 'stock change must not change core hash');
check($product->priceHash() === $stockChanged->priceHash(), 'stock change must not change price hash');
check($product->featureHash() === $stockChanged->featureHash(), 'stock change must not change feature hash');
check($product->categoryHash() === $stockChanged->categoryHash(), 'stock change must not change category hash');
check($product->imageHash() === $stockChanged->imageHash(), 'stock change must not change image hash');

$priceChanged = new ProductData(
    $product->sourceKey, $product->reference, $product->name, 15.9,
    $product->quantity, $product->active, $product->images, $product->extra
);
check($product->priceHash() !== $priceChanged->priceHash(), 'price change must change price hash');
check($product->coreHash() === $priceChanged->coreHash(), 'price change must not change core hash');
check($product->combinationHash() === $priceChanged->combinationHash(), 'price change must not change combinations');

$badEanRow = $rows[0];
$badEanRow['options'][0]['ean'] = 'BAD-EAN';
$badEan = $mapper->map($badEanRow);
check(($badEan->extra['combinations'][0]['ean13'] ?? 'x') === '', 'malformed optional EAN must be blank, not fatal');

$duplicateId = $rows[0];
$duplicateId['options'][1]['id'] = $duplicateId['options'][0]['id'];
try {
    $mapper->map($duplicateId);
    check(false, 'duplicate option id must fail');
} catch (InvalidArgumentException $e) {
    check(str_contains($e->getMessage(), 'Duplicate Matterhorn option id'), 'duplicate option id error clarity');
}

$duplicateSemantic = $rows[0];
$duplicateSemantic['options'][1]['name'] = 'XS';
try {
    $mapper->map($duplicateSemantic);
    check(false, 'duplicate semantic size must fail');
} catch (InvalidArgumentException $e) {
    check(str_contains($e->getMessage(), 'Duplicate semantic size'), 'duplicate semantic size error clarity');
}

$simple = $rows[0];
$simple['options'] = [];
$simpleMapped = $mapper->map($simple);
check($simpleMapped->quantity === 0, 'product with no option gets zero quantity when no product-level stock exists');
check(!isset($simpleMapped->extra['combinations']), 'product with no options must not invent combinations');

$unsafe = '<div class="ok" onclick="evil()"><strong>Safe</strong><script>alert(1)</script><iframe src="x"></iframe></div>';
$clean = $html->sanitize($unsafe);
check(!str_contains(strtolower($clean), '<script'), 'description sanitizer removes scripts');
check(!str_contains(strtolower($clean), '<iframe'), 'description sanitizer removes iframes');
check(!str_contains(strtolower($clean), 'onclick'), 'description sanitizer removes event attributes');
check(str_contains($clean, '<strong>Safe</strong>'), 'description sanitizer preserves safe formatting');

echo "Matterhorn parser/mapper checks: OK\n";
