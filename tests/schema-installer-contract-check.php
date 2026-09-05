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
$mappingRepository = (string) file_get_contents($root . '/src/Repository/MappingRepository.php');
$upgrade012 = (string) file_get_contents($root . '/upgrade/upgrade-0.1.2.php');
$upgrade013 = (string) file_get_contents($root . '/upgrade/upgrade-0.1.3.php');
$upgrade014 = (string) file_get_contents($root . '/upgrade/upgrade-0.1.4.php');
$upgrade015 = (string) file_get_contents($root . '/upgrade/upgrade-0.1.5.php');
$upgrade016 = (string) file_get_contents($root . '/upgrade/upgrade-0.1.6.php');
$upgrade017 = (string) file_get_contents($root . '/upgrade/upgrade-0.1.7.php');
$main = (string) file_get_contents($root . '/matterhornimport.php');

schemaCheck(substr_count($install, 'CREATE TABLE IF NOT EXISTS') === 13, 'core schema must define 13 generated module tables');
schemaCheck(substr_count($attributeInstall, 'CREATE TABLE IF NOT EXISTS') === 2, 'attribute schema must define two mapping tables');
schemaCheck(substr_count($imageOrphanInstall, 'CREATE TABLE IF NOT EXISTS') === 1, 'image orphan schema must define one recovery table');
schemaCheck(!str_contains($install, 'PREFIX_lp_import_'), 'generic skeleton table token must not leak into core schema');
schemaCheck(!str_contains($attributeInstall, 'PREFIX_lp_import_'), 'generic skeleton table token must not leak into attribute schema');
schemaCheck(!str_contains($imageOrphanInstall, 'PREFIX_lp_import_'), 'generic skeleton table token must not leak into image orphan schema');
schemaCheck(str_contains($install, 'PREFIX_li_matterhornim_99dfbf_run'), 'generated Matterhorn DB token must own run table');
schemaCheck(str_contains($install, '`source_policy_hash` CHAR(64) NULL'), 'fresh run schema must include policy hash');
schemaCheck(str_contains($install, "`image_reconcile_status` VARCHAR(16) NOT NULL DEFAULT 'pending'"), 'fresh run schema must include reconciliation status');
schemaCheck(str_contains($install, '`image_reconcile_checkpoint` VARCHAR(191) NULL'), 'fresh run schema must include reconciliation checkpoint');
schemaCheck(str_contains($install, '`image_reconcile_done` BIGINT UNSIGNED NOT NULL DEFAULT 0'), 'fresh run schema must include reconciliation counter');
schemaCheck(str_contains($install, 'KEY `idx_shop_source_run` (`id_shop`,`source`,`id_run`)'), 'fresh run schema must include latest-run index');
schemaCheck(str_contains($install, 'UNIQUE KEY `uq_shop_product_owner` (`id_shop`,`id_product`)'), 'fresh mapping schema must enforce exclusive product ownership per shop');
schemaCheck(!str_contains($install, 'UNIQUE KEY `uq_shop_source_product`'), 'fresh mapping schema must not retain source-scoped product ownership uniqueness');
schemaCheck(str_contains($install, 'KEY `idx_feed_product` (`id_shop`,`source`,`out_of_feed`,`id_product`)'), 'fresh mapping schema must include REMOVE keyset index');
schemaCheck(substr_count($install, 'KEY `idx_shop_claim` (`id_shop`,`status`,`available_at`,`id_queue`)') === 2, 'fresh queue schema must include both per-shop claim indexes');
schemaCheck(str_contains($install, 'KEY `idx_shop_source_status` (`id_shop`,`source`,`status`,`id_queue`)'), 'fresh image queue must support source-wide reconciliation fence');
schemaCheck(str_contains($install, 'KEY `idx_revalidate` (`id_shop`,`source`,`updated_at`,`source_key`)'), 'fresh image state must support bounded stale revalidation');
schemaCheck(str_contains($imageOrphanInstall, 'PREFIX_li_matterhornim_99dfbf_image_orphan'), 'generated Matterhorn DB token must own image orphan table');
schemaCheck(str_contains($imageOrphanInstall, 'ENGINE=InnoDB'), 'image orphan recovery schema must require InnoDB');
schemaCheck(str_contains($uninstall, 'PREFIX_li_matterhornim_99dfbf_image_orphan'), 'uninstall must target image orphan recovery table');
schemaCheck(str_contains($installer, "'install.sql'"), 'installer must load canonical core schema');
schemaCheck(str_contains($installer, "'attribute-mapping.sql'"), 'installer must load canonical attribute mapping schema');
schemaCheck(str_contains($installer, "'image-orphan.sql'"), 'installer must load image orphan recovery schema');
schemaCheck(!str_contains($installer, 'performance-indexes.sql'), 'fresh/reinstall path must not execute non-idempotent raw index SQL');
schemaCheck(str_contains($installer, '$schemaPreExisted = true'), 'install failure cleanup must default to preserving possible existing data');
schemaCheck(str_contains($installer, '$schemaPreExisted = $this->anyOwnedTableExists()'), 'installer must detect any retained or partial module schema before creating/upgrading');
schemaCheck(str_contains($installer, 'private const OWNED_TABLES = ['), 'installer must enumerate owned tables for partial-schema preservation');
schemaCheck(substr_count($installer, "'li_matterhornim_99dfbf_") >= 16, 'partial-schema preservation must cover all module tables');
schemaCheck(str_contains($installer, 'private function anyOwnedTableExists()'), 'installer must expose any-owned-table detection');
schemaCheck(str_contains($installer, 'if (!$schemaPreExisted)'), 'failed reinstall must not drop retained schema');
schemaCheck(str_contains($installer, 'ensureExclusiveProductOwnership()'), 'fresh/reinstall must ensure exclusive product ownership');
schemaCheck(str_contains($installer, "'uq_shop_product_owner'"), 'ownership migration must target uq_shop_product_owner');
schemaCheck(str_contains($installer, 'GROUP BY id_shop,id_product HAVING COUNT(*)>1'), 'ownership migration must detect legacy cross-source conflicts before adding unique index');
schemaCheck(str_contains($installer, 'Legacy Matterhorn mapping contains cross-source product ownership conflicts'), 'ownership conflict must fail closed with clear error');
schemaCheck(str_contains($installer, "'uq_shop_source_product'"), 'ownership migration must recognize legacy source-scoped index');
schemaCheck(str_contains($installer, 'DROP INDEX'), 'ownership migration must remove legacy source-scoped unique index after safe migration');
schemaCheck(str_contains($installer, 'ensureRunPolicySchema()'), 'fresh install must ensure semantic READ policy schema');
schemaCheck(str_contains($installer, 'ensureImageReconcileSchema()'), 'fresh/reinstall must ensure resumable image reconciliation schema');
schemaCheck(str_contains($installer, "'image_reconcile_status'"), 'reconciliation status column ensure missing');
schemaCheck(str_contains($installer, "'image_reconcile_checkpoint'"), 'reconciliation checkpoint column ensure missing');
schemaCheck(str_contains($installer, "'image_reconcile_done'"), 'reconciliation done column ensure missing');
schemaCheck(str_contains($installer, 'idx_shop_source_status'), 'reconciliation source queue index ensure missing');
schemaCheck(str_contains($installer, "'idx_revalidate' => '(`id_shop`,`source`,`updated_at`,`source_key`)'"), 'revalidation performance index ensure missing');
schemaCheck(str_contains($installer, 'INFORMATION_SCHEMA.STATISTICS'), 'index detection must be idempotent');
schemaCheck(str_contains($installer, 'ensurePerformanceIndexes()'), 'fresh install must ensure high-volume indexes idempotently');
schemaCheck(str_contains($installer, 'MATTERHORNIMPORT_RETAIN_DATA_ON_UNINSTALL'), 'installer must expose retention policy');
schemaCheck(str_contains($databaseSafety, "INDEX_NAME='uq_shop_product_owner'"), 'runtime database safety must require exclusive product ownership index');
schemaCheck(str_contains($databaseSafety, "['id_shop', 'id_product']"), 'runtime ownership safety must validate exact index column order');
schemaCheck(str_contains($databaseSafety, 'li_matterhornim_99dfbf_image_orphan'), 'database safety must include image orphan table');
schemaCheck(!str_contains($mappingRepository, 'ON DUPLICATE KEY UPDATE'), 'mapping save must not let ownership unique collisions mutate a foreign owner');
schemaCheck(str_contains($mappingRepository, 'findOwnerByProduct'), 'mapping save must expose conflicting product owner diagnostics');
schemaCheck(str_contains($mappingRepository, 'product ownership conflict'), 'mapping save must fail clearly on foreign product ownership');
schemaCheck(str_contains($upgrade012, 'upgrade_module_0_1_2'), '0.1.2 upgrade entrypoint must exist');
schemaCheck(str_contains($upgrade013, 'upgrade_module_0_1_3'), '0.1.3 upgrade entrypoint must exist');
schemaCheck(str_contains($upgrade014, 'upgrade_module_0_1_4'), '0.1.4 upgrade entrypoint must exist');
schemaCheck(str_contains($upgrade015, 'upgrade_module_0_1_5'), '0.1.5 upgrade entrypoint must exist');
schemaCheck(str_contains($upgrade015, 'ensureImageReconcileSchema()'), '0.1.5 upgrade must reuse idempotent reconciliation schema ensure');
schemaCheck(str_contains($upgrade016, 'upgrade_module_0_1_6'), '0.1.6 upgrade entrypoint must exist');
schemaCheck(str_contains($upgrade016, 'ensurePerformanceIndexes()'), '0.1.6 upgrade must reuse idempotent performance index ensure');
schemaCheck(str_contains($upgrade017, 'upgrade_module_0_1_7'), '0.1.7 upgrade entrypoint must exist');
schemaCheck(str_contains($upgrade017, 'ensureExclusiveProductOwnership()'), '0.1.7 upgrade must reuse idempotent ownership migration');
schemaCheck(str_contains($main, "\$this->version = '0.1.7'"), 'module version must match 0.1.7 ownership schema upgrade');
schemaCheck(str_contains($main, '(new Installer())->install()'), 'module install hook must invoke schema installer');
schemaCheck(str_contains($main, '(new Installer())->uninstall()'), 'module uninstall hook must invoke schema installer');

echo "Schema/installer contract checks: OK\n";
