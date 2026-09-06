<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Mapper\MatterhornProductMapper;
use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
use Lp\MatterhornImport\Matterhorn\MatterhornHtmlSanitizer;
use Lp\MatterhornImport\Matterhorn\MatterhornSizeResolver;
use Lp\MatterhornImport\Source\MatterhornXmlSource;

function catalogTextCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function expectCatalogTextFailure(callable $call, string $needle): void
{
    try {
        $call();
        catalogTextCheck(false, 'expected catalog text failure containing: ' . $needle);
    } catch (InvalidArgumentException $e) {
        catalogTextCheck(
            str_contains($e->getMessage(), $needle),
            'unexpected catalog text error: ' . $e->getMessage()
        );
    }
}

$normalizer = new MatterhornCategoryPathNormalizer();
$resolver = new MatterhornSizeResolver();
$mapper = new MatterhornProductMapper($resolver, $normalizer, new MatterhornHtmlSanitizer());
$fixture = __DIR__ . '/fixtures/matterhorn-sample.xml';
$row = iterator_to_array((new MatterhornXmlSource($fixture))->rows(), false)[0] ?? null;
catalogTextCheck(is_array($row), 'Matterhorn fixture row missing');

$badName = $row;
$badName['name'] = 'Bad <Product';
expectCatalogTextFailure(fn() => $mapper->map($badName), 'product name contains characters rejected by PrestaShop');

$badBrand = $row;
$badBrand['brand'] = 'Bad {Brand}';
expectCatalogTextFailure(fn() => $mapper->map($badBrand), 'manufacturer name contains characters rejected by PrestaShop');

$badCategory = $row;
$badCategory['category']['name'] = 'Bad > Category';
expectCatalogTextFailure(fn() => $mapper->map($badCategory), 'category name contains characters rejected by PrestaShop');

$badFeature = $row;
$badFeature['color'] = 'Red}';
expectCatalogTextFailure(fn() => $mapper->map($badFeature), 'Color feature value contains characters rejected by PrestaShop');

$badFallback = $row;
$badFallback['category'] = ['id' => 'bad{id', 'name' => ''];
$badFallback['category_path'] = '';
expectCatalogTextFailure(fn() => $mapper->map($badFallback), 'category fallback name contains characters rejected by PrestaShop');

expectCatalogTextFailure(
    fn() => $normalizer->normalize('/Women/Bad < Segment'),
    'category path segment contains characters rejected by PrestaShop'
);
expectCatalogTextFailure(
    fn() => $resolver->attribute('S<M'),
    'size contains characters rejected by PrestaShop'
);
expectCatalogTextFailure(
    fn() => (new MatterhornSizeResolver(null, 'Bad{Size}'))->attribute('M'),
    'Size group name contains characters rejected by PrestaShop'
);

$allowed = $row;
$allowed['name'] = "Women's A=B; C";
$allowed['brand'] = "O'Neil & Co.";
$allowed['category']['name'] = 'Briefs & Slips';
$allowed['category_path'] = '/Women/Briefs & Slips';
$allowed['color'] = 'Black/White';
$allowed['options'][0]['name'] = 'S/M';
$allowedMapped = $mapper->map($allowed);
catalogTextCheck(
    ($allowedMapped->name['default'] ?? '') === "Women's A=B; C",
    'PrestaShop-compatible punctuation must remain accepted in product names'
);
catalogTextCheck(
    ($allowedMapped->extra['manufacturer']['name'] ?? '') === "O'Neil & Co.",
    'PrestaShop-compatible punctuation must remain accepted in manufacturer names'
);
catalogTextCheck(
    ($allowedMapped->extra['features'][0]['value'] ?? '') === 'Black/White',
    'PrestaShop-compatible punctuation must remain accepted in feature values'
);

echo "Matterhorn catalog text bound: OK\n";
