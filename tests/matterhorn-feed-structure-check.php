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

// Prewk UniqueNode intentionally discovers the requested node by name without
// maintaining a full-document element-depth stack. This matches the proven
// Laravel CRM Matterhorn importer and keeps the large feed on one linear pass.
$nestedPath = feedStructureTemp(
    '<?xml version="1.0"?><products><group><product id="3"><name>Nested</name><price>1</price></product></group></products>'
);
try {
    $rows = iterator_to_array((new MatterhornXmlSource($nestedPath))->rows(), false);
    feedStructureCheck(count($rows) === 1 && ($rows[0]['id'] ?? '') === '3', 'Prewk UniqueNode must preserve node-name streaming semantics');
} finally {
    @unlink($nestedPath);
}

$sourceCode = (string) file_get_contents(dirname(__DIR__) . '/src/Source/MatterhornXmlSource.php');
feedStructureCheck(str_contains($sourceCode, 'private function assertRoot'), 'source must explicitly validate the Matterhorn root element');
feedStructureCheck(str_contains($sourceCode, 'XmlStringStreamer::createUniqueNodeParser'), 'source must use Prewk UniqueNode streaming');
feedStructureCheck(str_contains($sourceCode, "'uniqueNode' => 'product'"), 'source must target Matterhorn product nodes');

echo "Matterhorn feed structure: OK\n";
