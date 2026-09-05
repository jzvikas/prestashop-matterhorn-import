<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Lp\MatterhornImport\Source\MatterhornXmlSource;

function duplicateSingletonCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function duplicateSingletonTemp(string $xml): string
{
    $path = tempnam(sys_get_temp_dir(), 'mh-dup-');
    if ($path === false || file_put_contents($path, $xml) === false) {
        if (is_string($path)) {
            @unlink($path);
        }
        throw new RuntimeException('Could not create temporary Matterhorn feed');
    }
    return $path;
}

function expectDuplicateSingletonFailure(string $body, string $field): void
{
    $path = duplicateSingletonTemp('<?xml version="1.0"?><products><product id="1">' . $body . '</product></products>');
    try {
        iterator_to_array((new MatterhornXmlSource($path))->rows(), false);
        duplicateSingletonCheck(false, 'expected duplicate singleton failure for ' . $field);
    } catch (RuntimeException $e) {
        duplicateSingletonCheck(
            str_contains($e->getMessage(), 'Duplicate Matterhorn singleton field ' . $field . ' at source record 1'),
            'unexpected duplicate singleton error for ' . $field . ': ' . $e->getMessage()
        );
    } finally {
        @unlink($path);
    }
}

expectDuplicateSingletonFailure(
    '<name>One</name><name>Two</name><price>1</price>',
    'product/name'
);
expectDuplicateSingletonFailure(
    '<name>One</name><price>1</price><price>2</price>',
    'product/price'
);
expectDuplicateSingletonFailure(
    '<name>One</name><price>1</price><category id="1">A</category><category id="2">B</category>',
    'product/category'
);
expectDuplicateSingletonFailure(
    '<name>One</name><price>1</price><images/><images/>',
    'product/images'
);
expectDuplicateSingletonFailure(
    '<name>One</name><price>1</price><options/><options/>',
    'product/options'
);
expectDuplicateSingletonFailure(
    '<name>One</name><price>1</price><options><option id="A"><option_name>S</option_name><STOCK>1</STOCK><STOCK>2</STOCK></option></options>',
    'options/option/STOCK'
);
expectDuplicateSingletonFailure(
    '<name>One</name><price>1</price><options><option id="A"><option_name>S</option_name><ean>5901234123457</ean><ean>5901234123457</ean></option></options>',
    'options/option/ean'
);

$validPath = duplicateSingletonTemp(
    '<?xml version="1.0"?><products><product id="1">' .
    '<name>One</name><price>1</price>' .
    '<images><image_url>https://example.test/a.jpg</image_url><image_url>https://example.test/a.jpg</image_url><image_url>https://example.test/b.jpg</image_url></images>' .
    '<options>' .
    '<option id="A"><option_name>S</option_name><STOCK>1</STOCK><ean>5901234123457</ean></option>' .
    '<option id="B"><option_name>M</option_name><STOCK>2</STOCK><ean>5901234123457</ean></option>' .
    '</options>' .
    '<unknown>first</unknown><unknown>second</unknown>' .
    '</product></products>'
);
try {
    $rows = iterator_to_array((new MatterhornXmlSource($validPath))->rows(), false);
    duplicateSingletonCheck(count($rows) === 1, 'valid feed must still parse');
    duplicateSingletonCheck(($rows[0]['images'] ?? null) === ['https://example.test/a.jpg', 'https://example.test/b.jpg'], 'duplicate image URLs must remain deduplicated rather than rejected');
    duplicateSingletonCheck(count((array) ($rows[0]['options'] ?? [])) === 2, 'multiple option records must remain allowed');
} finally {
    @unlink($validPath);
}

$sourceCode = (string) file_get_contents(dirname(__DIR__) . '/src/Source/MatterhornXmlSource.php');
duplicateSingletonCheck(str_contains($sourceCode, 'assertSingletonField'), 'source must retain the singleton-field guard');

echo "Matterhorn duplicate singleton fields: OK\n";
