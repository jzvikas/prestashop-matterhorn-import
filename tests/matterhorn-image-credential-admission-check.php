<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Mapper\MatterhornProductMapper;
use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
use Lp\MatterhornImport\Matterhorn\MatterhornHtmlSanitizer;
use Lp\MatterhornImport\Matterhorn\MatterhornSizeResolver;
use Lp\MatterhornImport\Source\MatterhornXmlSource;

function imageCredentialCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$rows = iterator_to_array(
    (new MatterhornXmlSource(__DIR__ . '/fixtures/matterhorn-sample.xml'))->rows(),
    false
);
$row = $rows[0] ?? null;
imageCredentialCheck(is_array($row), 'Matterhorn fixture row missing');

$mapper = new MatterhornProductMapper(
    new MatterhornSizeResolver(),
    new MatterhornCategoryPathNormalizer(),
    new MatterhornHtmlSanitizer()
);
$baseline = $mapper->map($row);

$credentialRow = $row;
$credentialUrl = 'https://supplier-user:supplier-pass@example.test/image.jpg';
$credentialRow['images'][] = $credentialUrl;
$credentialRow['images'][] = $credentialUrl;
$credentialMapped = $mapper->map($credentialRow);

imageCredentialCheck(
    $credentialMapped->images === $baseline->images,
    'credential-bearing supplier image URL must not enter the desired image manifest'
);
$warnings = $credentialMapped->extra['supplier_warnings'] ?? [];
imageCredentialCheck(
    count($warnings) === 1 && str_contains((string) $warnings[0], 'URL credentials were skipped'),
    'credential-bearing supplier image URL must produce one deterministic warning even when duplicated'
);
imageCredentialCheck(
    $credentialMapped->payloadHash() !== $baseline->payloadHash(),
    'credential-image warning must remain observable in payload hash'
);
imageCredentialCheck(
    $credentialMapped->domainHashes() === $baseline->domainHashes(),
    'skipped credential image URL must not dirty catalog domains'
);

$mapperCode = (string) file_get_contents(dirname(__DIR__) . '/src/Mapper/MatterhornProductMapper.php');
imageCredentialCheck(
    str_contains($mapperCode, "isset(\$parts['user']) || isset(\$parts['pass'])"),
    'Matterhorn READ mapper must reject image URL credentials before queue admission'
);

$downloaderCode = (string) file_get_contents(dirname(__DIR__) . '/src/Image/SafeImageDownloader.php');
imageCredentialCheck(
    str_contains($downloaderCode, "throw new \\InvalidArgumentException('Credentials in image URLs are not allowed')"),
    'secure image downloader must retain credential rejection as defense in depth'
);

echo "Matterhorn image credential admission: OK\n";
