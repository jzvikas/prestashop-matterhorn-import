<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'runs' => 'src/Repository/RunRepository.php',
    'read' => 'src/Import/ReadStage.php',
    'import' => 'src/Import/ImportStage.php',
    'update' => 'src/Import/UpdateStage.php',
    'remove' => 'src/Import/RemoveStage.php',
    'runner' => 'src/Import/ImportRunner.php',
    'enqueue' => 'src/Command/NewProductsEnqueueCommand.php',
];

$source = [];
foreach ($paths as $name => $path) {
    $contents = file_get_contents($root . '/' . $path);
    if (!is_string($contents) || $contents === '') {
        fwrite(STDERR, "FAIL: stale generation fence source missing: {$path}\n");
        exit(1);
    }
    $source[$name] = $contents;
}

$guard = 'assertLatestCompletedReadGeneration';
foreach ([
    'public function ' . $guard . '(int $runId, int $shopId, string $source): void',
    '$latestRunId = $this->latestCompletedReadId($shopId, $source);',
    'if ($latestRunId > $runId)',
    'newer completed READ generation #%d exists for this shop/source',
] as $needle) {
    if (!str_contains($source['runs'], $needle)) {
        fwrite(STDERR, "FAIL: RunRepository generation fence missing: {$needle}\n");
        exit(1);
    }
}

$assertOrder = static function (string $contents, string $guardNeedle, string $laterNeedle, string $label): void {
    $guardPos = strpos($contents, $guardNeedle);
    $laterPos = strpos($contents, $laterNeedle);
    if ($guardPos === false || $laterPos === false || $guardPos >= $laterPos) {
        fwrite(STDERR, "FAIL: {$label} must fence stale completed READ generation before {$laterNeedle}\n");
        exit(1);
    }
};

$assertOrder(
    $source['read'],
    '$this->runs->' . $guard . '($runId, $shopId, (string) $run[\'source\'])',
    '$this->shopContext->activate($shopId)',
    'READ resume'
);
$assertOrder(
    $source['read'],
    '$this->runs->' . $guard . '($runId, $shopId, (string) $run[\'source\'])',
    '$this->prepareFreshRead(',
    'READ reset'
);

foreach (['import' => '$this->writer->create(', 'update' => '$this->writer->create('] as $stage => $catalogWrite) {
    $assertOrder(
        $source[$stage],
        '$this->runs->' . $guard . '($runId, $shopId, $source)',
        '$this->runs->resume($runId)',
        strtoupper($stage) . ' resume'
    );
    $assertOrder(
        $source[$stage],
        '$this->runs->' . $guard . '($runId, $shopId, $source)',
        $catalogWrite,
        strtoupper($stage) . ' catalog mutation'
    );
}

$assertOrder(
    $source['remove'],
    '$this->runs->' . $guard . '($runId, $shopId, $source)',
    '$this->mapping->countInFeedSource($shopId, $source)',
    'REMOVE dry-run planning'
);
$assertOrder(
    $source['remove'],
    '$this->runs->' . $guard . '($runId, $shopId, $source)',
    '$this->outOfFeedPolicy->apply($productId, $shopId)',
    'REMOVE destructive policy'
);

$runnerGuard = '$this->runs->' . $guard . '($runId, $shopId, $source)';
$assertOrder($source['runner'], $runnerGuard, '$this->runs->resume($runId)', 'RUN resume');
$assertOrder($source['runner'], $runnerGuard, '$this->read->run(', 'RUN READ execution');
if (!str_contains($source['runner'], '$executionStarted = false;')
    || !str_contains($source['runner'], '$executionStarted = true;')
    || !str_contains($source['runner'], 'if ($runId !== null && $executionStarted)')
) {
    fwrite(STDERR, "FAIL: preflight resume rejection must not rewrite retained run status as failed\n");
    exit(1);
}
$guardPos = strpos($source['runner'], $runnerGuard);
$resumePos = strpos($source['runner'], '$this->runs->resume($runId)', $guardPos === false ? 0 : $guardPos);
$startedPos = strpos($source['runner'], '$executionStarted = true;', $resumePos === false ? 0 : $resumePos);
if ($guardPos === false || $resumePos === false || $startedPos === false || !($guardPos < $resumePos && $resumePos < $startedPos)) {
    fwrite(STDERR, "FAIL: RUN must complete stale-generation preflight before execution is marked started\n");
    exit(1);
}

$enqueueGuard = '$this->runs->' . $guard . '($runId, $shopId, $source)';
$assertOrder($source['enqueue'], $enqueueGuard, '$this->queue->nextUnqueuedRows(', 'new-product enqueue scan');
$assertOrder($source['enqueue'], $enqueueGuard, '$this->queue->enqueueBatch(', 'new-product enqueue persistence');

if (substr_count($source['read'], $guard) !== 1
    || substr_count($source['import'], $guard) !== 1
    || substr_count($source['update'], $guard) !== 1
    || substr_count($source['remove'], $guard) !== 1
    || substr_count($source['runner'], $guard) !== 1
    || substr_count($source['enqueue'], $guard) !== 1
) {
    fwrite(STDERR, "FAIL: every stage/entrypoint must have exactly one explicit generation preflight fence\n");
    exit(1);
}

echo "Stale completed READ generation fence contract: OK\n";
