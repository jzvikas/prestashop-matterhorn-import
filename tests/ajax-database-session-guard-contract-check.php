<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$guard = (string) file_get_contents($root . '/src/Database/AjaxDatabaseSessionGuard.php');
$subscriber = (string) file_get_contents($root . '/src/EventSubscriber/AjaxDatabaseSessionSubscriber.php');
$controller = (string) file_get_contents($root . '/src/Controller/ImportController.php');
$services = (string) file_get_contents($root . '/config/services.yml');
$template = (string) file_get_contents($root . '/views/templates/admin/import/index.html.twig');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$check = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) { $fail($message); }
};

$check(str_contains($guard, 'WAIT_TIMEOUT_SECONDS = 300'), 'AJAX DB wait_timeout guard must be 300 seconds');
$check(str_contains($guard, 'NET_READ_TIMEOUT_SECONDS = 120'), 'AJAX DB net_read_timeout guard missing');
$check(str_contains($guard, 'NET_WRITE_TIMEOUT_SECONDS = 120'), 'AJAX DB net_write_timeout guard missing');
$check(str_contains($guard, 'SET SESSION wait_timeout = '), 'per-session wait_timeout SQL missing');
$check(str_contains($guard, 'SET SESSION net_read_timeout = '), 'per-session net_read_timeout SQL missing');
$check(str_contains($guard, 'SET SESSION net_write_timeout = '), 'per-session net_write_timeout SQL missing');
$check(str_contains($guard, '$this->doctrineConnection->close()'), 'Doctrine stale connection close/reconnect fence missing');
$check(str_contains($guard, '$this->doctrineConnection->connect()'), 'Doctrine reconnect missing');
$check(str_contains($guard, '$db->disconnect()'), 'legacy PrestaShop DB reconnect fence missing');
$check(str_contains($guard, '$db->connect()'), 'legacy PrestaShop DB reconnect missing');
$check(str_contains($guard, 'MySQL server has gone away'), 'MySQL 2006 classifier missing from DB guard');
$check(str_contains($guard, '2006|2013'), 'SQLSTATE 2006/2013 classifier missing from DB guard');

$check(str_contains($subscriber, "ROUTE_PREFIX = 'matterhorn_import_ajax'"), 'AJAX DB guard must be scoped to Matterhorn AJAX routes');
$check(str_contains($subscriber, "KernelEvents::REQUEST => ['onKernelRequest', 20]"), 'Doctrine DB guard must run after routing and before BO security');
$check(str_contains($subscriber, 'prepareDoctrine()'), 'Doctrine session preparation missing from request subscriber');
$check(str_contains($services, "@doctrine.dbal.default_connection"), 'Doctrine default connection wiring missing');

$check(str_contains($controller, 'AJAX_TIME_LIMIT_SECONDS = 10'), 'AJAX batch budget must stay below aggressive shared-hosting timeout');
$check(str_contains($controller, 'AjaxDatabaseSessionGuard $databaseSession'), 'AJAX batch DB session guard injection missing');
$check(str_contains($controller, '$databaseSession->prepareLegacy();'), 'legacy DB session must be prepared before bounded batch');
$check(str_contains($template, 'soft 10-second execution budget'), 'BO help must describe actual AJAX time budget');
$check(!str_contains($template, 'soft 20-second execution budget'), 'stale 20-second BO help remains');

echo "AJAX database session guard contract: OK\n";
