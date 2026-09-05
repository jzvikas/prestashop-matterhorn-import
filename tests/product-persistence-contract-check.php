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
$category = (string) file_get_contents($root . '/src/Repository/CategoryMappingRepository.php');
$outOfFeed = (string) file_get_contents($root . '/src/Product/DeactivateOutOfFeedPolicy.php');

persistenceCheck(str_contains($services, 'ProductWriterInterface:') && str_contains($services, 'MatterhornProductWriter'), 'DI must route product writes through Matterhorn writer');
persistenceCheck(str_contains($services, 'OutOfFeedPolicyInterface:') && str_contains($services, 'DeactivateOutOfFeedPolicy'), 'DI must keep conservative out-of-feed policy');
persistenceCheck(str_contains($writer, 'MATTERHORNIMPORT_SOURCE_LANGUAGE_ID'), 'Matterhorn writer must use configured supplier language');
persistenceCheck(!str_contains($writer, 'foreach (\\Language::getLanguages') || str_contains($writer, 'sourceLanguageId'), 'UPDATE core must not overwrite every translation');
persistenceCheck(str_contains($writer, "array_intersect(\$domains, ['stock','category'])"), 'Matterhorn writer must delegate generic stock/category domains');
persistenceCheck(str_contains($base, 'StockAvailable::setQuantity'), 'base writer must own shop-aware product stock writes');
persistenceCheck(str_contains($category, 'li_matterhornim_99dfbf_category_mapping'), 'category runtime must use generated module mapping table');
persistenceCheck(!str_contains($category, 'lp_import_category_mapping'), 'generic category table token must not leak into standalone module');
persistenceCheck(str_contains($outOfFeed, 'disable($productId, $shopId)'), 'out-of-feed must deactivate/zero stock through writer');

echo "Product persistence contract checks: OK\n";
