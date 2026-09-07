<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Source\MatterhornXmlSource;

$path = tempnam(sys_get_temp_dir(), 'mh-prewk-cdata-');
if ($path === false) {
    throw new RuntimeException('Could not create temporary Matterhorn fixture');
}

$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<products>
  <product id="1">
    <name>CDATA boundary</name>
    <description><![CDATA[Supplier text may contain a literal </product> marker and > characters without ending the real product node.]]></description>
  </product>
  <product id="2">
    <name>Second</name>
    <description><![CDATA[Normal description]]></description>
  </product>
</products>
XML;

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
    if (!str_contains((string) ($rows[0]['description'] ?? ''), 'literal </product> marker')) {
        throw new RuntimeException('CDATA content was truncated at a literal </product> marker');
    }

    $checkpoint = $source->byteCheckpoint();
    if ($checkpoint <= 0) {
        throw new RuntimeException('CDATA-aware Prewk stream did not expose a byte checkpoint');
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
