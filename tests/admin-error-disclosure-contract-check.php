<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Admin/AdminErrorReporter.php';

use Lp\MatterhornImport\Admin\AdminErrorReporter;

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$check = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};

$reporter = new AdminErrorReporter();
$secret = 'SELECT * FROM ps_customer WHERE passwd="super-secret"; path=/var/www/prestashop/app/config/parameters.php; https://user:password@example.invalid/feed.xml';
$public = $reporter->safeMessage(new RuntimeException($secret));

$check($public === '', 'AJAX-safe exception message must not expose runtime exception text');
$check(!str_contains($public, 'SELECT'), 'AJAX-safe message must not expose SQL');
$check(!str_contains($public, '/var/www/'), 'AJAX-safe message must not expose filesystem paths');
$check(!str_contains($public, 'super-secret'), 'AJAX-safe message must not expose secret values');
$check(!str_contains($public, 'user:password'), 'AJAX-safe message must not expose URL credentials');

$source = (string) file_get_contents(dirname(__DIR__) . '/src/Admin/AdminErrorReporter.php');
$controller = (string) file_get_contents(dirname(__DIR__) . '/src/Controller/ImportController.php');
$check(str_contains($controller, '$errors->safeMessage($exception)'), 'AJAX controller must route exception text through AdminErrorReporter');
$check(str_contains($controller, "'Operation failed. Reference: '"), 'AJAX controller must expose a correlation reference when details are withheld');
$check(str_contains($source, '$this->diagnosticMessage($exception)'), 'internal logger must retain a separately sanitized diagnostic message');
$check(str_contains($source, 'api[_-]?key|token|secret'), 'diagnostic redaction must cover common token/secret names');

echo "Admin error disclosure contract: OK\n";
