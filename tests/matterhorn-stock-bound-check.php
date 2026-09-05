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

$tooHigh = $base;
$tooHigh['options'][0]['stock'] = '2147483648';
try {
    $mapper->map($tooHigh);
    $fail('stock above PrestaShop INT32 maximum must fail during READ');
} catch (InvalidArgumentException $e) {
    $check(str_contains($e->getMessage(), 'stock exceeds PrestaShop maximum of 2147483647'), 'stock bound error must be explicit');
}

$source = (string) file_get_contents(dirname(__DIR__) . '/src/Mapper/MatterhornProductMapper.php');
$check(str_contains($source, 'MAX_PRESTASHOP_STOCK = 2147483647'), 'mapper stock bound constant missing');

echo "MATTERHORN_STOCK_BOUND_CHECK_OK\n";
