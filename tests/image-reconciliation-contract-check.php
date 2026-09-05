<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$reconciler = (string) file_get_contents($root . '/src/Image/ImageReconciler.php');
$processor = (string) file_get_contents($root . '/src/Image/PrestaImageProcessor.php');
$state = (string) file_get_contents($root . '/src/Repository/ImageStateRepository.php');
$snapshot = (string) file_get_contents($root . '/src/Repository/SnapshotRepository.php');
$command = (string) file_get_contents($root . '/src/Command/ImagesReconcileCommand.php');
$checks = [
    [$reconciler, 'Only the latest shop/source run may reconcile images'],
    [$reconciler, 'Image reconciliation blocked until all run image jobs are done'],
    [$reconciler, 'Desired image state is incomplete'],
    [$reconciler, 'canDeleteStateImage'],
    [$reconciler, 'hasOtherTargetShopStateRef'],
    [$processor, 'syncProductPlacement'],
    [$processor, 'Preserve any manual BO cover when Matterhorn has no desired images'],
    [$state, 'statesForProduct'],
    [$state, 'gcOrphans'],
    [$snapshot, 'imageManifestRows'],
    [$snapshot, 'm.out_of_feed=0'],
    [$command, 'matterhornimport:images:reconcile'],
];
foreach ($checks as [$haystack, $needle]) {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException('Image reconciliation contract missing: ' . $needle);
    }
}
echo "Image reconciliation contract: OK\n";
