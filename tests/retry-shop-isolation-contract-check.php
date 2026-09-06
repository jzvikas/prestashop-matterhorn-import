<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$command = file_get_contents($root . '/src/Command/RetryCommand.php');

$checks = [
    [$command, "addOption('shop', null, InputOption::VALUE_REQUIRED, 'Target shop ID')", 'retry must require an explicit target shop'],
    [$command, 'CommandInput::positiveInt($input->getOption(\'shop\'), \'--shop\')', 'retry must reject missing/non-positive shop IDs'],
    [$command, '$this->settings->retryLimit($shopId)', 'retry default limit must come from the selected shop settings'],
    [$command, '$this->images->retryFailed($source, $shopId, $limit)', 'image retry reset must remain shop-scoped'],
    [$command, '$this->newProducts->retryFailed($source, $shopId, $limit)', 'new-product retry reset must remain shop-scoped'],
];

foreach ($checks as [$haystack, $needle, $label]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

if (str_contains((string) $command, 'optionalPositiveInt($input->getOption(\'shop\')')) {
    fwrite(STDERR, "FAIL: retry must not retain an implicit all-shop write mode\n");
    exit(1);
}

if (str_contains((string) $command, '$shopId === null ? 1000')) {
    fwrite(STDERR, "FAIL: retry must not fall back to a global all-shop limit path\n");
    exit(1);
}

echo "Retry shop isolation contract: OK\n";
