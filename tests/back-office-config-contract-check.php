<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$module = (string) file_get_contents($root . '/matterhornimport.php');
$routes = (string) file_get_contents($root . '/config/routes.yml');
$services = (string) file_get_contents($root . '/config/services.yml');
$controller = (string) file_get_contents($root . '/src/Controller/ConfigurationController.php');
$formType = (string) file_get_contents($root . '/src/Form/ConfigurationFormType.php');
$formProvider = (string) file_get_contents($root . '/src/Form/ConfigurationFormDataProvider.php');
$status = (string) file_get_contents($root . '/src/Admin/StatusProvider.php');
$location = (string) file_get_contents($root . '/src/Source/SourceLocation.php');
$configuredSource = (string) file_get_contents($root . '/src/Source/ConfiguredMatterhornXmlSource.php');
$materializer = (string) file_get_contents($root . '/src/Source/RemoteFeedMaterializer.php');
$template = (string) file_get_contents($root . '/views/templates/admin/configuration.html.twig');
$settings = (string) file_get_contents($root . '/src/Config/OperationalSettings.php');
$policy = (string) file_get_contents($root . '/src/Config/MatterhornPolicy.php');
$images = (string) file_get_contents($root . '/src/Command/ImagesCommand.php');
$reconcile = (string) file_get_contents($root . '/src/Command/ImagesReconcileCommand.php');
$newProducts = (string) file_get_contents($root . '/src/Command/NewProductsCommand.php');
$retry = (string) file_get_contents($root . '/src/Command/RetryCommand.php');
$mapper = (string) file_get_contents($root . '/src/Mapper/MatterhornProductMapper.php');
$writer = (string) file_get_contents($root . '/src/Product/MatterhornProductWriter.php');

$checks = [
    [$settings, 'MATTERHORNIMPORT_IMAGE_WORKER_LIMIT', 'shop-scoped image setting key'],
    [$settings, 'MATTERHORNIMPORT_NEW_PRODUCT_WORKER_LIMIT', 'shop-scoped new-product setting key'],
    [$settings, 'shop/group mismatch', 'shop/group ownership validation'],
    [$module, "generate('matterhorn_import_configuration')", 'Module Manager configure redirect'],
    [$module, 'vendor/autoload.php', 'Composer-only module autoload'],
    [$routes, 'matterhorn_import_configuration:', 'modern configuration route'],
    [$routes, 'methods: [GET, POST]', 'configuration GET/POST route'],
    [$routes, '_legacy_controller: AdminModules', 'legacy permission bridge'],
    [$controller, 'extends PrestaShopAdminController', 'PrestaShop 9 admin controller'],
    [$controller, '#[AdminSecurity(', 'admin security attribute'],
    [$controller, 'FormHandlerInterface', 'native PrestaShop form handler'],
    [$controller, 'addFlashErrors($errors)', 'form handler error reporting'],
    [$controller, 'StatusProvider $statusProvider', 'status separated from controller rendering'],
    [$formType, 'extends TranslatorAwareType', 'PrestaShop translator-aware form type'],
    [$formType, 'SwitchType::class', 'PrestaShop switch fields'],
    [$formType, 'prestashop_ui_kit.html.twig', 'PrestaShop UI Kit form theme'],
    [$formProvider, 'implements FormDataProviderInterface', 'native form data provider contract'],
    [$formProvider, '$this->sourceLocation->validate', 'source location validation before persistence'],
    [$formProvider, "Configuration::updateValue(\$key, \$value, false, \$shopGroupId, \$shopId)", 'shop-scoped configuration persistence'],
    [$formProvider, '$this->operationalSettings->save($shopId, $shopGroupId', 'shop-scoped operational persistence'],
    [$location, "\$scheme === 'http' || \$scheme === 'https'", 'HTTP(S) source acceptance'],
    [$location, '!is_file($location) || !is_readable($location)', 'local source readability validation'],
    [$configuredSource, 'ConfiguredMatterhornXmlSource implements CheckpointableSourceInterface', 'remote-aware checkpointable source'],
    [$configuredSource, "hash_file('sha256', \$path)", 'stable remote content fingerprint'],
    [$materializer, 'curl_setopt_array', 'explicit remote transport configuration'],
    [$materializer, 'CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS', 'remote protocol restriction'],
    [$materializer, "rename(\$temp, \$target)", 'atomic source publication'],
    [$services, 'alias: Lp\\MatterhornImport\\Source\\ConfiguredMatterhornXmlSource', 'remote-aware source DI alias'],
    [$services, 'matterhornimport.form.configuration_handler:', 'native form handler service'],
    [$template, 'form_start(configurationForm)', 'Symfony form rendering'],
    [$template, 'form_row(configurationForm.source_location)', 'source location rendered by Symfony form'],
    [$template, 'Current shop status', 'BO status panel'],
    [$template, 'matterhornimport:doctor --shop=', 'BO CLI diagnostics documentation'],
    [$status, "\$source = 'matterhorn';", 'BO operational source scope'],
    [$status, "AND source='\" . \$sourceSql . \"'", 'BO run state source scope'],
    [$status, 'GROUP BY status', 'BO queue status aggregation'],
    [$status, 'true,', 'BO mutable queue query cache argument'],
    [$policy, 'MATTERHORNIMPORT_CATEGORY_AUTO_CREATE', 'category policy source'],
    [$policy, 'MATTERHORNIMPORT_FEATURE_AUTO_CREATE', 'feature policy source'],
    [$policy, 'MATTERHORNIMPORT_SOURCE_LANGUAGE_ID', 'source-language policy source'],
    [$mapper, '$policy = ($this->policy ?? new MatterhornPolicy())->current()', 'mapper reads stable Matterhorn policy snapshot'],
    [$mapper, "extra['source_language_id']", 'source language captured in snapshot payload'],
    [$writer, "data->extra['source_language_id']", 'writer consumes snapshot language policy'],
    [$images, '$this->settings->imageWorkerLimit($shopId)', 'image worker BO default'],
    [$reconcile, "addOption('max-items'", 'reconciliation bounded max-items option'],
    [$reconcile, "addOption('time-limit'", 'reconciliation bounded time-limit option'],
    [$newProducts, '$this->settings->newProductWorkerLimit($shopId)', 'new-product worker BO default'],
    [$retry, '$this->settings->retryLimit($shopId)', 'retry BO default'],
];

foreach ($checks as [$haystack, $needle, $label]) {
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

if (str_contains($module, '<form') || str_contains($module, 'displayConfirmation(')) {
    fwrite(STDERR, "FAIL: main module must not hand-render configuration HTML\n");
    exit(1);
}
if (is_file($root . '/autoload.php') || is_file($root . '/config/services_bootstrap.php')) {
    fwrite(STDERR, "FAIL: custom module autoload/bootstrap files must stay removed\n");
    exit(1);
}

if (!preg_match(
    '/executeS\(\s*[^;]*?GROUP BY status[^;]*?,\s*true\s*,\s*false\s*\)/s',
    $status
)) {
    fwrite(STDERR, "FAIL: BO mutable queue state must bypass Db query cache\n");
    exit(1);
}

echo "Back Office config contract: OK\n";
