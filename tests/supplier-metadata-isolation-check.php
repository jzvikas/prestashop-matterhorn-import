<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Lp\MatterhornImport\Mapper\MatterhornProductMapper;
use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
use Lp\MatterhornImport\Matterhorn\MatterhornHtmlSanitizer;
use Lp\MatterhornImport\Matterhorn\MatterhornSizeResolver;
use Lp\MatterhornImport\Source\MatterhornXmlSource;

function supplierMetadataCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$rows = iterator_to_array((new MatterhornXmlSource(__DIR__ . '/fixtures/matterhorn-sample.xml'))->rows(), false);
$mapper = new MatterhornProductMapper(
    new MatterhornSizeResolver(),
    new MatterhornCategoryPathNormalizer(),
    new MatterhornHtmlSanitizer()
);

$baseline = $mapper->map($rows[0]);
supplierMetadataCheck(
    ($baseline->extra['supplier_metadata']['creation_date'] ?? '') === '2026-06-03 00:00:00',
    'supplier creation_date must be retained in snapshot metadata'
);
supplierMetadataCheck(
    ($baseline->extra['combinations'][0]['matterhorn_available_in'] ?? '') === '3',
    'supplier avaible_in must be retained as combination metadata'
);

$creationChangedRow = $rows[0];
$creationChangedRow['creation_date'] = '2026-06-05 00:00:00';
$creationChanged = $mapper->map($creationChangedRow);
supplierMetadataCheck(
    $baseline->payloadHash() !== $creationChanged->payloadHash(),
    'creation_date metadata change must remain observable in payload hash'
);
supplierMetadataCheck(
    $baseline->domainHashes() === $creationChanged->domainHashes(),
    'creation_date metadata change must not dirty catalog domain hashes or rewrite PrestaShop date_add'
);

$availabilityChangedRow = $rows[0];
$availabilityChangedRow['options'][0]['available_in'] = '9';
$availabilityChanged = $mapper->map($availabilityChangedRow);
supplierMetadataCheck(
    ($availabilityChanged->extra['combinations'][0]['matterhorn_available_in'] ?? '') === '9',
    'changed avaible_in value must remain observable in snapshot payload'
);
supplierMetadataCheck(
    $baseline->payloadHash() !== $availabilityChanged->payloadHash(),
    'avaible_in metadata change must change payload hash'
);
supplierMetadataCheck(
    $baseline->domainHashes() === $availabilityChanged->domainHashes(),
    'avaible_in metadata change must not dirty catalog domain hashes'
);

echo "Supplier metadata isolation checks: OK\n";
