<?php
$root = dirname(__DIR__);
$read = file_get_contents($root . '/src/Import/ReadStage.php');
$run = file_get_contents($root . '/src/Repository/RunRepository.php');
$policy = file_get_contents($root . '/src/Config/MatterhornPolicy.php');
$snapshot = file_get_contents($root . '/src/Repository/SnapshotRepository.php');
$command = file_get_contents($root . '/src/Command/ReadCommand.php');
$services = file_get_contents($root . '/config/services.yml');
foreach ([$read,$run,$policy,$snapshot,$command,$services] as $content) {
    if ($content === false) { throw new RuntimeException('READ orchestration file missing'); }
}
$checks = [
    [$read, 'Source changed since READ checkpoint'],
    [$read, 'Source XML changed while READ was running'],
    [$read, 'Matterhorn semantic configuration changed since READ checkpoint'],
    [$read, '$this->policy->snapshot($shopId, true)'],
    [$read, '$this->policy->hash($policySnapshot)'],
    [$read, 'duplicate source keys'],
    [$read, 'valid row count dropped below 80%'],
    [$read, 'START TRANSACTION'],
    [$read, 'MAX_PRODUCT_PAYLOAD_BYTES'],
    [$run, 'read_checkpoint'],
    [$run, 'source_fingerprint'],
    [$run, 'source_policy_hash'],
    [$policy, 'MATTERHORNIMPORT_SOURCE_LANGUAGE_ID'],
    [$policy, 'MATTERHORNIMPORT_CATEGORY_AUTO_CREATE'],
    [$policy, 'MATTERHORNIMPORT_FEATURE_AUTO_CREATE'],
    [$policy, 'MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME'],
    [$snapshot, 'li_matterhornim_99dfbf_snapshot'],
    [$snapshot, 'ON DUPLICATE KEY UPDATE'],
    [$command, "matterhornimport:read"],
    [$command, "--run"],
    [$services, "Lp\\MatterhornImport\\Command\\ReadCommand"],
    [$services, "console.command"],
];
foreach ($checks as [$haystack,$needle]) {
    if (!str_contains($haystack, $needle)) { throw new RuntimeException('READ contract missing: ' . $needle); }
}
echo "READ orchestration contract: OK\n";
