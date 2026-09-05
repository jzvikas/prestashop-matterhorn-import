<?php
declare(strict_types=1);

function schemaCheck(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$root = dirname(__DIR__);
$install = (string) file_get_contents($root . '/sql/install.sql');
$attributeInstall = (string) file_get_contents($root . '/sql/attribute-mapping.sql');
$imageOrphanInstall = (string) file_get_contents($root . '/sql/image-orphan.sql');
$uninstall = (string) file_get_contents($root . '/sql/uninstall.sql');
$installer = (string) file_get_contents($root . '/src/Installer.php');
$databaseSafety = (string) file_get_contents($root . '/src/Util/DatabaseSafety.php');
$upgrade012 = (string) file_get_contents($root . '/upgrade/upgrade-0.1.2.php');
$upgrade013 = (string) file_get_contents($root . '/upgrade/upgrade-0.1.3.php');
$upgrade014 = (string) file_get_contents($root . '/upgrade/upgrade-0.1.4.php');
$main = (string) file_get_contents($root . '/matterhornimport.php');

schemaCheck(substr_count($install, 'CREATE TABLE IF NOT EXISTS') === 13, 'core schema must define 13 generated module tables');
schemaCheck(substr_count($attributeInstall, 'CREATE TABLE IF NOT EXISTS') === 2, 'attribute schema must define two mapping tables');
schemaCheck(substr_count($imageOrphanInstall, 'CREATE TABLE IF NOT EXISTS') === 1, 'image orphan schema must define one recovery table');
schemaCheck(!str_contains($install, 'PREFIX_lp_import_'), 'generic skeleton table token must not leak into core schema');
schemaCheck(!str_contains($attributeInstall, 'PREFIX_lp_import_'), 'generic skeleton table token must not leak into attribute schema');
schemaCheck(!str_contains($imageOrphanInstall, 'PREFIX_lp_import_'), 'generic skeleton table token must not leak into image orphan schema');
schemaCheck(str_contains($install, 'PREFIX_li_matterhornim_99dfbf_run'), 'generated Matterhorn DB token must own run table');
schemaCheck(str_contains($imageOrphanInstall, 'PREFIX_li_matterhornim_99dfbf_image_orphan'), 'generated Matterhorn DB token must own image orphan table');
schemaCheck(str_contains($imageOrphanInstall, 'ENGINE=InnoDB'), 'image orphan recovery schema must require InnoDB');
schemaCheck(str_contains($uninstall, 'PREFIX_li_matterhornim_99dfbf_image_orphan'), 'uninstall must target image orphan recovery table');
schemaCheck(str_contains($installer, "'install.sql'"), 'installer must load canonical core schema');
schemaCheck(str_contains($installer, "'attribute-mapping.sql'"), 'installer must load canonical attribute mapping schema');
schemaCheck(str_contains($installer, "'image-orphan.sql'"), 'installer must load image orphan recovery schema');
schemaCheck(!str_contains($installer, 'performance-indexes.sql'), 'fresh/reinstall path must not execute non-idempotent raw index SQL');
schemaCheck(str_contains($installer, 'ensureRunPolicySchema()'), 'fresh install must ensure semantic READ policy schema');
schemaCheck(str_contains($installer, "COLUMN_NAME='source_policy_hash'"), 'run policy column detection must be idempotent');
schemaCheck(str_contains($installer, 'ADD COLUMN `source_policy_hash` CHAR(64) NULL'), 'run policy hash column definition missing');
schemaCheck(str_contains($installer, 'ensurePerformanceIndexes()'), 'fresh install must ensure high-volume indexes idempotently');
schemaCheck(str_contains($installer, 'INFORMATION_SCHEMA.STATISTICS'), 'performance index detection must be idempotent');
schemaCheck(str_contains($installer, 'idx_shop_source_run'), 'latest-run hot query index missing');
schemaCheck(str_contains($installer, 'idx_feed_product'), 'REMOVE keyset hot query index missing');
schemaCheck(substr_count($installer, "'idx_shop_claim'") === 2, 'both per-shop worker queues need claim indexes');
schemaCheck(str_contains($installer, 'MATTERHORNIMPORT_RETAIN_DATA_ON_UNINSTALL'), 'installer must expose retention policy');
schemaCheck(str_contains($databaseSafety, 'li_matterhornim_99dfbf_image_orphan'), 'database safety must include image orphan table');
schemaCheck(str_contains($upgrade012, 'upgrade_module_0_1_2'), '0.1.2 upgrade entrypoint must exist');
schemaCheck(str_contains($upgrade012, 'li_matterhornim_99dfbf_image_orphan'), '0.1.2 upgrade must create image orphan table');
schemaCheck(str_contains($upgrade013, 'upgrade_module_0_1_3'), '0.1.3 upgrade entrypoint must exist');
schemaCheck(str_contains($upgrade013, 'ensurePerformanceIndexes()'), '0.1.3 upgrade must reuse reinstall-safe index ensure logic');
schemaCheck(str_contains($upgrade014, 'upgrade_module_0_1_4'), '0.1.4 upgrade entrypoint must exist');
schemaCheck(str_contains($upgrade014, 'ensureRunPolicySchema()'), '0.1.4 upgrade must reuse reinstall-safe policy schema ensure logic');
schemaCheck(str_contains($main, "\$this->version = '0.1.4'"), 'module version must match 0.1.4 schema upgrade');
schemaCheck(str_contains($main, '(new Installer())->install()'), 'module install hook must invoke schema installer');
schemaCheck(str_contains($main, '(new Installer())->uninstall()'), 'module uninstall hook must invoke schema installer');

echo "Schema/installer contract checks: OK\n";
