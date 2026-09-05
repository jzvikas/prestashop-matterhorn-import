<?php
namespace Lp\MatterhornImport;

final class Installer
{
    private const RETAIN_DATA_KEY = 'MATTERHORNIMPORT_RETAIN_DATA_ON_UNINSTALL';
    private const MAPPING_TABLE = 'li_matterhornim_99dfbf_mapping';
    private const RUN_TABLE = 'li_matterhornim_99dfbf_run';
    private const IMAGE_QUEUE_TABLE = 'li_matterhornim_99dfbf_image_queue';
    private const OWNED_TABLES = [
        'li_matterhornim_99dfbf_run',
        'li_matterhornim_99dfbf_snapshot',
        'li_matterhornim_99dfbf_mapping',
        'li_matterhornim_99dfbf_category_mapping',
        'li_matterhornim_99dfbf_feature_mapping',
        'li_matterhornim_99dfbf_feature_value_mapping',
        'li_matterhornim_99dfbf_feature_state',
        'li_matterhornim_99dfbf_combination_mapping',
        'li_matterhornim_99dfbf_specific_price_state',
        'li_matterhornim_99dfbf_new_product_queue',
        'li_matterhornim_99dfbf_error',
        'li_matterhornim_99dfbf_image_state',
        'li_matterhornim_99dfbf_image_queue',
        'li_matterhornim_99dfbf_image_orphan',
        'li_matterhornim_99dfbf_attribute_group_mapping',
        'li_matterhornim_99dfbf_attribute_value_mapping',
    ];
    private const CONFIG_KEYS = [
        'MATTERHORNIMPORT_SOURCE_FILE',
        'MATTERHORNIMPORT_SOURCE_LANGUAGE_ID',
        'MATTERHORNIMPORT_CATEGORY_AUTO_CREATE',
        'MATTERHORNIMPORT_FEATURE_AUTO_CREATE',
        'MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME',
        'MATTERHORNIMPORT_MAX_REMOVE_PERCENT',
        'MATTERHORNIMPORT_BATCH_SIZE',
        'MATTERHORNIMPORT_MAX_ITEMS',
        'MATTERHORNIMPORT_TIME_LIMIT',
        'MATTERHORNIMPORT_IMAGE_WORKER_LIMIT',
        'MATTERHORNIMPORT_IMAGE_WORKER_RUNTIME',
        'MATTERHORNIMPORT_NEW_PRODUCT_WORKER_LIMIT',
        'MATTERHORNIMPORT_NEW_PRODUCT_WORKER_RUNTIME',
        'MATTERHORNIMPORT_RETRY_LIMIT',
        self::RETAIN_DATA_KEY,
    ];
    private const INSTALL_SQL = ['install.sql', 'attribute-mapping.sql', 'image-orphan.sql'];
    private const UNINSTALL_SQL = ['uninstall-attribute-mapping.sql', 'uninstall.sql'];

