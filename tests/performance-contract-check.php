<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/src/Source/MatterhornXmlSource.php');
$read = (string) file_get_contents($root . '/src/Import/ReadStage.php');
$snapshots = (string) file_get_contents($root . '/src/Repository/SnapshotRepository.php');
$installer = (string) file_get_contents($root . '/src/Installer.php');
$product = (string) file_get_contents($root . '/src/DTO/ProductData.php');
$attributeMapping = (string) file_get_contents($root . '/src/Repository/AttributeMappingRepository.php');
$attributeResolver = (string) file_get_contents($root . '/src/Combination/CombinationAttributeResolver.php');
$categoryAuto = (string) file_get_contents($root . '/src/Category/CategoryAutoMapper.php');
$categorySync = (string) file_get_contents($root . '/src/Category/CategorySynchronizer.php');
$featureMapping = (string) file_get_contents($root . '/src/Repository/FeatureMappingRepository.php');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$check = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) { $fail($message); }
};

$check(str_contains($source, 'new \\XMLReader()'), 'Matterhorn source must stream through XMLReader');
$check(str_contains($source, 'readOuterXML()'), 'source may materialize only the current product record');
$check(!str_contains($source, 'file_get_contents($path)'), 'source must never read the entire XML into memory');
$check(!str_contains($source, 'simplexml_load_file'), 'source must never build whole-feed SimpleXML tree');
$check(str_contains($source, 'LIBXML_COMPACT'), 'XMLReader must use compact parser mode');
$check(str_contains($read, 'MAX_PRODUCT_PAYLOAD_BYTES = 2097152'), 'READ per-product payload bound missing');
$check(str_contains($read, 'MAX_BATCH_PAYLOAD_BYTES = 8388608'), 'READ batch payload bound missing');
$check(str_contains($read, 'WRITE_BATCH = 500'), 'READ bounded write batch missing');
$check(str_contains($snapshots, 'MAX_FETCH_PAYLOAD_BYTES = 8388608'), 'snapshot fetch payload bound missing');
$check(str_contains($snapshots, "s.source_key>'"), 'source-key keyset pagination missing');
$check(str_contains($snapshots, 'm.id_product>'), 'product-id keyset pagination missing');

$check(str_contains($installer, "'idx_shop_source_run' => '(`id_shop`,`source`,`id_run`)'"), 'latest-run index missing');
$check(str_contains($installer, "'idx_feed_product' => '(`id_shop`,`source`,`out_of_feed`,`id_product`)'"), 'REMOVE keyset index missing');
$check(substr_count($installer, "'idx_shop_claim' => '(`id_shop`,`status`,`available_at`,`id_queue`)'" ) === 2, 'per-shop queue claim indexes missing');
$check(str_contains($installer, 'INFORMATION_SCHEMA.STATISTICS'), 'performance index ensure must be reinstall-safe');

$check(str_contains($product, 'private ?string $jsonCache'), 'ProductData JSON serialization cache missing');
$check(str_contains($product, 'private array $hashCache'), 'ProductData domain hash cache missing');
$check(str_contains($product, '$this->jsonCache ??='), 'ProductData JSON cache not used');
$check(str_contains($product, "$this->hashCache['combination_stock']"), 'combination stock hash cache missing');

$check(str_contains($attributeMapping, 'private array $pairCache'), 'attribute mapping process cache missing');
$check(str_contains($attributeResolver, 'private array $availabilityCache'), 'attribute shop-availability cache missing');
$check(str_contains($categoryAuto, 'private array $preparedMetadata'), 'category metadata write-dedup cache missing');
$check(str_contains($categoryAuto, 'Conflicting Matterhorn category metadata'), 'category duplicate-key metadata conflict guard missing');
$check(str_contains($categoryAuto, 'private array $availabilityCache'), 'category availability cache missing');
$check(str_contains($categorySync, 'private array $hierarchyCache'), 'category ancestor hierarchy cache missing');
$check(str_contains($featureMapping, 'private array $pairCache'), 'feature mapping process cache missing');
$check(str_contains($featureMapping, '$this->pairCache[$this->cacheKey'), 'feature auto-create must seed process cache');

echo "High-volume performance contract: OK\n";
