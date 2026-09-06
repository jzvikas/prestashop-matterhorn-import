<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Mapper\MatterhornProductMapper;
use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
use Lp\MatterhornImport\Matterhorn\MatterhornHtmlSanitizer;
use Lp\MatterhornImport\Matterhorn\MatterhornSizeResolver;
use Lp\MatterhornImport\Source\MatterhornXmlSource;

function priceBoundCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$mapper = new MatterhornProductMapper(
    new MatterhornSizeResolver(),
    new MatterhornCategoryPathNormalizer(),
    new MatterhornHtmlSanitizer()
);
$fixture = __DIR__ . '/fixtures/matterhorn-sample.xml';
$row = iterator_to_array((new MatterhornXmlSource($fixture))->rows(), false)[0] ?? null;
priceBoundCheck(is_array($row), 'Matterhorn fixture row missing');

$normal = $row;
$normal['price'] = '14.90';
priceBoundCheck($mapper->map($normal)->price === 14.9, 'normal Matterhorn price must remain valid');

$scientific = $row;
$scientific['price'] = '1e2';
priceBoundCheck($mapper->map($scientific)->price === 100.0, 'numeric supplier price that becomes plain PrestaShop float representation must remain valid');

foreach ([
    '10000000000' => '11-digit price must fail before Product ObjectModel validation',
    '9999999999.99999' => 'float-rounded price crossing the PrestaShop 10-digit boundary must fail in READ',
    '0.000001' => 'float scientific-notation price must fail before Product ObjectModel validation',
] as $raw => $message) {
    $candidate = $row;
    $candidate['price'] = $raw;
    try {
        $mapper->map($candidate);
        priceBoundCheck(false, $message);
    } catch (InvalidArgumentException $e) {
        priceBoundCheck(
            str_contains($e->getMessage(), 'price cannot be represented by the PrestaShop price validator'),
            'unexpected price-bound error for ' . $raw . ': ' . $e->getMessage()
        );
    }
}

$mapperCode = (string) file_get_contents(dirname(__DIR__) . '/src/Mapper/MatterhornProductMapper.php');
priceBoundCheck(
    str_contains($mapperCode, "PRESTASHOP_PRICE_PATTERN = '/^[0-9]{1,10}(?:\\.[0-9]{1,9})?$/D'"),
    'Matterhorn mapper must preserve the PrestaShop isPrice-compatible float fence'
);

echo "Matterhorn price bound: OK\n";