    public function install(): bool
    {
        $defaults = [];
        $schemaPreExisted = true;
        try {
            // Preserve retained or partially-created module data on a failed reinstall/repair.
            // Destructive rollback is only safe when no Matterhorn-owned table existed before this call.
            $schemaPreExisted = $this->anyOwnedTableExists();
            foreach (self::INSTALL_SQL as $file) {
                foreach ($this->statements($file) as $sql) {
                    if (!\Db::getInstance()->execute($sql)) {
                        throw new \RuntimeException('Matterhorn install SQL failed from ' . $file . ': ' . \Db::getInstance()->getMsgError());
                    }
                }
            }
            if (!$this->upgradeMappingState()) {
                throw new \RuntimeException('Could not initialize Matterhorn mapping state schema');
            }
            if (!$this->ensureExclusiveProductOwnership()) {
                throw new \RuntimeException('Could not initialize exclusive Matterhorn product ownership schema');
            }
            if (!$this->ensureRunPolicySchema()) {
                throw new \RuntimeException('Could not initialize Matterhorn run policy schema');
            }
            if (!$this->ensureImageReconcileSchema()) {
                throw new \RuntimeException('Could not initialize resumable image reconciliation schema');
            }
            if (!$this->ensurePerformanceIndexes()) {
                throw new \RuntimeException('Could not initialize Matterhorn performance indexes');
            }
            $defaults = [
                self::RETAIN_DATA_KEY => '1',
                'MATTERHORNIMPORT_CATEGORY_AUTO_CREATE' => '1',
                'MATTERHORNIMPORT_FEATURE_AUTO_CREATE' => '1',
                'MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME' => 'Size',
                'MATTERHORNIMPORT_MAX_REMOVE_PERCENT' => '25',
            ];
            foreach ($defaults as $key => $value) {
                if (!\Configuration::updateValue($key, $value, false, 0, 0)) {
                    throw new \RuntimeException('Could not initialize Matterhorn configuration: ' . $key);
                }
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[matterhornimport] install/repair failed: ' . $e->getMessage());
            if (!$schemaPreExisted) {
                try { $this->uninstallSchemaOnly(); } catch (\Throwable) {}
            }
            foreach (array_keys($defaults) as $key) {
                try { \Configuration::deleteByName($key); } catch (\Throwable) {}
            }
            return false;
        }
    }

    public function upgradeMappingState(): bool
    {
        try {
            $db = \Db::getInstance();
            $table = _DB_PREFIX_ . self::MAPPING_TABLE;
            $columnExists = (bool) $db->getValue(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() " .
                "AND TABLE_NAME='" . pSQL($table) . "' AND COLUMN_NAME='out_of_feed' LIMIT 1",
                false
            );
            if (!$columnExists && !$db->execute(
                'ALTER TABLE `' . bqSQL($table) . '` ADD COLUMN `out_of_feed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `image_hash`'
            ) && (int) $db->getNumberError() !== 1060) {
                throw new \RuntimeException('Could not add Matterhorn out_of_feed mapping state: ' . $db->getMsgError());
            }

            $indexExists = (bool) $db->getValue(
                "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() " .
                "AND TABLE_NAME='" . pSQL($table) . "' AND INDEX_NAME='idx_feed_state' LIMIT 1",
                false
            );
            if (!$indexExists && !$db->execute(
                'ALTER TABLE `' . bqSQL($table) . '` ADD KEY `idx_feed_state` (`id_shop`,`source`,`out_of_feed`,`last_seen_run_id`)'
            ) && (int) $db->getNumberError() !== 1061) {
                throw new \RuntimeException('Could not add Matterhorn feed-state index: ' . $db->getMsgError());
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[matterhornimport] mapping-state schema upgrade failed: ' . $e->getMessage());
            return false;
        }
    }

    public function ensureExclusiveProductOwnership(): bool
    {
        try {
            $db = \Db::getInstance();
            $table = _DB_PREFIX_ . self::MAPPING_TABLE;
            $newUnique = 'uq_shop_product_owner';
            $ownerRows = $db->executeS(
                "SELECT COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS " .
                "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . pSQL($table) . "' " .
                "AND INDEX_NAME='" . pSQL($newUnique) . "' ORDER BY SEQ_IN_INDEX",
                true,
                false
            ) ?: [];
            if ($ownerRows !== []) {
                $columns = array_map(static fn(array $row): string => (string) ($row['COLUMN_NAME'] ?? ''), $ownerRows);
                $unique = true;
                foreach ($ownerRows as $row) { $unique = $unique && (int) ($row['NON_UNIQUE'] ?? 1) === 0; }
                if ($columns !== ['id_shop', 'id_product'] || !$unique) {
                    throw new \RuntimeException('Existing uq_shop_product_owner index has an unexpected definition');
                }
            } else {
                // One PrestaShop product is owned by exactly one supplier source inside a shop.
                // If legacy data violates that invariant, do not guess which source should win.
                $conflicts = $db->executeS(
                    'SELECT id_shop,id_product,COUNT(*) owners FROM `' . bqSQL($table) . '` ' .
                    'GROUP BY id_shop,id_product HAVING COUNT(*)>1 ORDER BY id_shop,id_product LIMIT 1',
                    true,
                    false
                ) ?: [];
                if ($conflicts !== []) {
                    throw new \RuntimeException('Legacy Matterhorn mapping contains cross-source product ownership conflicts');
                }
                if (!$db->execute(
                    'ALTER TABLE `' . bqSQL($table) . '` ADD UNIQUE KEY `' . bqSQL($newUnique) . '` (`id_shop`,`id_product`)'
                )) {
                    throw new \RuntimeException('Could not add exclusive product ownership index: ' . $db->getMsgError());
                }
            }

            $oldUnique = 'uq_shop_source_product';
            $oldUniqueExists = (bool) $db->getValue(
                "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() " .
                "AND TABLE_NAME='" . pSQL($table) . "' AND INDEX_NAME='" . pSQL($oldUnique) . "' LIMIT 1",
                false
            );
            if ($oldUniqueExists && !$db->execute(
                'ALTER TABLE `' . bqSQL($table) . '` DROP INDEX `' . bqSQL($oldUnique) . '`'
            )) {
                throw new \RuntimeException('Could not remove legacy source-scoped product ownership index: ' . $db->getMsgError());
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[matterhornimport] exclusive product ownership schema upgrade failed: ' . $e->getMessage());
            return false;
        }
    }

    public function ensureRunPolicySchema(): bool
    {
        try {
            $db = \Db::getInstance();
            $table = _DB_PREFIX_ . self::RUN_TABLE;
            $exists = (bool) $db->getValue(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() " .
                "AND TABLE_NAME='" . pSQL($table) . "' AND COLUMN_NAME='source_policy_hash' LIMIT 1",
                false
            );
            if (!$exists && !$db->execute(
                'ALTER TABLE `' . bqSQL($table) . '` ADD COLUMN `source_policy_hash` CHAR(64) NULL AFTER `source_fingerprint`'
            )) {
                throw new \RuntimeException('Could not add Matterhorn run source_policy_hash: ' . $db->getMsgError());
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[matterhornimport] run-policy schema upgrade failed: ' . $e->getMessage());
            return false;
        }
    }

    public function ensureImageReconcileSchema(): bool
    {
        $columns = [
            'image_reconcile_status' => "VARCHAR(16) NOT NULL DEFAULT 'pending' AFTER `remove_status`",
            'image_reconcile_checkpoint' => 'VARCHAR(191) NULL AFTER `read_checkpoint`',
            'image_reconcile_done' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `remove_failed`',
        ];
        try {
            $db = \Db::getInstance();
            $runTable = _DB_PREFIX_ . self::RUN_TABLE;
            foreach ($columns as $column => $definition) {
                $exists = (bool) $db->getValue(
                    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() " .
                    "AND TABLE_NAME='" . pSQL($runTable) . "' AND COLUMN_NAME='" . pSQL($column) . "' LIMIT 1",
                    false
                );
                if (!$exists && !$db->execute(
                    'ALTER TABLE `' . bqSQL($runTable) . '` ADD COLUMN `' . bqSQL($column) . '` ' . $definition
                )) {
                    throw new \RuntimeException('Could not add Matterhorn run column ' . $column . ': ' . $db->getMsgError());
                }
            }

            $queueTable = _DB_PREFIX_ . self::IMAGE_QUEUE_TABLE;
            $index = 'idx_shop_source_status';
            $indexExists = (bool) $db->getValue(
                "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() " .
                "AND TABLE_NAME='" . pSQL($queueTable) . "' AND INDEX_NAME='" . pSQL($index) . "' LIMIT 1",
                false
            );
            if (!$indexExists && !$db->execute(
                'ALTER TABLE `' . bqSQL($queueTable) . '` ADD KEY `' . $index . '` (`id_shop`,`source`,`status`,`id_queue`)'
            )) {
                throw new \RuntimeException('Could not add Matterhorn image source-status index: ' . $db->getMsgError());
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[matterhornimport] image reconciliation schema upgrade failed: ' . $e->getMessage());
            return false;
        }
    }

    public function ensurePerformanceIndexes(): bool
    {
        $indexes = [
            'li_matterhornim_99dfbf_run' => [
                'idx_shop_source_run' => '(`id_shop`,`source`,`id_run`)',
            ],
            'li_matterhornim_99dfbf_mapping' => [
                'idx_feed_product' => '(`id_shop`,`source`,`out_of_feed`,`id_product`)',
            ],
            'li_matterhornim_99dfbf_image_state' => [
                'idx_revalidate' => '(`id_shop`,`source`,`updated_at`,`source_key`)',
            ],
            'li_matterhornim_99dfbf_image_queue' => [
                'idx_shop_claim' => '(`id_shop`,`status`,`available_at`,`id_queue`)',
            ],
            'li_matterhornim_99dfbf_new_product_queue' => [
                'idx_shop_claim' => '(`id_shop`,`status`,`available_at`,`id_queue`)',
            ],
        ];

        try {
            $db = \Db::getInstance();
            foreach ($indexes as $suffix => $definitions) {
                $table = _DB_PREFIX_ . $suffix;
                foreach ($definitions as $index => $definition) {
                    $exists = (bool) $db->getValue(
                        "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() " .
                        "AND TABLE_NAME='" . pSQL($table) . "' AND INDEX_NAME='" . pSQL($index) . "' LIMIT 1",
                        false
                    );
                    if (!$exists && !$db->execute(
                        'ALTER TABLE `' . bqSQL($table) . '` ADD KEY `' . bqSQL($index) . '` ' . $definition
                    )) {
                        throw new \RuntimeException('Could not add performance index ' . $index . ' on ' . $table . ': ' . $db->getMsgError());
                    }
                }
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[matterhornimport] performance-index schema upgrade failed: ' . $e->getMessage());
            return false;
        }
    }

    public function uninstall(): bool
    {
        $retainData = (bool) \Configuration::get(self::RETAIN_DATA_KEY, null, 0, 0);
        if (!$retainData && !$this->uninstallSchemaOnly()) {
            return false;
        }
        $ok = true;
        foreach (self::CONFIG_KEYS as $key) {
            $ok = \Configuration::deleteByName($key) && $ok;
        }
        return $ok;
    }

    private function anyOwnedTableExists(): bool
    {
        foreach (self::OWNED_TABLES as $suffix) {
            if ($this->tableExists($suffix)) { return true; }
        }
        return false;
    }

    private function tableExists(string $suffix): bool
    {
        $table = _DB_PREFIX_ . $suffix;
        return (bool) \Db::getInstance()->getValue(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . pSQL($table) . "' LIMIT 1",
            false
        );
    }

    private function uninstallSchemaOnly(): bool
    {
        foreach (self::UNINSTALL_SQL as $file) {
            foreach ($this->statements($file) as $sql) {
                if (!\Db::getInstance()->execute($sql)) { return false; }
            }
        }
        return true;
    }

    private function statements(string $file): array
    {
        $path = dirname(__DIR__) . '/sql/' . $file;
        if (!is_file($path) || !is_readable($path)) { throw new \RuntimeException('Matterhorn SQL file is missing or unreadable: ' . $path); }
        $contents = file_get_contents($path);
        if ($contents === false) { throw new \RuntimeException('Cannot read Matterhorn SQL file: ' . $path); }
        $statements = preg_split('/;\s*(?:\r?\n|$)/', str_replace('PREFIX_', _DB_PREFIX_, $contents));
        if (!is_array($statements)) { throw new \RuntimeException('Cannot parse Matterhorn SQL file: ' . $path); }
        return array_values(array_filter(array_map('trim', $statements), static fn(string $statement): bool => $statement !== ''));
    }
}
