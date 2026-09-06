<?php
declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/src/Repository/ErrorRepository.php');

$required = [
    'function countForRun',
    'function countWarningsForRun',
    'function countErrorsForRun',
    "private const WARNING_PREFIX = 'WARNING: '",
    "throw new \\RuntimeException(\$persistenceError);",
    "getMsgError()",
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

$failureBranch = strstr($source, 'if (!$ok) {');
if ($failureBranch === false || !str_contains($failureBranch, 'throw new \\RuntimeException($persistenceError);')) {
    fwrite(STDERR, "FAIL: observability persistence failure must fail closed instead of logging and continuing\n");
    exit(1);
}

$throwPos = strpos($failureBranch, 'throw new \\RuntimeException($persistenceError);');
$methodEnd = strpos($failureBranch, "\n    }\n\n    public function purgeStage");
if ($throwPos === false || $methodEnd === false || $throwPos > $methodEnd) {
    fwrite(STDERR, "FAIL: persistence failure throw must remain inside ErrorRepository::add()\n");
    exit(1);
}

echo "Error observability contract: OK\n";
