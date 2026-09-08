<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Util/DiagnosticMessageSanitizer.php';
require_once dirname(__DIR__) . '/src/Admin/AdminErrorReporter.php';

use Lp\MatterhornImport\Admin\AdminErrorReporter;
use Lp\MatterhornImport\Util\DiagnosticMessageSanitizer;

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$check = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};

$reporter = new AdminErrorReporter(new DiagnosticMessageSanitizer());
$secret = 'SELECT * FROM ps_customer WHERE passwd="super-secret"; path=/var/www/prestashop/app/config/parameters.php; https://user:password@example.invalid/feed.xml';
$public = $reporter->safeMessage(new RuntimeException($secret));

$check($public === '', 'AJAX-safe exception message must not expose runtime exception text');
$check(!str_contains($public, 'SELECT'), 'AJAX-safe message must not expose SQL');
$check(!str_contains($public, '/var/www/'), 'AJAX-safe message must not expose filesystem paths');
$check(!str_contains($public, 'super-secret'), 'AJAX-safe message must not expose secret values');
$check(!str_contains($public, 'user:password'), 'AJAX-safe message must not expose URL credentials');

$source = (string) file_get_contents(dirname(__DIR__) . '/src/Admin/AdminErrorReporter.php');
$sanitizerSource = (string) file_get_contents(dirname(__DIR__) . '/src/Util/DiagnosticMessageSanitizer.php');
$controller = (string) file_get_contents(dirname(__DIR__) . '/src/Controller/ImportController.php');
$categoryController = (string) file_get_contents(dirname(__DIR__) . '/src/Controller/CategoryController.php');
$check(str_contains($controller, '$errors->safeMessage($exception)'), 'AJAX controller must route exception text through AdminErrorReporter');
$check(str_contains($controller, "'Operation failed. Reference: '"), 'AJAX controller must expose a correlation reference when details are withheld');
$check(str_contains($controller, "$errors->report('ajax-import-cancel-source-cleanup', $cleanupError)"), 'cancelled run-source cleanup failures must use the shared sanitized reporter');
$check(!str_contains($controller, '$cleanupError->getMessage()'), 'cancelled run-source cleanup must not log raw exception text');
$check(str_contains($source, '$this->sanitizer->sanitize($exception, 1200)'), 'internal logger must pass throwable diagnostics through the shared sanitizer');
$check(str_contains($sanitizerSource, 'api[_-]?key|access[_-]?token|refresh[_-]?token|token|secret'), 'diagnostic redaction must cover common token/secret names');
$check(str_contains($categoryController, 'use Lp\\MatterhornImport\\Admin\\AdminErrorReporter;'), 'category admin controller must use the shared sanitized error reporter');
$check(str_contains($categoryController, '$reference = $errors->report($operation, $e);'), 'category admin failures must be logged through AdminErrorReporter');
$check(!str_contains($categoryController, '\\PrestaShopLogger::addLog('), 'category admin controller must not directly log raw exception text');
$check(!str_contains($categoryController, '$e->getMessage()'), 'category admin controller must not bypass diagnostic redaction with raw exception text');

echo "Admin error disclosure contract: OK\n";
