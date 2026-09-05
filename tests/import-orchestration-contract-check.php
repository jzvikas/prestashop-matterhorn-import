<?php
$root = dirname(__DIR__);
$files = [
    'stage' => file_get_contents($root . '/src/Import/ImportStage.php'),
    'snapshot' => file_get_contents($root . '/src/Repository/SnapshotRepository.php'),
    'mapping' => file_get_contents($root . '/src/Repository/MappingRepository.php'),
    'recovery' => file_get_contents($root . '/src/Product/InterruptedCreateRecovery.php'),
    'queue' => file_get_contents($root . '/src/Repository/ImageQueueRepository.php'),
    'safety' => file_get_contents($root . '/src/Util/DatabaseSafety.php'),
    'command' => file_get_contents($root . '/src/Command/ImportCommand.php'),
    'services' => file_get_contents($root . '/config/services.yml'),
];
foreach ($files as $name => $content) { if ($content === false) { throw new RuntimeException('Missing IMPORT file: ' . $name); } }
$checks = [
    ['stage','READ must complete before IMPORT'], ['stage','InterruptedCreateRecovery'], ['stage','CombinationAttributeResolver'],
    ['stage','FeatureSynchronizer'], ['stage','ImageQueueRepository'], ['stage','SAVEPOINT'], ['stage','@@session.in_transaction'],
    ['snapshot','MAX_FETCH_PAYLOAD_BYTES'], ['snapshot','newRows'], ['mapping','last_seen_run_id'],
    ['recovery','date_add'], ['recovery','li_matterhornim_99dfbf_mapping'], ['queue','url_hash'],
    ['queue',"status='processing'"], ['safety','INNODB'], ['safety','attribute_group'], ['safety','manufacturer_shop'],
    ['command','matterhornimport:import'], ['command','--batch'], ['services','Lp\\MatterhornImport\\Command\\ImportCommand'],
];
foreach ($checks as [$file,$needle]) { if (!str_contains($files[$file], $needle)) { throw new RuntimeException('IMPORT contract missing ' . $needle . ' in ' . $file); } }
echo "IMPORT orchestration contract: OK\n";
