<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'src/Command/RetryCommand.php',
    'src/Command/DoctorCommand.php',
    'src/Command/StatusCommand.php',
    'src/Repository/ErrorRepository.php',
    'src/Command/GcCommand.php',
    'src/Util/Diagnostics.php',
    'src/Gc/GcService.php',
];
foreach ($files as $file) {
    if (!is_file($root . '/' . $file)) { fwrite(STDERR, "Missing operations file: {$file}\n"); exit(1); }
}

$retry = file_get_contents($root . '/src/Command/RetryCommand.php');
$doctor = file_get_contents($root . '/src/Util/Diagnostics.php');
$status = file_get_contents($root . '/src/Command/StatusCommand.php');
$errors = file_get_contents($root . '/src/Repository/ErrorRepository.php');
$gc = file_get_contents($root . '/src/Gc/GcService.php');
$services = file_get_contents($root . '/config/services.yml');
$checks = [
    [$retry, "parent::__construct('matterhornimport:retry')", 'retry command'],
    [$retry, "['image','new-product','all']", 'explicit retry domains'],
    [$doctor, "version_compare($psVersion, '9.1.0', '>=')", 'PrestaShop 9.1 lower bound'],
    [$doctor, "version_compare($psVersion, '9.2.0', '<')", 'PrestaShop 9.1 upper bound'],
    [$doctor, 'assertTransactionalCore()', 'doctor database safety'],
    [$doctor, "'mbstring'", 'doctor mbstring requirement'],
    [$doctor, "'source_policy_hash'", 'doctor READ policy schema check'],
    [$doctor, "'combination_stock_hash'", 'doctor mapping hash schema check'],
    [$doctor, "'size-group-mappings'", 'doctor Size group mapping integrity'],
    [$doctor, "'size-value-mappings'", 'doctor Size value mapping integrity'],
    [$doctor, "'image-source-queue-index'", 'doctor source image-queue index check'],
    [$doctor, "'image-revalidation-index'", 'doctor stale image revalidation index check'],
    [$doctor, "['id_shop','source','updated_at','source_key']", 'doctor revalidation index column order'],
    [$doctor, 'INFORMATION_SCHEMA.COLUMNS', 'doctor column-level schema validation'],
    [$doctor, 'INFORMATION_SCHEMA.STATISTICS', 'doctor index-level schema validation'],
    [$doctor, 'locked_until<=NOW()', 'expired lease diagnostics'],
    [$status, "parent::__construct('matterhornimport:status')", 'status command'],
    [$status, "'new_products'=>$this->newProducts->counts", 'new-product status visibility'],
    [$status, "'issues_total'", 'status total persisted issue visibility'],
    [$status, "'errors_total'=>$runId > 0 ? $this->errors->countErrorsForRun", 'status true-error severity'],
    [$status, "'warnings_total'=>$runId > 0 ? $this->errors->countWarningsForRun", 'status warning severity'],
    [$errors, "private const WARNING_PREFIX = 'WARNING: '", 'warning persistence marker'],
    [$errors, 'function countWarningsForRun', 'warning counter'],
    [$errors, 'function countErrorsForRun', 'true error counter'],
    [$gc, 'maxRows', 'GC row budget'],
    [$gc, 'timeLimitSeconds', 'GC time budget'],
    [$gc, "status='done'", 'GC only completed queue jobs'],
    [$gc, 'EXISTS (SELECT 1', 'new-product mapping retention guard'],
    [$gc, "newer.id_shop=r.id_shop AND newer.source=r.source AND newer.id_run>r.id_run", 'latest shop/source snapshot retention guard'],
    [$gc, "li_matterhornim_99dfbf_snapshot", 'snapshot GC must target module snapshot table'],
    [$services, 'Lp\\MatterhornImport\\Command\\GcCommand:', 'GC service registration'],
];
foreach ($checks as [$haystack, $needle, $label]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "Operations contract: OK\n";
