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
$categoryMapping = (string) file_get_contents($root . '/src/Repository/CategoryMappingRepository.php');
$categorySync = (string) file_get_contents($root . '/src/Category/CategorySynchronizer.php');
$featureMapping = (string) file_get_contents($root . '/src/Repository/FeatureMappingRepository.php');
$imageState = (string) file_get_contents($root . '/src/Repository/ImageStateRepository.php');
$revalidation = (string) file_get_contents($root . '/src/Image/ImageRevalidationScheduler.php');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$check = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) { $fail($message); }
};

$check(str_contains($source, 'new \\XMLReader()'), 'Matterhorn source must stream through XMLReader');
$check(str_contains($source, 'private function readProduct(\\XMLReader $reader, int $record): array'), 'Matterhorn source must stream the current product without whole-record materialization');
$check(!str_contains($source, 'readOuterXML'), 'source must not materialize a whole product XML string');
$check(!str_contains($source, 'simplexml_load_string'), 'source must not reparse a whole product through SimpleXML');
$check(!str_contains($source, 'file_get_contents($path)'), 'source must never read the entire XML into memory');
$check(!str_contains($source, 'simplexml_load_file'), 'source must never build whole-feed SimpleXML tree');
$check(str_contains($source, 'MAX_SOURCE_RECORD_BYTES = 4194304'), 'source per-product decoded-text bound missing');
$check(str_contains($source, 'MAX_SOURCE_FIELD_BYTES = 2097152'), 'source per-field decoded-text bound missing');
$check(str_contains($source, 'MAX_IMAGES_PER_PRODUCT = 1000'), 'source image fan-out bound missing');
$check(str_contains($source, 'MAX_OPTIONS_PER_PRODUCT = 5000'), 'source option fan-out bound missing');
$check(str_contains($source, 'readImageUrlElement'), 'source bounded optional image URL reader missing');
$check(str_contains($source, 'skipCurrentElementCounting'), 'ignored source elements must still contribute to the record byte budget');
$check(str_contains($source, 'LIBXML_COMPACT'), 'XMLReader must use compact parser mode');
$check(str_contains($read, 'MAX_PRODUCT_PAYLOAD_BYTES = 2097152'), 'READ per-product payload bound missing');
$check(str_contains($read, 'MAX_BATCH_PAYLOAD_BYTES = 8388608'), 'READ batch payload bound missing');
$check(str_contains($read, 'WRITE_BATCH = 500'), 'READ bounded write batch missing');
$check(str_contains($snapshots, 'MAX_FETCH_PAYLOAD_BYTES = 8388608'), 'snapshot fetch payload bound missing');
$check(str_contains($snapshots, 'MAX_WRITE_SQL_BYTES = 8388608'), 'snapshot escaped SQL write bound missing');
$check(str_contains($snapshots, "s.source_key>'"), 'source-key keyset pagination missing');
$check(str_contains($snapshots, 'm.id_product>'), 'product-id keyset pagination missing');
$check(str_contains($snapshots, 'function imageManifestRowsForSourceKeys'), 'bounded keyed image manifest lookup missing');

$check(str_contains($installer, "'idx_shop_source_run' => '(`id_shop`,`source`,`id_run`)'"), 'latest-run index missing');
$check(str_contains($installer, "'idx_feed_product' => '(`id_shop`,`source`,`out_of_feed`,`id_product`)'"), 'REMOVE keyset index missing');
$check(str_contains($installer, "'idx_revalidate' => '(`id_shop`,`source`,`updated_at`,`source_key`)'"), 'image stale-revalidation index missing');
$check(substr_count($installer, "'idx_shop_claim' => '(`id_shop`,`status`,`available_at`,`id_queue`)'" ) === 2, 'per-shop queue claim indexes missing');
$check(str_contains($installer, 'INFORMATION_SCHEMA.STATISTICS'), 'performance index ensure must be reinstall-safe');
$check(str_contains($imageState, 'function staleSourceKeys'), 'bounded stale image-state discovery missing');
$check(str_contains($imageState, 'LIMIT %d'), 'stale image-state discovery must stay LIMIT bounded');
$check(str_contains($imageState, 'updated_at<=DATE_SUB'), 'stale image-state discovery must be age bounded');
$check(str_contains($revalidation, '$limit = max(1, min(5000, $limit))'), 'image revalidation product bound missing');
$check(str_contains($revalidation, 'payload_window_deferred'), 'image revalidation payload-window deferral visibility missing');

