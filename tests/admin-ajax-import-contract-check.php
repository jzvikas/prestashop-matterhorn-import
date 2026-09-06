<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'controller' => (string) file_get_contents($root . '/src/Controller/ImportController.php'),
    'presenter' => (string) file_get_contents($root . '/src/Admin/ImportStatusProvider.php'),
    'errors' => (string) file_get_contents($root . '/src/Admin/AdminErrorReporter.php'),
    'runs' => (string) file_get_contents($root . '/src/Repository/RunRepository.php'),
    'runner' => (string) file_get_contents($root . '/src/Import/ImportRunner.php'),
    'routes' => (string) file_get_contents($root . '/config/routes.yml'),
    'template' => (string) file_get_contents($root . '/views/templates/admin/import/index.html.twig'),
    'configuration' => (string) file_get_contents($root . '/views/templates/admin/configuration.html.twig'),
    'js' => (string) file_get_contents($root . '/views/js/admin-import.js'),
];

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$check = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) { $fail($message); }
};

foreach ($files as $name => $content) {
    $check($content !== '', 'AJAX import file missing or empty: ' . $name);
}

foreach ([
    'matterhorn_import_ajax:',
    'matterhorn_import_ajax_start:',
    'matterhorn_import_ajax_batch:',
    'matterhorn_import_ajax_status:',
    'matterhorn_import_ajax_cancel:',
] as $route) {
    $check(str_contains($files['routes'], $route), 'AJAX import route missing: ' . $route);
}

$check(str_contains($files['controller'], "'matterhorn_ajax_import'"), 'AJAX import CSRF token missing');
$check(str_contains($files['controller'], 'runBounded('), 'AJAX import must reuse bounded ImportRunner orchestration');
$check(str_contains($files['controller'], 'self::AJAX_TIME_LIMIT_SECONDS'), 'AJAX import per-request time budget missing');
$check(str_contains($files['controller'], 'self::MAX_AJAX_BATCH'), 'AJAX import batch hard bound missing');
$check(str_contains($files['controller'], 'assertContext('), 'AJAX import run/shop/source fence missing');
$check(str_contains($files['controller'], 'ImportLock'), 'AJAX import start/cancel race lock missing');

$check(str_contains($files['runs'], 'function findActive('), 'active AJAX run lookup missing');
$check(str_contains($files['runs'], 'function recent('), 'recent AJAX run lookup missing');
$check(str_contains($files['runs'], 'function cancel('), 'AJAX run cancellation missing');
$check(str_contains($files['runs'], "`status`='cancelled'"), 'cancelled terminal run state missing');
$check(str_contains($files['runner'], "['completed', 'cancelled']"), 'runner must reject cancelled run resume');

$check(!str_contains($files['presenter'], '\\Db::'), 'AJAX status presenter must not query database tables on every poll');
$check(str_contains($files['presenter'], "'indeterminate'"), 'honest indeterminate stage progress missing');
$check(str_contains($files['presenter'], "'source_total'"), 'durable READ telemetry missing');
$check(str_contains($files['presenter'], "'import_done'"), 'durable IMPORT telemetry missing');
$check(str_contains($files['presenter'], "'update_done'"), 'durable UPDATE telemetry missing');
$check(str_contains($files['presenter'], "'remove_done'"), 'durable REMOVE telemetry missing');

$check(str_contains($files['template'], 'AJAX batch import'), 'AJAX import BO card missing');
$check(str_contains($files['template'], 'matterhorn-progress-bar'), 'AJAX import progress UI missing');
$check(str_contains($files['template'], 'Recent import runs'), 'AJAX recent-run table missing');
$check(str_contains($files['configuration'], "path('matterhorn_import_ajax')"), 'settings page AJAX import link missing');

$check(str_contains($files['js'], 'X-Requested-With'), 'AJAX request marker missing');
$check(str_contains($files['js'], 'response.text()'), 'non-JSON response-safe parsing missing');
$check(str_contains($files['js'], 'response.status >= 502 && response.status <= 504'), 'transient HTTP retry classification missing');
$check(str_contains($files['js'], 'isTransientDatabaseDisconnect'), 'transient database disconnect classifier missing');
$check(str_contains($files['js'], 'MySQL server has gone away'), 'MySQL error 2006 classifier missing');
$check(str_contains($files['js'], 'ConnectionLost'), 'Doctrine connection-lost classifier missing');
$check(str_contains($files['js'], 'SQLSTATE'), 'SQLSTATE connection-lost classifier missing');
$check(str_contains($files['js'], 'const maxTransientBatchRetries = 3'), 'AJAX transient retry hard bound missing');
$check(str_contains($files['js'], 'transientBatchFailures < maxTransientBatchRetries'), 'AJAX transient retry counter fence missing');
$check(!str_contains($files['js'], 'response.status >= 500 && response.status <= 504'), 'generic HTTP 500 business/runtime failures must not be automatically retried');
$check(str_contains($files['js'], 'status !== 500'), 'special HTTP 500 retry must remain limited to classified transient DB disconnects');
$check(str_contains($files['js'], 'batchInFlight'), 'AJAX cancel/batch race fence missing');
$check(str_contains($files['js'], 'cancelRequested'), 'AJAX deferred cancellation state missing');
$check(str_contains($files['js'], 'void refreshStatus()'), 'page reload active-run status restore missing');
$check(str_contains($files['js'], 'window.setTimeout(runNextBatch, 100)'), 'serial AJAX batch loop missing');

require_once $root . '/src/Admin/ImportStatusProvider.php';
$presenter = new \Lp\MatterhornImport\Admin\ImportStatusProvider();
$sample = $presenter->present([
    'id_run' => 9,
    'id_shop' => 1,
    'source' => 'matterhorn',
    'status' => 'paused',
    'read_status' => 'completed',
    'import_status' => 'paused',
    'update_status' => 'pending',
    'remove_status' => 'pending',
    'source_total' => 20000,
    'source_valid' => 20000,
    'source_invalid' => 0,
    'source_duplicate' => 0,
    'import_done' => 500,
]);
$check(($sample['progress']['phase'] ?? null) === 'import', 'progress phase must advance after completed READ');
$check(($sample['progress']['phase_index'] ?? null) === 2, 'IMPORT must be phase 2/4');
$check(($sample['progress']['overall_percent'] ?? null) === 25, 'progress must expose honest completed-phase boundary');
$check(($sample['progress']['indeterminate'] ?? null) === true, 'active phase without denominator must be indeterminate');

$completed = $presenter->present([
    'id_run' => 10,
    'id_shop' => 1,
    'source' => 'matterhorn',
    'status' => 'completed',
    'read_status' => 'completed',
    'import_status' => 'completed',
    'update_status' => 'completed',
    'remove_status' => 'completed',
]);
$check(($completed['progress']['overall_percent'] ?? null) === 100, 'completed AJAX run must report 100%');
$check(($completed['active'] ?? null) === false, 'completed AJAX run must be terminal');

echo "Admin AJAX import contract: OK\n";
