<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Mapper\MatterhornProductMapper;
use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
use Lp\MatterhornImport\Matterhorn\MatterhornHtmlSanitizer;
use Lp\MatterhornImport\Matterhorn\MatterhornSizeResolver;
use Lp\MatterhornImport\Source\MatterhornXmlSource;

function domainIsolationCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** @param list<string> $expectedChanged */
function assertChangedDomains(ProductData $before, ProductData $after, array $expectedChanged, string $label): void
{
    $beforeHashes = $before->domainHashes();
    $afterHashes = $after->domainHashes();
    $expectedLookup = array_fill_keys($expectedChanged, true);

    domainIsolationCheck(
        array_keys($beforeHashes) === array_keys($afterHashes),
        $label . ': domain key set changed unexpectedly'
    );

    foreach ($beforeHashes as $domain => $hash) {
        $changed = $hash !== $afterHashes[$domain];
        $expected = isset($expectedLookup[$domain]);
        domainIsolationCheck(
            $changed === $expected,
            $label . ': domain ' . $domain . ($expected ? ' did not change' : ' changed unexpectedly')
        );
    }
}

$mapper = new MatterhornProductMapper(
    new MatterhornSizeResolver(),
    new MatterhornCategoryPathNormalizer(),
    new MatterhornHtmlSanitizer()
);

$rows = iterator_to_array(
    (new MatterhornXmlSource(__DIR__ . '/fixtures/matterhorn-sample.xml'))->rows(),
    false
);
$baselineRow = $rows[0] ?? null;
domainIsolationCheck(is_array($baselineRow), 'baseline Matterhorn fixture row missing');
$baseline = $mapper->map($baselineRow);

$cases = [];

$row = $baselineRow;
$row['price'] = '15.9';
$cases[] = ['price-only', $row, ['price']];

$row = $baselineRow;
$row['options'][0]['stock'] = '7';
$cases[] = ['combination stock-only', $row, ['combination_stock']];

$row = $baselineRow;
$row['images'][] = 'https://matterhorn-wholesale.com/pics_source/domain-hash-extra.jpg';
$cases[] = ['images-only', $row, ['image']];

$row = $baselineRow;
$row['brand'] = 'Axami Domain Hash';
$cases[] = ['brand-only', $row, ['core']];

$row = $baselineRow;
$row['description'] = '<p>Domain hash description change</p>';
$cases[] = ['description-only', $row, ['core']];

$row = $baselineRow;
$row['name'] = 'Panties model 206161 Axami domain hash';
$cases[] = ['name-only', $row, ['core']];

$row = $baselineRow;
$row['color'] = 'blue';
$cases[] = ['color-only', $row, ['feature']];

$row = $baselineRow;
$row['type'] = 'Briefs';
$cases[] = ['type-only', $row, ['feature']];

$row = $baselineRow;
$row['category']['id'] = '300003';
$cases[] = ['category id-only', $row, ['category']];

$row = $baselineRow;
$row['category_path'] = '/WOMEN/Women`s Lingerie/Knickers, Slips, Thongs/Domain Hash Briefs';
$cases[] = ['category path-only', $row, ['category']];

$row = $baselineRow;
$row['options'][0]['ean'] = '5902934981999';
$cases[] = ['option EAN-only', $row, ['combination']];

$row = $baselineRow;
$row['options'][0]['name'] = 'S';
$cases[] = ['option size-only', $row, ['combination', 'combination_stock']];

$row = $baselineRow;
$row['options'][0]['id'] = 'M1188150';
$cases[] = ['option reference-only', $row, ['combination']];

foreach ($cases as [$label, $changedRow, $expectedDomains]) {
    $changed = $mapper->map($changedRow);
    assertChangedDomains($baseline, $changed, $expectedDomains, $label);
}

$creationDateRow = $baselineRow;
$creationDateRow['creation_date'] = '2026-06-04 00:00:00';
$creationDateChanged = $mapper->map($creationDateRow);
assertChangedDomains($baseline, $creationDateChanged, [], 'creation_date supplier metadata-only');
domainIsolationCheck(
    $baseline->payloadHash() !== $creationDateChanged->payloadHash(),
    'creation_date supplier metadata change must remain observable in payload hash'
);

$availableInRow = $baselineRow;
$availableInRow['options'][0]['available_in'] = '9';
$availableInChanged = $mapper->map($availableInRow);
assertChangedDomains($baseline, $availableInChanged, [], 'avaible_in supplier metadata-only');
domainIsolationCheck(
    $baseline->payloadHash() !== $availableInChanged->payloadHash(),
    'avaible_in supplier metadata change must remain observable in payload hash'
);

echo "Matterhorn domain hash isolation: OK\n";