$check(str_contains($product, 'private ?string $jsonCache'), 'ProductData JSON serialization cache missing');
$check(str_contains($product, 'private array $hashCache'), 'ProductData domain hash cache missing');
$check(str_contains($product, '$this->jsonCache ??='), 'ProductData JSON cache not used');
$check(str_contains($product, 'private ?array $combinationHashCache = null'), 'bounded two-hash combination cache missing');
$check(str_contains($product, "return \$this->combinationHashes()['structure'];"), 'combination structure hash must use shared projection pass');
$check(str_contains($product, "return \$this->combinationHashes()['stock'];"), 'combination stock hash must use shared projection pass');
$check(str_contains($product, "'structure' => \$this->hashValue([") && str_contains($product, "'stock' => \$this->hashValue(\$stock)"), 'shared projection pass must cache only final combination hashes');
$check(substr_count($product, 'combinationAttributeTokens(') === 2, 'combination semantic-token projection must have one implementation call site plus method declaration');
$check(!str_contains($product, 'combinationProjection(bool $stockOnly)'), 'legacy duplicate combination projection passes must be removed');

$check(str_contains($attributeMapping, 'private array $pairCache'), 'attribute mapping process cache missing');
$check(str_contains($attributeResolver, 'private array $availabilityCache'), 'attribute shop-availability cache missing');
$check(str_contains($categoryAuto, 'private array $preparedMetadata'), 'category metadata write-dedup cache missing');
$check(str_contains($categoryAuto, 'Conflicting Matterhorn category metadata'), 'category duplicate-key metadata conflict guard missing');
$check(str_contains($categoryAuto, 'private array $availabilityCache'), 'category availability cache missing');
$check(str_contains($categoryAuto, 'private array $childMap'), 'category child lookup process cache missing');
$check(str_contains($categoryAuto, 'MAX_PATH_DEPTH = 32'), 'category path-depth bound missing');
$check(str_contains($categoryAuto, "'lpimp:cat:'"), 'category auto-create must use shared cross-import advisory lock namespace');
$check(substr_count($categoryAuto, '), true, false)') >= 2, 'category live path/child reads must bypass Db query cache');
$check(str_contains($categoryMapping, '), true, false)'), 'category mapping preload must bypass Db query cache');
$check(str_contains($categorySync, 'private array $hierarchyCache'), 'category ancestor hierarchy cache missing');
$check(str_contains($categorySync, 'private function liveHierarchy'), 'category hierarchy cache must have a fresh topology fence');
$check(str_contains($categorySync, 'leaf.nleft BETWEEN parent.nleft AND parent.nright'), 'category hierarchy fence must use current nested-set topology');
$check(str_contains($categorySync, 'leaf_shop') && str_contains($categorySync, 'parent_shop'), 'category hierarchy fence must stay target-shop scoped for leaf and ancestors');
$check(str_contains($categorySync, '), true, false);'), 'category hierarchy live read must bypass Db query cache');
$check(str_contains($categorySync, 'Mapped category is unavailable in target shop'), 'category hierarchy cache must fail closed on deleted/unassociated leaves');
$check(str_contains($featureMapping, 'private array $pairCache'), 'feature mapping process cache missing');
$check(str_contains($featureMapping, '$this->pairCache[$this->cacheKey'), 'feature auto-create must seed process cache');

echo "High-volume performance contract: OK\n";
