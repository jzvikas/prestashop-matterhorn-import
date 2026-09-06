<?php
$root = dirname(__DIR__);
$files = [
    'reader' => file_get_contents($root . '/src/Category/CategoryPathReader.php'),
    'repository' => file_get_contents($root . '/src/Repository/CategoryMappingRepository.php'),
    'auto_mapper' => file_get_contents($root . '/src/Category/CategoryAutoMapper.php'),
    'sync' => file_get_contents($root . '/src/Category/CategoryCatalogSynchronizer.php'),
    'manager' => file_get_contents($root . '/src/Category/CategoryMappingManager.php'),
    'controller' => file_get_contents($root . '/src/Controller/CategoryController.php'),
    'form' => file_get_contents($root . '/src/Form/CategoryMappingFormType.php'),
    'routes' => file_get_contents($root . '/config/routes.yml'),
    'services' => file_get_contents($root . '/config/services.yml'),
    'index' => file_get_contents($root . '/views/templates/admin/category/index.html.twig'),
    'edit' => file_get_contents($root . '/views/templates/admin/category/edit.html.twig'),
];
foreach ($files as $name => $content) {
    if ($content === false) { throw new RuntimeException('Category admin mapping file missing: ' . $name); }
}

$checks = [
    ['reader', 'ORDER BY leaf.id_category,parent.nleft,parent.id_category'],
    ['reader', "implode(' > ', \$parts)"],
    ['repository', 'findAll('],
    ['repository', 'findUnmapped('],
    ['repository', 'updateMapping('],
    ['repository', 'assertCategoryInShop('],
    ['repository', 'CategoryPathReader'],
    ['repository', 'ON DUPLICATE KEY UPDATE `supplier_parent_key`=VALUES(`supplier_parent_key`)'],
    ['auto_mapper', 'CategoryPathReader'],
    ['auto_mapper', 'Ambiguous exact category path'],
    ['sync', 'SourceInterface'],
    ['sync', 'synchronize(int $shopId)'],
    ['sync', 'Conflicting Matterhorn category metadata'],
    ['manager', 'autoMapExisting('],
    ['manager', 'createAndMapMissing('],
    ['controller', 'CategoryMappingFormType::class'],
    ['controller', 'matterhorn_category_sync'],
    ['controller', 'matterhorn_category_auto_map'],
    ['controller', 'matterhorn_category_auto_create'],
    ['form', 'CategoryPathReader'],
    ['form', 'PrestaShop category'],
    ['routes', 'matterhorn_import_categories:'],
    ['routes', 'matterhorn_import_category_edit:'],
    ['services', 'Lp\\MatterhornImport\\Form\\CategoryMappingFormType:'],
    ['index', 'Synchronize categories from XML'],
    ['index', 'Auto-map existing full paths'],
    ['index', 'Create and map missing categories'],
    ['edit', "form_theme categoryForm '@PrestaShop/Admin/TwigTemplateForm/prestashop_ui_kit.html.twig'"],
    ['edit', 'form_widget(categoryForm)'],
];
foreach ($checks as [$file, $needle]) {
    if (!str_contains($files[$file], $needle)) {
        throw new RuntimeException('Category admin mapping contract missing ' . $needle . ' in ' . $file);
    }
}

foreach (['reader', 'repository', 'auto_mapper', 'form'] as $file) {
    if (stripos($files[$file], 'GROUP_CONCAT') !== false) {
        throw new RuntimeException('Category path logic must not depend on GROUP_CONCAT: ' . $file);
    }
}
if (str_contains($files['form'], "'form_theme' =>")) {
    throw new RuntimeException('Category form theme must be applied by Twig instead of a Symfony form option');
}
if (str_contains($files['repository'], '`active`=VALUES(`active`)')) {
    throw new RuntimeException('Supplier metadata refresh must not re-enable a manually disabled category mapping');
}
if (!str_contains($files['repository'], '), true, false)')) {
    throw new RuntimeException('Category admin mapping live reads must bypass PrestaShop Db query cache');
}

echo "Category admin mapping contract: OK\n";
