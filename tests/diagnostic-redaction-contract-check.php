<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Util\DiagnosticMessageSanitizer;

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$sanitizer = new DiagnosticMessageSanitizer();
$input = new RuntimeException(
    'download failed url=https://feed-user:feed-pass@supplier.invalid/path?token=topsecret&x=1 ' .
    'password=hunter2 authorization=Bearer bearer-secret Authorization: Basic YmFzaWMtc2VjcmV0 api_key=key123 ' .
    'SQLSTATE[42000] table=ps_product'
);
$output = $sanitizer->sanitize($input, 4000);

foreach (['feed-user', 'feed-pass', 'topsecret', 'hunter2', 'bearer-secret', 'YmFzaWMtc2VjcmV0', 'key123'] as $secret) {
    if (str_contains($output, $secret)) {
        $fail('diagnostic sanitizer leaked secret: ' . $secret);
    }
}
foreach (['RuntimeException:', 'supplier.invalid', 'SQLSTATE[42000]', 'ps_product'] as $context) {
    if (!str_contains($output, $context)) {
        $fail('diagnostic sanitizer removed useful non-secret context: ' . $context);
    }
}

$root = dirname(__DIR__);
$admin = (string) file_get_contents($root . '/src/Admin/AdminErrorReporter.php');
$errors = (string) file_get_contents($root . '/src/Repository/ErrorRepository.php');
$runFailure = (string) file_get_contents($root . '/src/Util/RunFailureRecorder.php');
$importRunner = (string) file_get_contents($root . '/src/Import/ImportRunner.php');
$readStage = (string) file_get_contents($root . '/src/Import/ReadStage.php');
$newProducts = (string) file_get_contents($root . '/src/Repository/NewProductQueueRepository.php');
$images = (string) file_get_contents($root . '/src/Repository/ImageQueueRepository.php');
$imageOrphans = (string) file_get_contents($root . '/src/Repository/ImageOrphanRepository.php');
$imageWorker = (string) file_get_contents($root . '/src/Image/ImageWorker.php');

if (!str_contains($admin, 'DiagnosticMessageSanitizer') || !str_contains($admin, '$this->sanitizer->sanitize(')) {
    $fail('AdminErrorReporter must use the shared diagnostic sanitizer');
}
if (!str_contains($errors, 'DiagnosticMessageSanitizer') || !str_contains($errors, '$message = $this->sanitizer->sanitize($error, 8000);')) {
    $fail('ErrorRepository must sanitize diagnostics before persistence');
}
if (str_contains($errors, 'get_class($error) . \': \' . $error->getMessage()')) {
    $fail('ErrorRepository must not reconstruct raw throwable messages for persistence');
}
if (!str_contains($runFailure, 'DiagnosticMessageSanitizer') || !str_contains($runFailure, '$this->sanitizer->sanitize($error, 1000)')) {
    $fail('RunFailureRecorder fallback must redact throwable diagnostics');
}
if (!str_contains($importRunner, 'DiagnosticMessageSanitizer') || !str_contains($importRunner, '$this->sanitizer->sanitize($finishError, 1000)')) {
    $fail('ImportRunner failure-state fallback must redact throwable diagnostics');
}
if (str_contains($importRunner, '$finishError->getMessage()')) {
    $fail('ImportRunner fallback must not log raw failure-state exception text');
}
if (!str_contains($readStage, 'DiagnosticMessageSanitizer') || substr_count($readStage, '$this->sanitizer->sanitize($exception, 1000)') < 2) {
    $fail('READ checkpoint/source cleanup fallbacks must redact throwable diagnostics');
}
if (str_contains($readStage, '$exception->getMessage()')) {
    $fail('READ best-effort fallbacks must not log raw exception text');
}
foreach ([
    'new-product queue' => $newProducts,
    'image queue' => $images,
] as $label => $queue) {
    if (!str_contains($queue, 'DiagnosticMessageSanitizer')) {
        $fail($label . ' must receive the shared diagnostic sanitizer');
    }
    if (!str_contains($queue, '$this->sanitizer->sanitize(')) {
        $fail($label . ' must sanitize last_error diagnostics before persistence');
    }
    if (str_contains($queue, 'pSQL(mb_substr($message, 0, 4000), true)') || str_contains($queue, 'pSQL(mb_substr($error, 0, 4000), true)')) {
        $fail($label . ' must not persist raw worker failure text');
    }
}

$queueSupersedeSanitizer = '$message = $this->sanitizer->sanitize(\'superseded: \' . trim($reason), 4000);';
if (!str_contains($newProducts, $queueSupersedeSanitizer)) {
    $fail('new-product supersede reason must be sanitized before persistence');
}
if (!str_contains($images, $queueSupersedeSanitizer)) {
    $fail('image supersede reason must be sanitized before persistence');
}

if (!str_contains($imageOrphans, 'DiagnosticMessageSanitizer')) {
    $fail('image orphan repository must receive the shared diagnostic sanitizer');
}
if (substr_count($imageOrphans, '$this->sanitizer->sanitize(') < 2) {
    $fail('image orphan record/defer paths must sanitize persistent diagnostics');
}
if (str_contains($imageOrphans, 'pSQL(mb_substr($lastError, 0, 4000), true)') || str_contains($imageOrphans, 'pSQL(mb_substr($error, 0, 4000), true)')) {
    $fail('image orphan repository must not persist raw recovery error text');
}
if (!str_contains($imageWorker, 'DiagnosticMessageSanitizer') || !str_contains($imageWorker, '$this->sanitizer->sanitize($orphanError, 1000)')) {
    $fail('image orphan persistence fallback logging must redact throwable diagnostics');
}
if (str_contains($imageWorker, '$orphanError->getMessage()')) {
    $fail('image orphan persistence fallback must not log raw exception text');
}

echo "Diagnostic redaction contract: OK\n";
