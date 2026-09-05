<?php
declare(strict_types=1);

function persistenceCheck(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
$root = dirname(__DIR__);
$services = (string) file_get_contents($root . '/config/services.yml');
$writer = (string) file_get_contents($root . '/src/Product/MatterhornProductWriter.php');
$base = (string) file_get_contents($root . '/src/Product/PrestaProductWriter.php');
$associations = (string) file_get_contents($root . '/src/Product/ProductShopAssociationManager.php');
$category = (string) file_get_contents($root . '/src/Repository/CategoryMappingRepository.php');
$categorySync = (string) file_get_contents($root . '/src/Category/CategorySynchronizer.php');
$specificPrice = (string) file_get_contents($root . '/src/SpecificPrice/SpecificPriceSynchronizer.php');
$outOfFeed = (string) file_get_contents($root . '/src/Product/DeactivateOutOfFeedPolicy.php');

persistenceCheck(str_contains($services, 'ProductWriterInterface:') && str_contains($services, 'MatterhornProductWriter'), 'DI must route product writes through Matterhorn writer');
persistenceCheck(str_contains($services, 'OutOfFeedPolicyInterface:') && str_contains($services, 'DeactivateOutOfFeedPolicy'), 'DI must keep conservative out-of-feed policy');
persistenceCheck(str_contains($writer, 'MATTERHORNIMPORT_SOURCE_LANGUAGE_ID'), 'Matterhorn writer must use configured supplier language');
persistenceCheck(!str_contains($writer, 'foreach (\\Language::getLanguages') || str_contains($writer, 'sourceLanguageId'), 'UPDATE core must not overwrite every translation');
persistenceCheck(str_contains($writer, "array_intersect(\$domains, ['stock','category'])"), 'Matterhorn writer must delegate generic stock/category domains');
persistenceCheck(str_contains($writer, 'assertExclusiveGlobalOwnership($productId, $shopId)'), 'Matterhorn core writes must fail closed for shared products');
persistenceCheck(str_contains($writer, "restoreDefaultShopShadows(\$productId, \$shopId, ['price'])"), 'Matterhorn price-only update must repair PrestaShop global price shadow');
persistenceCheck(str_contains($base, 'StockAvailable::setQuantity'), 'base writer must own shop-aware product stock writes');
persistenceCheck(str_contains($base, "restoreDefaultShopShadows(\$productId, \$shopId, ['price'])"), 'generic price-only update must repair PrestaShop global price shadow');
persistenceCheck(str_contains($base, "restoreDefaultShopShadows(\$productId, \$shopId, ['active'])"), 'out-of-feed disable must repair PrestaShop global active shadow');
persistenceCheck(str_contains($base, 'Product::getProductAttributesIds($productId)'), 'out-of-feed disable must enumerate product combinations');
persistenceCheck(substr_count($base, 'StockAvailable::setQuantity($productId, $attributeId, 0, $shopId)') === 1, 'out-of-feed disable must zero each combination stock row');
persistenceCheck(str_contains($associations, 'assertExclusiveGlobalOwnership'), 'association manager must expose global-field ownership guard');
persistenceCheck(str_contains($associations, 'function restoreDefaultShopShadows'), 'association manager must expose duplicated shop-field repair');
persistenceCheck(str_contains($associations, "['price' => true, 'active' => true]"), 'global product shadow repair must use a strict field allow-list');
persistenceCheck(str_contains($associations, 'id_shop_default'), 'global product shadow repair must source values from the default shop');
persistenceCheck(str_contains($category, 'li_matterhornim_99dfbf_category_mapping'), 'category runtime must use generated module mapping table');
persistenceCheck(!str_contains($category, 'lp_import_category_mapping'), 'generic category table token must not leak into standalone module');
persistenceCheck(str_contains($categorySync, 'assertExclusiveGlobalOwnership($productId, $shopId)'), 'category_product mutation must fail closed for shared products');
persistenceCheck(str_contains($specificPrice, 'combinationBelongsToShopProduct'), 'specific prices must validate target-shop combination association');
persistenceCheck(str_contains($specificPrice, 'product_attribute_shop'), 'specific-price combination validation must be shop-scoped');
persistenceCheck(str_contains($outOfFeed, 'disable($productId, $shopId)'), 'out-of-feed must deactivate/zero stock through writer');

echo "Product persistence contract checks: OK\n";
