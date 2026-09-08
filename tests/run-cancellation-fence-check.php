<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'runs' => 'src/Repository/RunRepository.php',
    'guard' => 'src/Util/ItemTransactionGuard.php',
    'read' => 'src/Import/ReadStage.php',
    'import' => 'src/Import/ImportStage.php',
    'update' => 'src/Import/UpdateStage.php',
    'remove' => 'src/Import/RemoveStage.php',
    'failure' => 'src/Util/RunFailureRecorder.php',
];
$source = [];
foreach ($files as $name => $path) {
    $contents = file_get_contents($root . '/' . $path);
    if (!is_string($contents) || $contents === '') {
        fwrite(STDERR, "FAIL: cancellation fence source missing: {$path}\n");
        exit(1);
    }
    $source[$name] = $contents;
}

foreach ([
    'public function lockRunning(int $runId): void',
    'SELECT status FROM `',
    ' LIMIT 1 FOR UPDATE',
    "!== 'running'",
    "AND status<>'cancelled'",
    "AND status='running'",
    'terminal state preserved',
] as $needle) {
    if (!str_contains($source['runs'], $needle)) {
        fwrite(STDERR, "FAIL: RunRepository cancellation CAS/row fence missing {$needle}\n");
        exit(1);
    }
}

$readStart = strpos($source['read'], 'private function flushBatch(');
$readEnd = $readStart === false ? false : strpos($source['read'], 'private function persistRunCheckpointBestEffort(', $readStart);
$readFlush = ($readStart !== false && $readEnd !== false) ? substr($source['read'], $readStart, $readEnd - $readStart) : '';
if ($readFlush === '' || !str_contains($readFlush, '$this->runs->commitReadProgress(')) {
    fwrite(STDERR, "FAIL: READ batch must keep snapshot writes and active-run checkpoint CAS in one transaction\n");
    exit(1);
}

foreach (['import','update'] as $stage) {
    if (!str_contains($source[$stage], 'beginItemSavepoint($db, $runId)')
        || !str_contains($source[$stage], '$this->transactionGuard->arm($db, self::SAVEPOINT, $runId)')
    ) {
        fwrite(STDERR, 'FAIL: ' . strtoupper($stage) . " item transaction is not fenced by run generation\n");
        exit(1);
    }
}
if (!str_contains($source['remove'], '$this->transactionGuard->arm($db, null, $runId)')) {
    fwrite(STDERR, "FAIL: REMOVE item transaction is not fenced by run generation\n");
    exit(1);
}

foreach ([
    'private ?int $runId = null;',
    'private ?RunRepository $runs = null',
    '$this->requireRunRepository()->lockRunning($runId);',
    '$this->requireRunRepository()->lockRunning($this->runId);',
    'RunRepository is required when arming a run cancellation fence',
    '$this->db->execute(\'ROLLBACK\');',
] as $needle) {
    if (!str_contains($source['guard'], $needle)) {
        fwrite(STDERR, "FAIL: ItemTransactionGuard cancellation recovery fence missing {$needle}\n");
        exit(1);
    }
}
$restore = strpos($source['guard'], 'public function restoreAfterExternalCommit(): bool');
$startTx = $restore === false ? false : strpos($source['guard'], "execute('START TRANSACTION')", $restore);
$relock = $startTx === false ? false : strpos($source['guard'], '$this->requireRunRepository()->lockRunning($this->runId);', $startTx);
if ($restore === false || $startTx === false || $relock === false || !($restore < $startTx && $startTx < $relock)) {
    fwrite(STDERR, "FAIL: external-commit recovery must recreate the transaction before reacquiring the run row fence\n");
    exit(1);
}

if (!str_contains($source['failure'], "=== 'cancelled'") || !str_contains($source['failure'], 'return;')) {
    fwrite(STDERR, "FAIL: cancellation-induced worker abort must preserve cancelled terminal state\n");
    exit(1);
}

if (!defined('_DB_PREFIX_')) { define('_DB_PREFIX_', 'mh_test_'); }
if (!function_exists('pSQL')) {
    function pSQL(string $value, bool $htmlOK = false): string { return $value; }
}
if (!class_exists('Db', false)) {
    final class Db
    {
        public static string $status = 'running';
        public static string $lastSql = '';
        private static ?self $instance = null;
        public static function getInstance(): self { return self::$instance ??= new self(); }
        public function executeS(string $sql, bool $array = true, bool $useCache = true): array|false
        {
            self::$lastSql = $sql;
            return [['status' => self::$status]];
        }
        public function getMsgError(): string { return ''; }
    }
}
require_once $root . '/src/Repository/RunRepository.php';
$repository = new \Lp\MatterhornImport\Repository\RunRepository();
$repository->lockRunning(7);
if (!str_contains(\Db::$lastSql, 'LIMIT 1 FOR UPDATE')) {
    fwrite(STDERR, "FAIL: executable run fence did not use a bounded row lock with valid MariaDB clause ordering\n");
    exit(1);
}
\Db::$status = 'cancelled';
$blocked = false;
try { $repository->lockRunning(7); }
catch (\RuntimeException) { $blocked = true; }
if (!$blocked) {
    fwrite(STDERR, "FAIL: executable run fence allowed cancelled worker execution\n");
    exit(1);
}

echo "Run cancellation stale-worker fence: OK\n";
