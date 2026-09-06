<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/src/Command/NewProductsEnqueueCommand.php';
if (!is_file($path)) {
    fwrite(STDERR, "FAIL: missing new-product enqueue command\n");
    exit(1);
}

$command = (string) file_get_contents($path);
$checks = [
    ['$maxItems === 0 && $timeLimit === 0', 'enqueue must detect both disabled execution bounds'],
    ['New-product enqueue requires a positive --max-items or --time-limit bound', 'enqueue must reject a fully unbounded invocation'],
    ['0 disables only this bound', 'enqueue option help must not describe zero as globally unlimited'],
    ['at least one execution bound must stay positive', 'enqueue time-limit help must document the hard bound requirement'],
    ['$this->budget->start($maxItems, $timeLimit)', 'enqueue must preserve ExecutionBudget enforcement after validation'],
];

foreach ($checks as [$needle, $label]) {
    if (!str_contains($command, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

$guardPos = strpos($command, '$maxItems === 0 && $timeLimit === 0');
$sourcePos = strpos($command, '$source = $this->source->name()');
$budgetPos = strpos($command, '$this->budget->start($maxItems, $timeLimit)');
if ($guardPos === false || $sourcePos === false || $budgetPos === false || !($guardPos < $sourcePos && $sourcePos < $budgetPos)) {
    fwrite(STDERR, "FAIL: unbounded enqueue guard must run before supplier/run work and before budget activation\n");
    exit(1);
}

if (substr_count($command, '=== 0 &&') !== 1) {
    fwrite(STDERR, "FAIL: enqueue hard-bound guard shape unexpectedly changed\n");
    exit(1);
}

echo "New-product enqueue execution bound contract: OK\n";
