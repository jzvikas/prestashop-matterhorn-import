<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/src/Command/ImagesReconcileCommand.php';
if (!is_file($path)) {
    fwrite(STDERR, "FAIL: missing image reconciliation command\n");
    exit(1);
}

$command = (string) file_get_contents($path);
$checks = [
    ['$maxItems === 0 && $timeLimit === 0', 'image reconciliation must detect both disabled execution bounds'],
    ['Image reconciliation requires a positive --max-items or --time-limit bound', 'image reconciliation must reject a fully unbounded invocation'],
    ['0 disables only this bound', 'image reconciliation option help must not describe zero as globally unlimited'],
    ['at least one execution bound must stay positive', 'image reconciliation time-limit help must document the hard bound requirement'],
    ['$this->reconciler->run($runId, $shopId, $batch, $maxItems, $timeLimit)', 'image reconciliation must preserve bounded reconciler arguments'],
];

foreach ($checks as [$needle, $label]) {
    if (!str_contains($command, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

$guardPos = strpos($command, '$maxItems === 0 && $timeLimit === 0');
$runPos = strpos($command, '$this->reconciler->run($runId, $shopId, $batch, $maxItems, $timeLimit)');
if ($guardPos === false || $runPos === false || $guardPos >= $runPos) {
    fwrite(STDERR, "FAIL: unbounded reconciliation guard must run before reconciliation work\n");
    exit(1);
}

if (substr_count($command, '=== 0 &&') !== 1) {
    fwrite(STDERR, "FAIL: image reconciliation hard-bound guard shape unexpectedly changed\n");
    exit(1);
}

echo "Image reconciliation execution bound contract: OK\n";
