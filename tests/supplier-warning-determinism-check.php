<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Mapper\MatterhornProductMapper;
use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
use Lp\MatterhornImport\Matterhorn\MatterhornHtmlSanitizer;
use Lp\MatterhornImport\Matterhorn\MatterhornSizeResolver;
use Lp\MatterhornImport\Source\MatterhornXmlSource;

function warningDeterminismCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$rows = iterator_to_array((new MatterhornXmlSource(__DIR__ . '/fixtures/matterhorn-sample.xml'))->rows(), false);
$row = $rows[0];
$row['options'][0]['ean'] = 'BAD-EAN';
$row['options'][1]['stock'] = '-4';

$mapper = new MatterhornProductMapper(
    new MatterhornSizeResolver(),
    new MatterhornCategoryPathNormalizer(),
    new MatterhornHtmlSanitizer()
);

$forward = $mapper->map($row);
$reversedRow = $row;
$reversedRow['options'] = array_reverse($reversedRow['options']);
$reversed = $mapper->map($reversedRow);

warningDeterminismCheck(count($forward->extra['supplier_warnings'] ?? []) === 2, 'fixture must produce two supplier warnings');
warningDeterminismCheck(
    ($forward->extra['supplier_warnings'] ?? []) === ($reversed->extra['supplier_warnings'] ?? []),
    'supplier warning order must be deterministic when supplier option order changes'
);
warningDeterminismCheck(
    $forward->payloadHash() === $reversed->payloadHash(),
    'supplier option reordering with identical semantics must not churn snapshot payload hash'
);
warningDeterminismCheck(
    $forward->domainHashes() === $reversed->domainHashes(),
    'supplier option reordering with identical semantics must not churn catalog domain hashes'
);

echo "Supplier warning determinism checks: OK\n";
