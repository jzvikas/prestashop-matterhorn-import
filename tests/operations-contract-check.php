<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'src/Command/RetryCommand.php',
    'src/Command/DoctorCommand.php',
    'src/Command/StatusCommand.php',
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
$gc = file_get_contents($root . '/src/Gc/GcService.php');
$services = file_get_contents($root . '/config/services.yml');
$checks = [
    [$retry, "parent::__construct('matterhornimport:retry')", 'retry command'],
    [$retry, "['image','new-product','all']", 'explicit retry domains'],
    [$doctor, "version_compare($psVersion, '9.1.0', '>=')", 'PrestaShop 9.1 lower bound'],
    [$doctor, "version_compare($psVersion, '9.2.0', '<')", 'PrestaShop 9.1 upper bound'],
    [$doctor, 'assertTransactionalCore()', 'doctor database safety'],
    [$doctor, 'locked_until<=NOW()', 'expired lease diagnostics'],
    [$status, "parent::__construct('matterhornimport:status')", 'status command'],
    [$status, "'new_products'=>$this->newProducts->counts", 'new-product status visibility'],
    [$gc, 'maxRows', 'GC row budget'],
    [$gc, 'timeLimitSeconds', 'GC time budget'],
    [$gc, "status='done'", 'GC only completed queue jobs'],
    [$gc, 'EXISTS (SELECT 1', 'new-product mapping retention guard'],
    [$services, 'Lp\\MatterhornImport\\Command\\GcCommand:', 'GC service registration'],
];
foreach ($checks as [$haystack, $needle, $label]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "Operations contract: OK\n";
