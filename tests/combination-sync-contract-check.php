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
$genericCombinationToken = 'lp_' . 'import_' . 'combination_mapping';
combinationCheck(str_contains($mapping, 'li_matterhornim_99dfbf_combination_mapping'), 'combination mapping uses standalone DB token');
combinationCheck(!str_contains($mapping, $genericCombinationToken), 'generic combination DB token does not leak');
combinationCheck(str_contains($mapping, 'ownerForAttribute') && str_contains($mapping, '), false);'), 'combination owner lookup must use fresh DB state');
combinationCheck(!str_contains($mapping, 'ON DUPLICATE KEY UPDATE'), 'combination mapping save must not use ownership-sensitive upsert');
combinationCheck(str_contains($mapping, 'private function exactOwner('), 'combination mapping save must re-read exact semantic owner');
combinationCheck(str_contains($mapping, 'private function semanticOwnerWhere('), 'combination mapping save must scope updates to exact semantic owner');
combinationCheck(str_contains($mapping, '$db->update(self::TABLE, $data, $where, 0, true, false)'), 'combination mapping exact-owner updates must bypass Db query cache');
combinationCheck(str_contains($mapping, '$db->insert(self::TABLE, $insert, false, true, \\Db::INSERT)'), 'combination mapping save must use a plain insert after exact-owner recheck');
combinationCheck(str_contains($mapping, 'Combination attribute ownership conflict:'), 'foreign product-attribute owner collisions must fail explicitly');
combinationCheck(str_contains($mapping, 'matchesOwner('), 'combination mapping save must compare complete owner identity');
combinationCheck(str_contains($mapping, 'deleteExact') && str_contains($mapping, 'Affected_Rows() !== 1'), 'combination mapping deletes must be exact-owner affected-row fenced');
combinationCheck(!str_contains($mapping, 'function deleteByAttribute') && !str_contains($mapping, 'function deleteSemantic'), 'broad combination mapping delete APIs must not remain available');
combinationCheck(str_contains($attributeResolver, "$" . "row['attribute_ids'] = $" . "attributeIds"), 'supplier attributes resolve to numeric ids before sync');
combinationCheck(str_contains($sync, 'combinations_authoritative'), 'authoritative combination removal supported');
combinationCheck(str_contains($sync, '$authoritative = !empty($product->extra'), 'authoritative state must be explicit for default healing');
combinationCheck(str_contains($sync, 'COALESCE(('), 'authoritative empty-survivor path must derive cached default from live associations');
combinationCheck(str_contains($sync, 'pas.default_on=1'), 'manual live default must be preserved after authoritative removal');
combinationCheck(str_contains($sync, 'cache_default_attribute=COALESCE'), 'product_shop cached default must be synchronized after authoritative removal');
combinationCheck(str_contains($sync, 'Refusing to mutate global fields of shared combination'), 'shared combination global mutation fails closed');
combinationCheck(str_contains($sync, 'Refusing to override default combination owned outside Matterhorn'), 'external manual default combination conflict fails closed');
combinationCheck(str_contains($sync, 'pa.id_product_attribute NOT IN'), 'default healing must inspect non-Matterhorn target-shop combinations');
combinationCheck(str_contains($sync, 'StockAvailable::setQuantity'), 'combination stock uses shop-aware PrestaShop stock API');
combinationCheck(
    str_contains($sync, "['default_on' => null],\n            'id_shop=' . \$shopId . ' AND id_product_attribute IN (' . \$idList . ')',\n            0,\n            true,\n            false"),
    'clearing target-shop defaults must emit SQL NULL and bypass Db query cache'
);

combinationCheck(str_contains($sync, 'assertMappingOwner'), 'combination mutation must compare fresh mapping owner identity');
combinationCheck(str_contains($sync, "hash_equals(\$source, (string) \$owner['source'])"), 'combination owner fence must include source');
combinationCheck(str_contains($sync, "hash_equals(\$sourceKey, (string) \$owner['source_key'])"), 'combination owner fence must include source key');
combinationCheck(str_contains($sync, "hash_equals(\$semanticKey, (string) \$owner['semantic_key'])"), 'combination owner fence must include semantic key');
combinationCheck(str_contains($sync, "(int) \$owner['id_product'] !== \$productId"), 'combination owner fence must include product id');
combinationCheck(str_contains($sync, 'Preserve unmapped/manual duplicate combinations'), 'unmapped manual duplicate combinations must not be destructively cleaned');
combinationCheck(str_contains($sync, '$this->mapping->deleteExact($shopId, $source, $product->sourceKey'), 'authoritative cleanup must use exact mapping delete');
combinationCheck(!str_contains($sync, 'deleteByAttribute(') && !str_contains($sync, 'deleteSemantic('), 'synchronizer must not use broad mapping deletes');
$ownerCheck = strpos($sync, '$candidateOwner = $this->mapping->ownerForAttribute');
$duplicateDelete = strpos($sync, 'foreach (array_keys($ownedDuplicateIds) as $candidateId)');
combinationCheck($ownerCheck !== false && $duplicateDelete !== false && $ownerCheck < $duplicateDelete, 'duplicate ownership must be checked before destructive cleanup');

combinationCheck(str_contains($sync, 'DELETE target FROM `%sproduct_attribute_shop` target'), 'shared combination detach must be atomic');
combinationCheck(str_contains($sync, 'other.id_shop<>target.id_shop'), 'shared detach must prove another shop association still exists');
combinationCheck(str_contains($sync, 'pa.id_product_attribute=target.id_product_attribute AND pa.id_product=%d'), 'shared detach must fence product ownership');
combinationCheck(str_contains($sync, '$affected = (int) $db->Affected_Rows()'), 'shared detach must validate affected-row count');
combinationCheck(str_contains($sync, '$this->deleteExclusiveCombination($productId, $id, $shopId)'), 'topology race must re-enter independently fenced exclusive delete');
combinationCheck(str_contains($sync, 'target_shop_count'), 'exclusive delete must prove exact target-shop ownership');
combinationCheck(str_contains($sync, 'shared or ambiguously associated combination'), 'exclusive delete must fail closed on shared/ambiguous topology');
combinationCheck(substr_count($sync, '), false);') >= 3, 'direct live combination ownership/default reads must bypass Db query cache');
combinationCheck(
    str_contains($sync, "'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute_shop` WHERE id_product_attribute=' . \$id,\n            false"),
    'shop association count must bypass Db query cache'
);
combinationCheck(str_contains($sync, "executeS(sprintf(\n            \"SELECT pa.id_product_attribute") && str_contains($sync, '), true, false) ?: []'), 'semantic combination inventory must bypass Db query cache');
combinationCheck(str_contains($sync, "'cart_product'"), 'shared detach must clean target-shop cart rows after successful detach');

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
