<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Mapper\MatterhornProductMapper;
use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
use Lp\MatterhornImport\Matterhorn\MatterhornHtmlSanitizer;
use Lp\MatterhornImport\Matterhorn\MatterhornSizeResolver;
use Lp\MatterhornImport\Source\MatterhornXmlSource;

function requiredFieldCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function expectRequiredFieldFailure(MatterhornProductMapper $mapper, array $row, string $needle): void
{
    try {
        $mapper->map($row);
        requiredFieldCheck(false, 'expected required-field failure containing: ' . $needle);
    } catch (InvalidArgumentException $e) {
        requiredFieldCheck(
            str_contains($e->getMessage(), $needle),
            'unexpected required-field error: ' . $e->getMessage()
        );
    }
}

$mapper = new MatterhornProductMapper(
    new MatterhornSizeResolver(),
    new MatterhornCategoryPathNormalizer(),
    new MatterhornHtmlSanitizer()
);
$row = iterator_to_array((new MatterhornXmlSource(__DIR__ . '/fixtures/matterhorn-sample.xml'))->rows(), false)[0] ?? null;
requiredFieldCheck(is_array($row), 'Matterhorn fixture row missing');

$maxName = $row;
$maxName['name'] = str_repeat('N', 128);
requiredFieldCheck(
    ($mapper->map($maxName)->name['default'] ?? '') === str_repeat('N', 128),
    '128-character product name must remain lossless'
);

$tooLongName = $row;
$tooLongName['name'] = str_repeat('N', 129);
expectRequiredFieldFailure($mapper, $tooLongName, 'product name exceeds PrestaShop 128-character limit');

$blankId = $row;
$blankId['id'] = '';
expectRequiredFieldFailure($mapper, $blankId, 'product id must be a non-empty numeric value');

$nonNumericId = $row;
$nonNumericId['id'] = '12A';
expectRequiredFieldFailure($mapper, $nonNumericId, 'product id must be a non-empty numeric value');

$blankName = $row;
$blankName['name'] = '   ';
expectRequiredFieldFailure($mapper, $blankName, 'is missing name');

foreach (['', 'not-a-price', '-1'] as $invalidPrice) {
    $badPrice = $row;
    $badPrice['price'] = $invalidPrice;
    expectRequiredFieldFailure($mapper, $badPrice, 'has invalid price');
}

$missingOptionId = $row;
$missingOptionId['options'][0]['id'] = '';
expectRequiredFieldFailure($mapper, $missingOptionId, 'contains option without id');

$missingSize = $row;
$missingSize['options'][0]['name'] = '';
expectRequiredFieldFailure($mapper, $missingSize, 'has empty size');

$invalidStock = $row;
$invalidStock['options'][0]['stock'] = '1.5';
expectRequiredFieldFailure($mapper, $invalidStock, 'has invalid stock');

$mapperCode = (string) file_get_contents(dirname(__DIR__) . '/src/Mapper/MatterhornProductMapper.php');
requiredFieldCheck(!str_contains($mapperCode, 'mb_substr($name, 0, 128'), 'product name must never be silently truncated');

echo "Matterhorn required-field bounds: OK\n";
