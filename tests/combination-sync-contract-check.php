<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Lp\MatterhornImport\Combination\CombinationNormalizer;
use Lp\MatterhornImport\DTO\ProductData;

function combinationCheck(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$root = dirname(__DIR__);
$mapping = (string) file_get_contents($root . '/src/Repository/CombinationMappingRepository.php');
$sync = (string) file_get_contents($root . '/src/Combination/CombinationSynchronizer.php');
$attributeResolver = (string) file_get_contents($root . '/src/Combination/CombinationAttributeResolver.php');
combinationCheck(str_contains($mapping, 'li_matterhornim_99dfbf_combination_mapping'), 'combination mapping uses standalone DB token');
combinationCheck(!str_contains($mapping, 'lp_import_combination_mapping'), 'generic combination DB token does not leak');
combinationCheck(str_contains($attributeResolver, "$" . "row['attribute_ids'] = $" . "attributeIds"), 'supplier attributes resolve to numeric ids before sync');
combinationCheck(str_contains($sync, 'combinations_authoritative'), 'authoritative combination removal supported');
combinationCheck(str_contains($sync, 'Refusing to mutate global fields of shared combination'), 'shared combination global mutation fails closed');
combinationCheck(str_contains($sync, 'StockAvailable::setQuantity'), 'combination stock uses shop-aware PrestaShop stock API');

$product = new ProductData('206161', 'MH-206161', ['default' => 'Panties'], 14.9, 0, true, [], [
    'combinations' => [[
        'attribute_ids' => [12],
        'reference' => 'M1188149',
        'quantity' => 2,
        'price_impact' => 0.0,
        'weight_impact' => 0.0,
        'wholesale_price' => 0.0,
        'minimal_quantity' => 1,
        'ean13' => '5902934981668',
        'upc' => '',
        'mpn' => '',
        'default' => true,
    ]],
]);
$rows = (new CombinationNormalizer())->normalize($product);
combinationCheck(count($rows) === 1, 'resolved combination normalizes');
combinationCheck($rows[0]['reference'] === 'M1188149', 'option reference preserved');
combinationCheck($rows[0]['quantity'] === 2, 'option stock preserved');
combinationCheck($rows[0]['ean13'] === '5902934981668', 'EAN preserved');
combinationCheck(strlen($rows[0]['semantic_key']) === 64, 'semantic combination key stable');

echo "Combination sync contract checks: OK\n";
