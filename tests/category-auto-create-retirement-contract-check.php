<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$deprecatedKey = 'MATTERHORNIMPORT_CATEGORY_AUTO_CREATE';

$module = (string) file_get_contents($root . '/matterhornimport.php');
$installer = (string) file_get_contents($root . '/src/Installer.php');
$formType = (string) file_get_contents($root . '/src/Form/ConfigurationFormType.php');
$formProvider = (string) file_get_contents($root . '/src/Form/ConfigurationFormDataProvider.php');
$policy = (string) file_get_contents($root . '/src/Config/MatterhornPolicy.php');
$mapper = (string) file_get_contents($root . '/src/Mapper/MatterhornProductMapper.php');
$manager = (string) file_get_contents($root . '/src/Category/CategoryMappingManager.php');
$upgradePath = $root . '/upgrade/upgrade-0.1.8.php';
$upgrade = is_file($upgradePath) ? (string) file_get_contents($upgradePath) : '';

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!str_contains($module, "\$this->version = '0.1.8';")) {
    $fail('module version must be 0.1.8 for category configuration migration');
}

foreach ([
    'installer' => $installer,
    'configuration form' => $formType,
    'configuration provider' => $formProvider,
    'runtime policy' => $policy,
    'product mapper' => $mapper,
] as $label => $content) {
    if (str_contains($content, $deprecatedKey)
        || str_contains($content, 'category_auto_create')
        || str_contains($content, 'Auto-create missing categories')) {
        $fail("retired category auto-create setting remains in {$label}");
    }
}

if ($upgrade === '') {
    $fail('0.1.8 upgrade script is missing');
}
if (!str_contains($upgrade, 'function upgrade_module_0_1_8')) {
    $fail('0.1.8 upgrade entry point is missing');
}
if (!str_contains($upgrade, "Configuration::deleteByName('{$deprecatedKey}')")) {
    $fail('0.1.8 upgrade must delete every legacy category auto-create configuration row');
}
if (!str_contains($manager, 'createAndMapMissing')) {
    $fail('explicit Category mapping create-and-map action must remain available');
}
if (!str_contains($manager, "'auto_create' => true")) {
    $fail('explicit Category mapping action must retain internal category creation intent');
}

if (str_contains($mapper, "'auto_create' =>")) {
    $fail('normal product mapper must not request category creation');
}

echo "Category auto-create setting retirement contract: OK\n";
