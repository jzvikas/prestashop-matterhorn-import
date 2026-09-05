<?php
declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/src/Repository/ErrorRepository.php');

$required = [
    'function countForRun',
    'function countWarningsForRun',
    'function countErrorsForRun',
    "private const WARNING_PREFIX = 'WARNING: '",
];
foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: error observability contract missing {$needle}\n");
        exit(1);
    }
}

if (substr_count($source, "\n            false\n        );") < 3) {
    fwrite(STDERR, "FAIL: live error/warning counters must bypass PrestaShop Db query cache\n");
    exit(1);
}

if (!str_contains($source, "message LIKE '") || !str_contains($source, "message NOT LIKE '")) {
    fwrite(STDERR, "FAIL: warning and true-error counters must remain severity-separated\n");
    exit(1);
}

echo "Error observability contract: OK\n";
