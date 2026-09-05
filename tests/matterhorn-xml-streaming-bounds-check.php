<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Lp\MatterhornImport\Mapper\MatterhornProductMapper;
use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
use Lp\MatterhornImport\Matterhorn\MatterhornHtmlSanitizer;
use Lp\MatterhornImport\Matterhorn\MatterhornSizeResolver;
use Lp\MatterhornImport\Source\MatterhornXmlSource;

function streamingCheck(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

function tempXml(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'mh-stream-');
    if ($path === false) { throw new RuntimeException('Could not create temporary XML path'); }
    if (file_put_contents($path, "<?xml version=\"1.0\" encoding=\"utf-8\"?><products>{$body}</products>") === false) {
        @unlink($path);
        throw new RuntimeException('Could not write temporary XML');
    }
    return $path;
}

function expectStreamFailure(string $body, string $needle): void
{
    $path = tempXml($body);
    try {
        iterator_to_array((new MatterhornXmlSource($path))->rows(), false);
        streamingCheck(false, 'expected streaming failure containing: ' . $needle);
    } catch (RuntimeException $e) {
        streamingCheck(str_contains($e->getMessage(), $needle), 'unexpected streaming failure: ' . $e->getMessage());
    } finally {
        @unlink($path);
    }
}

$sourceCode = (string) file_get_contents(dirname(__DIR__) . '/src/Source/MatterhornXmlSource.php');
streamingCheck(!str_contains($sourceCode, 'readOuterXML'), 'Matterhorn source must not materialize whole product XML');
streamingCheck(!str_contains($sourceCode, 'simplexml_load_string'), 'Matterhorn source must not reparse whole product through SimpleXML');
foreach ([
    'MAX_SOURCE_RECORD_BYTES = 4194304',
    'MAX_SOURCE_FIELD_BYTES = 2097152',
    'MAX_SOURCE_ATTRIBUTE_BYTES = 191',
    'MAX_IMAGE_URL_BYTES = 16384',
    'MAX_IMAGES_PER_PRODUCT = 1000',
    'MAX_OPTIONS_PER_PRODUCT = 5000',
    'skipCurrentElementCounting',
    'readImageUrlElement',
    'readBoundedAttribute',
] as $token) {
    streamingCheck(str_contains($sourceCode, $token), 'missing streaming bound: ' . $token);
}

$fixture = __DIR__ . '/fixtures/matterhorn-sample.xml';
$fixtureRows = iterator_to_array((new MatterhornXmlSource($fixture))->rows(), false);
streamingCheck(count($fixtureRows) === 3, 'bounded parser must preserve fixture product count');
streamingCheck(($fixtureRows[0]['category']['id'] ?? '') === '3', 'bounded parser must preserve category id attribute');
streamingCheck(($fixtureRows[0]['options'][0]['id'] ?? '') === 'M1188149', 'bounded parser must preserve option id attribute');
streamingCheck(($fixtureRows[0]['options'][0]['available_in'] ?? '') === '3', 'bounded parser must preserve avaible_in metadata');
streamingCheck(count($fixtureRows[0]['images'] ?? []) === 4, 'bounded parser must preserve ordered image deduplication');
$resumed = iterator_to_array((new MatterhornXmlSource($fixture))->rowsFrom(1), false);
streamingCheck(count($resumed) === 2 && ($resumed[0]['id'] ?? '') === '34375', 'bounded parser must preserve checkpoint resume');

$oversizedUrl = 'https://supplier.invalid/' . str_repeat('x', 16384);
$warningPath = tempXml(
    '<product id="1"><name>Valid product</name><price>1</price><images><image_url>' .
    htmlspecialchars($oversizedUrl, ENT_XML1 | ENT_COMPAT, 'UTF-8') .
    '</image_url><image_url>https://supplier.invalid/ok.jpg</image_url></images></product>'
);
try {
    $row = iterator_to_array((new MatterhornXmlSource($warningPath))->rows(), false)[0] ?? null;
    streamingCheck(is_array($row), 'oversized optional image must not invalidate product row');
    streamingCheck(($row['images'] ?? []) === ['https://supplier.invalid/ok.jpg'], 'oversized optional image must be omitted while valid image remains');
    $sourceWarnings = (array) ($row['supplier_warnings'] ?? []);
    streamingCheck(count($sourceWarnings) === 1 && str_contains((string) $sourceWarnings[0], 'exceeds 16384 bytes'), 'oversized image must produce one source warning');

    $mapped = (new MatterhornProductMapper(
        new MatterhornSizeResolver(),
        new MatterhornCategoryPathNormalizer(),
        new MatterhornHtmlSanitizer()
    ))->map($row);
    streamingCheck($mapped->images === ['https://supplier.invalid/ok.jpg'], 'mapper must retain valid streamed image manifest');
    $mappedWarnings = (array) ($mapped->extra['supplier_warnings'] ?? []);
    streamingCheck(count($mappedWarnings) === 1 && str_contains((string) $mappedWarnings[0], 'exceeds 16384 bytes'), 'streamed image warning must survive into snapshot payload');
} finally {
    @unlink($warningPath);
}

expectStreamFailure(
    '<product id="2"><name>Too large field</name><price>1</price><description><![CDATA[' . str_repeat('d', 2097153) . ']]></description></product>',
    'source field description exceeds limit of 2097152 bytes'
);

$images = '';
for ($i = 0; $i < 1001; $i++) {
    $images .= '<image_url>https://supplier.invalid/' . $i . '.jpg</image_url>';
}
expectStreamFailure(
    '<product id="3"><name>Too many images</name><price>1</price><images>' . $images . '</images></product>',
    'image count exceeds limit of 1000'
);

$options = '';
for ($i = 0; $i < 5001; $i++) {
    $options .= '<option id="O' . $i . '"><option_name>S' . $i . '</option_name><STOCK>1</STOCK><avaible_in>3</avaible_in><ean></ean></option>';
}
expectStreamFailure(
    '<product id="4"><name>Too many options</name><price>1</price><options>' . $options . '</options></product>',
    'option count exceeds limit of 5000'
);

$longAttribute = str_repeat('A', 192);
expectStreamFailure(
    '<product id="' . $longAttribute . '"><name>Long product id</name><price>1</price></product>',
    'source attribute product/@id exceeds limit of 191 bytes'
);
expectStreamFailure(
    '<product id="5"><name>Long category id</name><price>1</price><category id="' . $longAttribute . '">Category</category></product>',
    'source attribute category/@id exceeds limit of 191 bytes'
);
expectStreamFailure(
    '<product id="6"><name>Long option id</name><price>1</price><options><option id="' . $longAttribute . '"><option_name>S</option_name><STOCK>1</STOCK></option></options></product>',
    'source attribute options/option/@id exceeds limit of 191 bytes'
);

echo "Matterhorn XML streaming bounds: OK\n";
