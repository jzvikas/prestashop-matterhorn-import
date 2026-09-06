<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'src/Command/RetryCommand.php',
    'src/Command/DoctorCommand.php',
    'src/Command/StatusCommand.php',
    'src/Repository/ErrorRepository.php',
    'src/Repository/ImageQueueRepository.php',
    'src/Repository/NewProductQueueRepository.php',
    'src/Repository/ImageOrphanRepository.php',
    'src/Command/GcCommand.php',
    'src/Util/Diagnostics.php',
    'src/Gc/GcService.php',
];
foreach ($files as $file) {
    if (!is_file($root . '/' . $file)) { fwrite(STDERR, "Missing operations file: {$file}\n"); exit(1); }
}

$retry = (string) file_get_contents($root . '/src/Command/RetryCommand.php');
$doctor = (string) file_get_contents($root . '/src/Util/Diagnostics.php');
$status = (string) file_get_contents($root . '/src/Command/StatusCommand.php');
$errors = (string) file_get_contents($root . '/src/Repository/ErrorRepository.php');
$imageQueue = (string) file_get_contents($root . '/src/Repository/ImageQueueRepository.php');
$newProductQueue = (string) file_get_contents($root . '/src/Repository/NewProductQueueRepository.php');
$imageOrphans = (string) file_get_contents($root . '/src/Repository/ImageOrphanRepository.php');
$gc = (string) file_get_contents($root . '/src/Gc/GcService.php');
$services = (string) file_get_contents($root . '/config/services.yml');
$checks = [
    [$retry, "parent::__construct('matterhornimport:retry')", 'retry command'],
    [$retry, "['image','new-product','all']", 'explicit retry domains'],
    [$retry, 'SourceInterface', 'retry command must resolve active supplier source'],
    [$retry, '$source = trim($this->sourceAdapter->name())', 'retry command must resolve concrete source once'],
    [$retry, '$this->images->retryFailed($source, $shopId, $limit)', 'image retry command must pass source scope'],
    [$retry, '$this->newProducts->retryFailed($source, $shopId, $limit)', 'new-product retry command must pass source scope'],
    [$imageQueue, 'public function retryFailed(string $source', 'image retry repository must require source scope'],
    [$imageQueue, '$limit = max(1, min(100000, $limit));', 'image retry repository limit must be bounded'],
    [$imageQueue, "WHERE status='failed' AND source='", 'image retry reset must recheck failed status and source'],
    [$imageQueue, 'return (int) $db->Affected_Rows();', 'image retry reset must report rows actually reopened'],
    [$newProductQueue, 'public function retryFailed(string $source', 'new-product retry repository must require source scope'],
    [$newProductQueue, '$limit = max(1, min(100000, $limit));', 'new-product retry repository limit must be bounded'],
    [$newProductQueue, "WHERE status='failed' AND source='", 'new-product retry reset must recheck failed status and source'],
    [$newProductQueue, 'return (int) $db->Affected_Rows();', 'new-product retry reset must report rows actually reopened'],
    [$imageQueue, 'public function counts(?int $shopId = null, ?string $source = null)', 'image counts must support source scope'],
    [$newProductQueue, 'public function counts(?int $shopId = null, ?string $source = null)', 'new-product counts must support source scope'],
    [$imageOrphans, 'public function due(int $limit, ?int $shopId = null, ?string $source = null)', 'orphan due scan must support source scope'],
    [$imageOrphans, 'scopeWhere .= " AND source=', 'orphan due scan must apply source predicate'],
    [$imageOrphans, 'attempts<=1 THEN 900', 'first orphan defer must back off 15 minutes using post-increment attempts'],
    [$imageOrphans, 'attempts<=3 THEN 3600', 'orphan defer must back off to one hour'],
    [$imageOrphans, 'attempts<=6 THEN 21600', 'orphan defer must back off to six hours'],
    [$imageOrphans, 'ELSE 86400', 'orphan defer must cap at 24 hours'],
    [$imageOrphans, "ORDER BY id_orphan LIMIT ' . \$limit,", 'orphan due list must expose explicit query arguments'],
    [$doctor, 'version_compare($psVersion, \'9.1.0\', \'>=\')', 'PrestaShop 9.1 lower bound'],
    [$doctor, 'version_compare($psVersion, \'9.2.0\', \'<\')', 'PrestaShop 9.1 upper bound'],
    [$doctor, 'assertTransactionalCore()', 'doctor database safety'],
    [$doctor, "'mbstring'", 'doctor mbstring requirement'],
    [$doctor, "'source_policy_hash'", 'doctor READ policy schema check'],
    [$doctor, "'combination_stock_hash'", 'doctor mapping hash schema check'],
    [$doctor, "'product-ownership-index'", 'doctor exclusive product ownership index check'],
    [$doctor, "'size-group-mappings'", 'doctor Size group mapping integrity'],
    [$doctor, "'size-value-mappings'", 'doctor Size value mapping integrity'],
    [$doctor, "'image-source-queue-index'", 'doctor source image-queue index check'],
    [$doctor, "'image-revalidation-index'", 'doctor stale image revalidation index check'],
    [$doctor, "['id_shop','source','updated_at','source_key']", 'doctor revalidation index column order'],
    [$doctor, 'INFORMATION_SCHEMA.COLUMNS', 'doctor column-level schema validation'],
    [$doctor, 'INFORMATION_SCHEMA.STATISTICS', 'doctor index-level schema validation'],
    [$doctor, 'locked_until IS NOT NULL AND locked_until<=NOW()', 'expired lease diagnostics'],
    [$doctor, '$sourceName = trim($this->source->name())', 'doctor must resolve concrete source once'],
    [$doctor, "AND source='\" . \$sourceSql . \"'", 'doctor live queue/orphan state must be source scoped'],
    [$doctor, "getValue('SELECT @@max_allowed_packet', false)", 'doctor session state must bypass Db query cache'],
    [$doctor, "'new-product-done-ownership'", 'doctor completed new-product ownership drift check'],
    [$doctor, "q.id_product IS NOT NULL AND (m.id_product IS NULL OR m.id_product<>q.id_product)", 'doctor must exclude intentional superseded jobs and require exact mapping ownership'],
    [$doctor, "'done_product_without_exact_mapping='", 'doctor ownership-drift count visibility'],
    [$status, "parent::__construct('matterhornimport:status')", 'status command'],
    [$status, "'images' => \$this->images->counts(\$shopId, \$source)", 'image status must be source scoped'],
    [$status, "'new_products' => \$this->newProducts->counts(\$shopId, \$source)", 'new-product status must be source scoped'],
    [$status, "'image_orphans' => \$this->imageOrphans->count(\$shopId, \$source)", 'orphan status must be source scoped'],
    [$status, "'issues_total'", 'status total persisted issue visibility'],
    [$status, "'errors_total' => \$runId > 0 ? \$this->errors->countErrorsForRun", 'status true-error severity'],
    [$status, "'warnings_total' => \$runId > 0 ? \$this->errors->countWarningsForRun", 'status warning severity'],
    [$errors, "private const WARNING_PREFIX = 'WARNING: '", 'warning persistence marker'],
    [$errors, 'function countWarningsForRun', 'warning counter'],
    [$errors, 'function countErrorsForRun', 'true error counter'],
    [$gc, 'SourceInterface', 'GC must resolve active supplier source'],
    [$gc, 'RunRepository', 'GC retention boundary must validate a persisted run'],
    [$gc, '$source = trim($this->sourceAdapter->name())', 'GC must resolve concrete source once'],
    [$gc, 'GC requires a positive --max-rows or --time-limit bound', 'GC must reject fully unbounded maintenance runs'],
    [$gc, '$maxRows === 0 && $timeLimitSeconds === 0', 'GC must explicitly detect both disabled execution budgets'],
    [$gc, 'GC --keep-run requires a concrete --shop', 'GC keep-run must not use an ambiguous cross-shop boundary'],
    [$gc, '$this->runs->assertContext($keepRunId, $shopId, $source)', 'GC keep-run must match exact shop/source context'],
    [$gc, 'Snapshot GC requires a concrete shop retention context', 'snapshot deletion must fail closed without shop context'],
    [$gc, '$this->imageOrphans->due($limit, $shopId, $source)', 'GC orphan scan must pass source scope'],
    [$gc, "status='done' AND source='", 'GC queue cleanup must require source scope'],
    [$gc, "AND r.source='", 'snapshot GC must restrict source generation'],
    [$gc, "AND s.source='", 'image-state GC candidate scan must restrict source'],
    [$gc, "'source'=>\$source", 'GC result must expose resolved source scope'],
    [$gc, 'maxRows', 'GC row budget'],
    [$gc, 'timeLimitSeconds', 'GC time budget'],
    [$gc, 'private const ORPHAN_PAGE_LIMIT = 2000;', 'GC must respect orphan repository page cap'],
    [$gc, 'min($chunk, self::ORPHAN_PAGE_LIMIT)', 'orphan GC must compare EOF with effective page size'],
    [$gc, "status='done'", 'GC only completed queue jobs'],
    [$gc, 'EXISTS (SELECT 1', 'new-product mapping retention guard'],
    [$gc, "newer.id_shop=r.id_shop AND newer.source=r.source AND newer.id_run>r.id_run", 'latest shop/source snapshot retention guard'],
    [$gc, "li_matterhornim_99dfbf_snapshot", 'snapshot GC must target module snapshot table'],
    [$gc, 'NOT EXISTS (SELECT 1 FROM `', 'image-state GC must recheck live ownership during delete'],
    [$gc, "li_matterhornim_99dfbf_image_state", 'image-state GC must target module image state'],
    [$services, 'autoconfigure: true', 'Symfony command autoconfiguration'],
    [$services, "Lp\\MatterhornImport\\:\n", 'PSR-4 service discovery for module classes'],
];
foreach ($checks as [$haystack, $needle, $label]) {
    if (!str_contains($haystack, $needle)) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
if (str_contains($services, 'Lp\\MatterhornImport\\Command\\GcCommand:')) {
    fwrite(STDERR, "FAIL: GC command must rely on Symfony autoconfiguration, not a manual service entry\n");
    exit(1);
}

if (substr_count($imageOrphans, "true,\n            false") < 1) {
    fwrite(STDERR, "FAIL: image orphan mutable reads must bypass Db query cache\n");
    exit(1);
}
if (!str_contains($gc, "ORDER BY s.id_shop,s.source,s.source_key,s.url_hash LIMIT ' . \$limit,\n            true,\n            false")) {
    fwrite(STDERR, "FAIL: image-state GC candidate scan must bypass Db query cache\n");
    exit(1);
}

echo "Operations contract: OK\n";