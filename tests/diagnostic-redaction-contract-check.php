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
    'password=hunter2 authorization=Bearer-abcdef api_key=key123 SQLSTATE[42000] table=ps_product'
);
$output = $sanitizer->sanitize($input, 4000);

foreach (['feed-user', 'feed-pass', 'topsecret', 'hunter2', 'Bearer-abcdef', 'key123'] as $secret) {
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
$newProducts = (string) file_get_contents($root . '/src/Repository/NewProductQueueRepository.php');
$images = (string) file_get_contents($root . '/src/Repository/ImageQueueRepository.php');

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

if (!str_contains($newProducts, "$message = $this->sanitizer->sanitize('superseded: ' . trim($reason), 4000);")) {
    $fail('new-product supersede reason must be sanitized before persistence');
}
if (!str_contains($images, "$message = $this->sanitizer->sanitize('superseded: ' . trim($reason), 4000);")) {
    $fail('image supersede reason must be sanitized before persistence');
}

echo "Diagnostic redaction contract: OK\n";
