<?php

declare(strict_types=1);

$host = getenv('LP_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('LP_DB_PORT') ?: 3306);
$user = getenv('LP_DB_USER') ?: 'root';
$pass = getenv('LP_DB_PASSWORD') ?: 'root';
$name = getenv('LP_DB_NAME') ?: 'matterhorn_test';
$prefix = getenv('LP_DB_PREFIX') ?: 'mh_';
$root = dirname(__DIR__);

$db = new mysqli($host, $user, $pass, $name, $port);
if ($db->connect_errno) { fwrite(STDERR, "DB connect failed: {$db->connect_error}\n"); exit(1); }
$db->set_charset('utf8mb4');

$parse = static function (string $file) use ($root, $prefix): array {
    $sql = file_get_contents($root . '/sql/' . $file);
    if ($sql === false) { throw new RuntimeException('Cannot read ' . $file); }
    $parts = preg_split('/;\s*(?:\r?\n|$)/', str_replace('PREFIX_', $prefix, $sql));
    if (!is_array($parts)) { throw new RuntimeException('Cannot parse ' . $file); }
    return array_values(array_filter(array_map('trim', $parts), static fn(string $statement): bool => $statement !== ''));
};
$execFile = static function (string $file) use ($parse, $db): void {
    foreach ($parse($file) as $statement) {
        if (!$db->query($statement)) { throw new RuntimeException($file . ': ' . $db->error . "\nSQL: " . $statement); }
    }
};

$installFiles = ['install.sql', 'attribute-mapping.sql', 'image-orphan.sql'];
$uninstallFiles = ['uninstall-attribute-mapping.sql', 'uninstall.sql'];
$expected = [
    'li_matterhornim_99dfbf_run','li_matterhornim_99dfbf_snapshot','li_matterhornim_99dfbf_mapping',
    'li_matterhornim_99dfbf_category_mapping','li_matterhornim_99dfbf_feature_mapping','li_matterhornim_99dfbf_feature_value_mapping',
    'li_matterhornim_99dfbf_feature_state','li_matterhornim_99dfbf_combination_mapping','li_matterhornim_99dfbf_specific_price_state',
    'li_matterhornim_99dfbf_new_product_queue','li_matterhornim_99dfbf_error','li_matterhornim_99dfbf_image_state','li_matterhornim_99dfbf_image_queue',
    'li_matterhornim_99dfbf_image_orphan','li_matterhornim_99dfbf_attribute_group_mapping','li_matterhornim_99dfbf_attribute_value_mapping',
];

try {
    foreach ($uninstallFiles as $file) { $execFile($file); }
    foreach ($installFiles as $file) { $execFile($file); }
    foreach ($installFiles as $file) { $execFile($file); }

    foreach ($expected as $suffix) {
        $table = $prefix . $suffix;
        $quoted = $db->real_escape_string($table);
        $row = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$quoted}'")?->fetch_assoc();
        if (!$row) { throw new RuntimeException('Missing installed table ' . $table); }
        if (strtoupper((string) $row['ENGINE']) !== 'INNODB') { throw new RuntimeException('Non-InnoDB table ' . $table); }
    }

    $runTable = $prefix . 'li_matterhornim_99dfbf_run';
    foreach (['source_policy_hash','image_reconcile_status','image_reconcile_checkpoint','image_reconcile_done'] as $columnName) {
        $safe = $db->real_escape_string($columnName);
        if (!$db->query("SHOW COLUMNS FROM `{$runTable}` LIKE '{$safe}'")?->fetch_assoc()) { throw new RuntimeException('Run column missing: ' . $columnName); }
    }
    if (!$db->query("SHOW INDEX FROM `{$runTable}` WHERE Key_name='idx_shop_source_run'")?->fetch_assoc()) { throw new RuntimeException('Run idx_shop_source_run missing'); }

    $mapping = $prefix . 'li_matterhornim_99dfbf_mapping';
    if (!$db->query("SHOW COLUMNS FROM `{$mapping}` LIKE 'out_of_feed'")?->fetch_assoc()) { throw new RuntimeException('Mapping out_of_feed column missing'); }
    if (!$db->query("SHOW INDEX FROM `{$mapping}` WHERE Key_name='idx_feed_state'")?->fetch_assoc()) { throw new RuntimeException('Mapping idx_feed_state missing'); }
    if (!$db->query("SHOW INDEX FROM `{$mapping}` WHERE Key_name='idx_feed_product'")?->fetch_assoc()) { throw new RuntimeException('Mapping idx_feed_product missing'); }

    $imageState = $prefix . 'li_matterhornim_99dfbf_image_state';
    if (!$db->query("SHOW INDEX FROM `{$imageState}` WHERE Key_name='idx_revalidate'")?->fetch_assoc()) { throw new RuntimeException('Image state idx_revalidate missing'); }

    $imageQueue = $prefix . 'li_matterhornim_99dfbf_image_queue';
    foreach (['id_shop','source','source_key','id_product','url_hash','status','locked_by','locked_until','available_at'] as $columnName) {
        $safe = $db->real_escape_string($columnName);
        if (!$db->query("SHOW COLUMNS FROM `{$imageQueue}` LIKE '{$safe}'")?->fetch_assoc()) { throw new RuntimeException('Image queue column missing: ' . $columnName); }
    }
    if (!$db->query("SHOW INDEX FROM `{$imageQueue}` WHERE Key_name='idx_shop_claim'")?->fetch_assoc()) { throw new RuntimeException('Image queue idx_shop_claim missing'); }
    if (!$db->query("SHOW INDEX FROM `{$imageQueue}` WHERE Key_name='idx_shop_source_status'")?->fetch_assoc()) { throw new RuntimeException('Image queue idx_shop_source_status missing'); }

    $newProductQueue = $prefix . 'li_matterhornim_99dfbf_new_product_queue';
    if (!$db->query("SHOW INDEX FROM `{$newProductQueue}` WHERE Key_name='idx_shop_claim'")?->fetch_assoc()) { throw new RuntimeException('New-product queue idx_shop_claim missing'); }

    $imageOrphan = $prefix . 'li_matterhornim_99dfbf_image_orphan';
    foreach (['id_queue','id_shop','source','source_key','id_product','id_image','reason','attempts','available_at'] as $columnName) {
        $safe = $db->real_escape_string($columnName);
        if (!$db->query("SHOW COLUMNS FROM `{$imageOrphan}` LIKE '{$safe}'")?->fetch_assoc()) { throw new RuntimeException('Image orphan column missing: ' . $columnName); }
    }

    foreach ($uninstallFiles as $file) { $execFile($file); }
    foreach ($expected as $suffix) {
        $table = $db->real_escape_string($prefix . $suffix);
        $row = $db->query("SELECT COUNT(*) qty FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}'")?->fetch_assoc();
        if ((int) ($row['qty'] ?? 0) !== 0) { throw new RuntimeException('Uninstall left table ' . $prefix . $suffix); }
    }

    echo "Matterhorn database lifecycle: OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $db->close();
}
