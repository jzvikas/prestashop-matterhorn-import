<?php
$root = dirname(__DIR__);
$stage = file_get_contents($root . '/src/Import/UpdateStage.php');
$snapshot = file_get_contents($root . '/src/Repository/SnapshotRepository.php');
$command = file_get_contents($root . '/src/Command/UpdateCommand.php');
$services = file_get_contents($root . '/config/services.yml');
foreach ([$stage,$snapshot,$command,$services] as $file) { if ($file === false) { throw new RuntimeException('UPDATE orchestration file missing'); } }
$checks = [
    [$stage,'READ and IMPORT must complete before UPDATE'], [$stage,'GranularProductWriterInterface'],
    [$stage,"['core','price','stock','category']"], [$stage,'old_combination_stock_hash'],
    [$stage,'product_shop_exists'], [$stage,'ImageQueueRepository'], [$stage,'SAVEPOINT'],
    [$stage,'Payload-only changes are supplier/runtime metadata'],
    [$snapshot,'changedRows'], [$snapshot,'product_exists'], [$snapshot,'old_feature_hash'],
    [$snapshot,'removedRows'], [$snapshot,'countRemoved'], [$command,'matterhornimport:update'],
    [$services,'Lp\\MatterhornImport\\Command\\UpdateCommand'],
];
foreach ($checks as [$haystack,$needle]) { if (!str_contains($haystack, $needle)) { throw new RuntimeException('UPDATE contract missing: ' . $needle); } }
if (str_contains($stage, "'old_payload_hash', 'payload_hash'")) { throw new RuntimeException('UPDATE must not route metadata-only payload differences into core catalog writes'); }
echo "UPDATE orchestration contract: OK\n";