<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Mapper\MatterhornProductMapper;
use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
use Lp\MatterhornImport\Matterhorn\MatterhornHtmlSanitizer;
use Lp\MatterhornImport\Matterhorn\MatterhornSizeResolver;
use Lp\MatterhornImport\Source\MatterhornXmlSource;

function updateFixtureCheck(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$mapper = new MatterhornProductMapper(
    new MatterhornSizeResolver(),
    new MatterhornCategoryPathNormalizer(),
    new MatterhornHtmlSanitizer()
);

$load = static function (string $path) use ($mapper): array {
    $products = [];
    foreach ((new MatterhornXmlSource($path))->rows() as $row) {
        $product = $mapper->map($row);
        $products[$product->sourceKey] = $product;
    }
    return $products;
};

$before = $load(__DIR__ . '/fixtures/matterhorn-sample.xml');
$after = $load(__DIR__ . '/fixtures/matterhorn-sample-updated.xml');

updateFixtureCheck(count($before) === 3, 'baseline fixture must contain three products');
updateFixtureCheck(count($after) === 2, 'updated fixture must contain two products');
updateFixtureCheck(isset($before['228723']) && !isset($after['228723']), 'updated fixture must exercise out-of-feed removal for 228723');
updateFixtureCheck(isset($before['206161'], $after['206161'], $before['34375'], $after['34375']), 'required lifecycle products missing');

$old = $before['206161'];
$new = $after['206161'];
updateFixtureCheck($old->price === 14.9 && $new->price === 15.9, '206161 must exercise a price-only domain change');
updateFixtureCheck(($old->extra['combinations'][0]['quantity'] ?? null) === 2, 'baseline XS stock must be 2');
updateFixtureCheck(($new->extra['combinations'][0]['quantity'] ?? null) === 7, 'updated XS stock must be 7');
updateFixtureCheck($old->priceHash() !== $new->priceHash(), 'price hash must change');
updateFixtureCheck($old->combinationStockHash() !== $new->combinationStockHash(), 'combination_stock hash must change');
foreach (['core','stock','attribute','feature','category','combination','specific_price','image'] as $domain) {
    updateFixtureCheck(
        $old->domainHashes()[$domain] === $new->domainHashes()[$domain],
        '206161 ' . $domain . ' hash must remain stable in changed fixture'
    );
}

$unchangedBefore = $before['34375'];
$unchangedAfter = $after['34375'];
updateFixtureCheck($unchangedBefore->payloadHash() === $unchangedAfter->payloadHash(), '34375 must remain byte-semantically unchanged');
updateFixtureCheck($unchangedBefore->domainHashes() === $unchangedAfter->domainHashes(), '34375 domain hashes must remain unchanged');

echo "Matterhorn changed-feed fixture checks: OK\n";
