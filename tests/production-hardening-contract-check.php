<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$services = (string) file_get_contents($root . '/config/services.yml');
$installer = (string) file_get_contents($root . '/src/Installer.php');
$import = (string) file_get_contents($root . '/src/Import/ImportStage.php');
$update = (string) file_get_contents($root . '/src/Import/UpdateStage.php');
$remove = (string) file_get_contents($root . '/src/Import/RemoveStage.php');
$importLock = (string) file_get_contents($root . '/src/Lock/ImportLock.php');
$imageWorker = (string) file_get_contents($root . '/src/Image/ImageWorker.php');
$manufacturer = (string) file_get_contents($root . '/src/Manufacturer/ManufacturerResolver.php');
$attributeResolver = (string) file_get_contents($root . '/src/Attribute/AttributeResolver.php');
$attributeMapping = (string) file_get_contents($root . '/src/Repository/AttributeMappingRepository.php');
$newWorker = (string) file_get_contents($root . '/src/NewProduct/NewProductWorker.php');
$production = (string) file_get_contents($root . '/docs/PRODUCTION.md');
$mapper = (string) file_get_contents($root . '/src/Mapper/MatterhornProductMapper.php');
$dbSafety = (string) file_get_contents($root . '/src/Util/DatabaseSafety.php');
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$self = realpath(__FILE__);

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$commands = [
    'RunCommand','ReadCommand','ImportCommand','UpdateCommand','RemoveCommand','ImagesCommand','ImagesReconcileCommand','ImagesRevalidateCommand',
    'NewProductsEnqueueCommand','NewProductsCommand','RetryCommand','DoctorCommand','StatusCommand','GcCommand',
];
foreach ($commands as $command) {
    if (!str_contains($services, 'Lp\\MatterhornImport\\Command\\' . $command . ':')) { $fail('missing service registration for ' . $command); }
}

foreach ([
    'MATTERHORNIMPORT_BATCH_SIZE','MATTERHORNIMPORT_MAX_ITEMS','MATTERHORNIMPORT_TIME_LIMIT',
    'MATTERHORNIMPORT_IMAGE_WORKER_LIMIT','MATTERHORNIMPORT_IMAGE_WORKER_RUNTIME',
    'MATTERHORNIMPORT_NEW_PRODUCT_WORKER_LIMIT','MATTERHORNIMPORT_NEW_PRODUCT_WORKER_RUNTIME','MATTERHORNIMPORT_RETRY_LIMIT',
] as $key) {
    if (!str_contains($installer, "'{$key}'")) { $fail('uninstall configuration cleanup missing ' . $key); }
}

foreach ([[$import, '$this->specificPrices->sync', 'IMPORT specific-price parity'], [$update, '$this->specificPrices->sync', 'UPDATE specific-price parity'], [$newWorker, '$this->specificPrices->sync', 'new-product specific-price parity']] as [$source, $needle, $label]) {
    if (!str_contains($source, $needle)) { $fail($label); }
}
if (!str_contains($update, "'specific_price'")) { $fail('UPDATE does not route specific_price hash domain'); }

foreach ([
    [$import, "getValue('SELECT @@session.in_transaction', false)", 'IMPORT transaction state must bypass Db query cache'],
    [$update, "getValue('SELECT @@session.in_transaction', false)", 'UPDATE transaction state must bypass Db query cache'],
    [$remove, "getValue('SELECT @@session.in_transaction', false)", 'REMOVE transaction state must bypass Db query cache'],
    [$importLock, '), false);', 'advisory import lock reads must bypass Db query cache'],
    [$importLock, "RELEASE_LOCK('" , 'advisory import lock release missing'],
    [$imageWorker, "getValue('SELECT @@session.in_transaction', false)", 'image transaction state must bypass Db query cache'],
    [$imageWorker, 'GET_LOCK', 'image content lock acquisition missing'],
    [$imageWorker, 'false', 'image lock/session reads must bypass Db query cache'],
] as [$source, $needle, $label]) {
    if (!str_contains($source, $needle)) { $fail($label); }
}

if (!str_contains($manufacturer, 'GET_LOCK') || !str_contains($manufacturer, "', 10)\", false)")) {
    $fail('manufacturer resolver lock acquisition must bypass Db query cache');
}
if (!str_contains($manufacturer, 'SELECT id_manufacturer') || substr_count($manufacturer, "\n                false\n") < 2) {
    $fail('manufacturer resolver live lookup/association reads must bypass Db query cache');
}
if (!str_contains($manufacturer, 'RELEASE_LOCK') || !str_contains($manufacturer, "')\", false);")) {
    $fail('manufacturer resolver lock release must bypass Db query cache');
}
if (substr_count($attributeResolver, '), true, false)') < 2) {
    $fail('attribute resolver exact group/value reads must bypass Db query cache');
}
if (substr_count($attributeResolver, "\n            false\n") < 3) {
    $fail('attribute resolver position/lock reads must bypass Db query cache');
}
if (!str_contains($attributeResolver, 'RELEASE_LOCK') || !str_contains($attributeResolver, "')\", false);")) {
    $fail('attribute resolver lock release must bypass Db query cache');
}
if (!str_contains($attributeMapping, '), false);')) {
    $fail('supplier attribute mapping resolution must bypass Db query cache');
}

if (!str_contains($production, 'READ -> IMPORT -> UPDATE -> REMOVE')) { $fail('production stage order missing'); }
if (!str_contains($production, 'matterhornimport:images:reconcile')) { $fail('production image reconciliation documentation missing'); }
if (!str_contains($production, 'matterhornimport:images:revalidate')) { $fail('production image revalidation documentation missing'); }
if (!str_contains($production, 'flock -n')) { $fail('production overlap guard example missing'); }

foreach ([
    "'manufacturer_lang'" => 'manufacturer language transaction safety',
    "'attribute_group_lang'" => 'Size group translation transaction safety',
    "'attribute_lang'" => 'Size value translation transaction safety',
    "'li_matterhornim_99dfbf_attribute_group_mapping'" => 'Size group mapping transaction safety',
    "'li_matterhornim_99dfbf_attribute_value_mapping'" => 'Size value mapping transaction safety',
] as $needle => $label) {
    if (!str_contains($dbSafety, $needle)) { $fail($label); }
}

$requires = is_array($composer['require'] ?? null) ? $composer['require'] : [];
if ((str_contains($mapper, 'mb_strlen(') || str_contains($mapper, 'mb_substr(') || str_contains($mapper, 'mb_strtolower(')) && !array_key_exists('ext-mbstring', $requires)) {
    $fail('composer must declare ext-mbstring used by MatterhornProductMapper');
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$forbidden = ['Lp\\ImportSkeleton\\', 'LPIMPORTSKELETON_', 'lp_import_'];
foreach ($iterator as $file) {
    if (!$file->isFile()) { continue; }
    $path = $file->getPathname();
    if ($self !== false && realpath($path) === $self) { continue; }
    if (str_contains($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR) || str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) { continue; }
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($extension, ['php','yml','yaml','sql','md','sh','json'], true)) { continue; }
    $contents = file_get_contents($path);
    if (!is_string($contents)) { continue; }
    foreach ($forbidden as $token) {
        if (str_contains($contents, $token)) { $fail('generic skeleton token leaked into ' . substr($path, strlen($root) + 1) . ': ' . $token); }
    }
}

echo "Production hardening contract: OK\n";