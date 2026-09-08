<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Source\MatterhornXmlSource;

function feedStructureCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function feedStructureTemp(string $xml): string
{
    $path = tempnam(sys_get_temp_dir(), 'mh-feed-');
    if ($path === false || file_put_contents($path, $xml) === false) {
        if (is_string($path)) {
            @unlink($path);
        }
        throw new RuntimeException('Could not create temporary Matterhorn feed');
    }
    return $path;
}

function expectFeedStructureFailure(string $xml, string $needle): void
{
    $path = feedStructureTemp($xml);
    try {
        iterator_to_array((new MatterhornXmlSource($path))->rows(), false);
        feedStructureCheck(false, 'expected Matterhorn feed structure failure containing: ' . $needle);
    } catch (RuntimeException $e) {
        feedStructureCheck(
            str_contains($e->getMessage(), $needle),
            'unexpected Matterhorn feed structure error: ' . $e->getMessage()
        );
    } finally {
        @unlink($path);
    }
}

$validPath = feedStructureTemp(
    "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<!-- supplier comment -->\n" .
    '<products><product id="1"><name>Valid</name><price>1</price></product></products>'
);
try {
    $rows = iterator_to_array((new MatterhornXmlSource($validPath))->rows(), false);
    feedStructureCheck(count($rows) === 1 && ($rows[0]['id'] ?? '') === '1', 'valid <products>/<product> feed must remain accepted');
} finally {
    @unlink($validPath);
}

expectFeedStructureFailure(
    '<?xml version="1.0"?><catalog><product id="2"><name>Wrong root</name><price>1</price></product></catalog>',
    'root must be <products>'
);

// Matterhorn's supplier contract is <products> with direct <product> children.
// StringWalker intentionally validates that depth instead of discovering any
// nested element merely named product, which also prevents accidental capture
// of product-like markup inside supplier content. The malformed-record recovery
// layer may surface a concrete libxml parse detail, so assert the stable source-
// record parse-error contract rather than one historical libxml message.
expectFeedStructureFailure(
    '<?xml version="1.0"?><products><group><product id="3"><name>Nested</name><price>1</price></product></group></products>',
    'Matterhorn product XML parse error at source record 1:'
);

$sourceCode = (string) file_get_contents(dirname(__DIR__) . '/src/Source/MatterhornXmlSource.php');
feedStructureCheck(str_contains($sourceCode, 'private function assertRoot'), 'source must explicitly validate the Matterhorn root element');
feedStructureCheck(str_contains($sourceCode, 'new PrewkCheckpointStringWalker('), 'source must construct the Prewk StringWalker parser');
feedStructureCheck(str_contains($sourceCode, 'new XmlStringStreamer('), 'source must run the Prewk XmlStringStreamer');
feedStructureCheck(str_contains($sourceCode, "'captureDepth' => \$byteOffset === 0 ? 2 : 1"), 'source must capture direct products and resume between them');
feedStructureCheck(str_contains($sourceCode, "'expectGT' => true"), 'source must preserve CDATA/comment boundaries');

echo "Matterhorn feed structure: OK\n";
