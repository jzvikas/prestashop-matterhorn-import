<?php

$root = dirname(__DIR__);
$commands = [
    $root . '/src/Command/ImagesCommand.php' => 'image worker',
    $root . '/src/Command/NewProductsCommand.php' => 'new-product worker',
];

foreach ($commands as $path => $label) {
    $command = file_get_contents($path);
    if ($command === false) {
        fwrite(STDERR, "FAIL: could not read {$label} command\n");
        exit(1);
    }

    $checks = [
        ['$remainingMs = (int) floor(max(0.0, $maxRuntime - (microtime(true) - $started)) * 1000);', 'must derive idle sleep from the remaining runtime budget'],
        ['if ($remainingMs <= 0) { break; }', 'must stop instead of sleeping after the runtime budget is exhausted'],
        ['$sleepMs = min($idleSleepMs, $remainingMs);', 'must cap idle sleep to the remaining runtime budget'],
        ['if ($sleepMs > 0) { usleep($sleepMs * 1000); }', 'must sleep only for the bounded interval'],
    ];

    foreach ($checks as [$needle, $message]) {
        if (!str_contains($command, $needle)) {
            fwrite(STDERR, "FAIL: {$label} {$message}\n");
            exit(1);
        }
    }

    if (str_contains($command, 'usleep($idleSleepMs * 1000);')) {
        fwrite(STDERR, "FAIL: {$label} must not use an unbounded idle sleep directly\n");
        exit(1);
    }
}

echo "Worker runtime budget contract: OK\n";
