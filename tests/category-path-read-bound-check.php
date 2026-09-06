<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$check = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) { $fail($message); }
};

$normalizer = new MatterhornCategoryPathNormalizer();
$segments32 = array_map(static fn(int $i): string => 'Category' . $i, range(1, 32));
$normalized = $normalizer->normalize('/' . implode('/', $segments32));
$check(substr_count($normalized, ' > ') === 31, '32 category path segments must remain valid');

try {
    $normalizer->normalize('/' . implode('/', array_merge($segments32, ['Category33'])));
    $fail('33 category path segments must fail during READ normalization');
} catch (InvalidArgumentException $e) {
    $check(str_contains($e->getMessage(), 'path depth exceeds operational limit of 32'), 'category depth error must be explicit');
}

$check($normalizer->normalize('/' . str_repeat('A', 128)) === str_repeat('A', 128), '128-character category segment must remain valid');
try {
    $normalizer->normalize('/' . str_repeat('A', 129));
    $fail('129-character category path segment must fail during READ normalization');
} catch (InvalidArgumentException $e) {
    $check(str_contains($e->getMessage(), 'path segment exceeds PrestaShop 128-character limit'), 'category segment error must be explicit');
}

$source = (string) file_get_contents(dirname(__DIR__) . '/src/Matterhorn/MatterhornCategoryPathNormalizer.php');
foreach (['MAX_CATEGORY_PATH_DEPTH = 32', 'MAX_CATEGORY_SEGMENT_CHARS = 128', 'MAX_SUPPLIER_KEY_CHARS = 191'] as $token) {
    $check(str_contains($source, $token), 'missing category READ bound: ' . $token);
}

echo "CATEGORY_PATH_READ_BOUND_CHECK_OK\n";
