<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$stage = (string) file_get_contents($root . '/src/Import/RemoveStage.php');
$mapping = (string) file_get_contents($root . '/src/Repository/MappingRepository.php');
$snapshot = (string) file_get_contents($root . '/src/Repository/SnapshotRepository.php');
$installer = (string) file_get_contents($root . '/src/Installer.php');
$upgrade = (string) file_get_contents($root . '/upgrade/upgrade-0.1.1.php');
$module = (string) file_get_contents($root . '/matterhornimport.php');
$command = (string) file_get_contents($root . '/src/Command/RemoveCommand.php');

$checks = [
    [$stage, 'MATTERHORNIMPORT_MAX_REMOVE_PERCENT'],
    [$stage, 'REMOVE safety guard blocked'],
    [$stage, 'ItemTransactionGuard'],
    [$stage, '$this->transactionGuard->arm($db)'],
    [$stage, '$this->transactionGuard->restoreAfterExternalCommit()'],
    [$stage, '$this->transactionGuard->recoveryCount() > 0'],
    [$stage, '$this->mapping->lockProductOwnership($shopId, $source, $sourceKey, $productId)'],
    [$stage, '$this->mapping->markOutOfFeed($shopId, $source, $sourceKey, $productId, $runId)'],
    [$stage, "getValue('SELECT @@session.in_transaction', false)"],
    [$mapping, 'out_of_feed'],
    [$mapping, 'countInFeedSource'],
    [$mapping, 'AND id_product=%d'],
    [$mapping, 'Affected_Rows() !== 1'],
    [$mapping, 'mapping ownership changed before out-of-feed completion'],
    [$snapshot, 'm.out_of_feed=1'],
    [$snapshot, 'm.out_of_feed=0'],
    [$installer, 'upgradeMappingState'],
    [$installer, 'idx_feed_state'],
    [$upgrade, 'upgrade_module_0_1_1'],
    [$module, 'Maximum REMOVE percentage'],
    [$command, 'matterhornimport:remove'],
    [$command, 'dry-run'],
];
foreach ($checks as [$haystack, $needle]) {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException('REMOVE contract missing: ' . $needle);
    }
}

if (str_contains($stage, 'SAVEPOINT matterhorn_remove_item') || str_contains($stage, 'private const SAVEPOINT')) {
    throw new RuntimeException('REMOVE must use one transaction per product, not a batch savepoint boundary');
}

// REMOVE safety is a behavioral invariant. Do not pin the regression test to a
// historical module version; legitimate later fixes must not require falsifying
// the version or weakening the removal fence to satisfy this contract.
if (!preg_match("/\\$this->version\\s*=\\s*'\\d+\\.\\d+\\.\\d+';/", $module)) {
    throw new RuntimeException('Module must expose a valid semantic version');
}

echo "REMOVE orchestration contract: OK\n";
