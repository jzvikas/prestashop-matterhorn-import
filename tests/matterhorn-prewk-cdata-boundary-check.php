<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Source\MatterhornXmlSource;

$path = tempnam(sys_get_temp_dir(), 'mh-prewk-cdata-');
if ($path === false) {
    throw new RuntimeException('Could not create temporary Matterhorn fixture');
}

$largePrefix = str_repeat('A', 70000);
$description = $largePrefix . ' supplier literal </product> marker and > characters after a chunk boundary.';
$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
    '<products>' . "\n" .
    '  <product id="1">' . "\n" .
    '    <name>CDATA boundary</name>' . "\n" .
    '    <description><![CDATA[' . $description . ']]></description>' . "\n" .
    '  </product>' . "\n" .
    '  <product id="2">' . "\n" .
    '    <name>Second</name>' . "\n" .
    '    <description><![CDATA[Normal description]]></description>' . "\n" .
    '  </product>' . "\n" .
    '</products>' . "\n";

file_put_contents($path, $xml);

try {
    $source = new MatterhornXmlSource($path);
    $rows = [];
    foreach ($source->rows() as $row) {
        $rows[] = $row;
        if (count($rows) === 1) {
            break;
        }
    }

    if (($rows[0]['id'] ?? null) !== '1') {
        throw new RuntimeException('CDATA-aware Prewk stream did not return the first product intact');
    }
    $actualDescription = (string) ($rows[0]['description'] ?? '');
    if (strlen($actualDescription) !== strlen($description)) {
        throw new RuntimeException('CDATA content length changed across the 64 KiB stream boundary');
    }
    if (!str_contains($actualDescription, 'literal </product> marker')) {
        throw new RuntimeException('CDATA content was truncated at a literal </product> marker');
    }

    $checkpoint = $source->byteCheckpoint();
    if ($checkpoint <= 65536) {
        throw new RuntimeException('CDATA-aware Prewk stream did not advance beyond the first stream chunk');
    }

    $resumed = new MatterhornXmlSource($path);
    $ids = [];
    foreach ($resumed->rowsFromByte($checkpoint, 1) as $row) {
        $ids[] = (string) ($row['id'] ?? '');
    }
    if ($ids !== ['2']) {
        throw new RuntimeException('CDATA-aware Prewk byte resume repeated or skipped products');
    }
} finally {
    @unlink($path);
}

echo "Matterhorn Prewk CDATA boundary: OK\n";
