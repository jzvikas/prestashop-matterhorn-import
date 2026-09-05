<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Lp\MatterhornImport\Mapper\MatterhornProductMapper;
use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
use Lp\MatterhornImport\Matterhorn\MatterhornHtmlSanitizer;
use Lp\MatterhornImport\Matterhorn\MatterhornSizeResolver;

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$check = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) { $fail($message); }
};

$mapper = new MatterhornProductMapper(
    new MatterhornSizeResolver(),
    new MatterhornCategoryPathNormalizer(),
    new MatterhornHtmlSanitizer()
);
$base = [
    'id' => '1',
    'name' => 'Stock bound product',
    'price' => '1.00',
    'images' => [],
    'options' => [[
        'id' => 'M1',
        'name' => 'S',
        'stock' => '2147483647',
        'available_in' => '',
        'ean' => '',
    ]],
];

$max = $mapper->map($base);
$check(($max->extra['combinations'][0]['quantity'] ?? null) === 2147483647, 'PrestaShop INT32 maximum stock must remain valid');

foreach ([
    '08' => 8,
    '0001' => 1,
    '00' => 0,
    '+00012' => 12,
] as $raw => $expected) {
    $candidate = $base;
    $candidate['options'][0]['stock'] = $raw;
    $mapped = $mapper->map($candidate);
    $check(
        ($mapped->extra['combinations'][0]['quantity'] ?? null) === $expected,
        'canonical integer stock with leading zeros must map losslessly: ' . $raw
    );
}

$negativeLeadingZero = $base;
$negativeLeadingZero['options'][0]['stock'] = '-0004';
$negativeMapped = $mapper->map($negativeLeadingZero);
$check(($negativeMapped->extra['combinations'][0]['quantity'] ?? null) === 0, 'negative stock with leading zeros must normalize to zero');
$check(
    count($negativeMapped->extra['supplier_warnings'] ?? []) === 1
    && str_contains((string) $negativeMapped->extra['supplier_warnings'][0], 'negative stock -4 normalized to 0'),
    'negative stock with leading zeros must keep the existing observable normalization warning'
);

$tooHigh = $base;
$tooHigh['options'][0]['stock'] = '0002147483648';
try {
    $mapper->map($tooHigh);
    $fail('stock above PrestaShop INT32 maximum must fail during READ even with leading zeros');
} catch (InvalidArgumentException $e) {
    $check(str_contains($e->getMessage(), 'stock exceeds PrestaShop maximum of 2147483647'), 'stock bound error must be explicit');
}

foreach (['1.0', '8x', '--1', '+-1'] as $raw) {
    $candidate = $base;
    $candidate['options'][0]['stock'] = $raw;
    try {
        $mapper->map($candidate);
        $fail('non-integer stock syntax must remain invalid: ' . $raw);
    } catch (InvalidArgumentException $e) {
        $check(str_contains($e->getMessage(), 'has invalid stock'), 'invalid stock syntax error must remain explicit: ' . $raw);
    }
}

$source = (string) file_get_contents(dirname(__DIR__) . '/src/Mapper/MatterhornProductMapper.php');
$check(str_contains($source, 'MAX_PRESTASHOP_STOCK = 2147483647'), 'mapper stock bound constant missing');
$check(str_contains($source, 'canonicalInteger'), 'mapper must canonicalize leading-zero integer stock before FILTER_VALIDATE_INT');

echo "MATTERHORN_STOCK_BOUND_CHECK_OK\n";
