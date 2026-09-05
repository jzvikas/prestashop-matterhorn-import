<?php
namespace Lp\MatterhornImport;

final class Installer
{
    private const RETAIN_DATA_KEY = 'MATTERHORNIMPORT_RETAIN_DATA_ON_UNINSTALL';
    private const MAPPING_TABLE = 'li_matterhornim_99dfbf_mapping';
    private const CONFIG_KEYS = [
        'MATTERHORNIMPORT_SOURCE_FILE', 'MATTERHORNIMPORT_SOURCE_LANGUAGE_ID', 'MATTERHORNIMPORT_CATEGORY_AUTO_CREATE',
        'MATTERHORNIMPORT_FEATURE_AUTO_CREATE', 'MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME', 'MATTERHORNIMPORT_MAX_REMOVE_PERCENT',
        self::RETAIN_DATA_KEY,
    ];
    private const INSTALL_SQL = ['install.sql', 'attribute-mapping.sql'];
    private const UNINSTALL_SQL = ['uninstall-attribute-mapping.sql', 'uninstall.sql'];

    public function install(): bool
    {
        $defaults = [];
        try {
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
        } catch (\Throwable) {
            try { $this->uninstallSchemaOnly(); } catch (\Throwable) {}
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
            $quotedTable = str_replace('`', '``', $table);
            $column = $db->getRow("SHOW COLUMNS FROM `{$quotedTable}` LIKE 'out_of_feed'");
            if (!is_array($column)) {
                if (!$db->execute("ALTER TABLE `{$quotedTable}` ADD COLUMN `out_of_feed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `image_hash`")) {
                    throw new \RuntimeException('Could not add Matterhorn out_of_feed mapping state: ' . $db->getMsgError());
                }
            }
            $index = $db->getRow("SHOW INDEX FROM `{$quotedTable}` WHERE Key_name='idx_feed_state'");
            if (!is_array($index)) {
                if (!$db->execute("ALTER TABLE `{$quotedTable}` ADD KEY `idx_feed_state` (`id_shop`,`source`,`out_of_feed`,`last_seen_run_id`)")) {
                    throw new \RuntimeException('Could not add Matterhorn feed-state index: ' . $db->getMsgError());
                }
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[matterhornimport] mapping-state schema upgrade failed: ' . $e->getMessage());
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

    private function uninstallSchemaOnly(): bool
    {
        foreach (self::UNINSTALL_SQL as $file) {
            foreach ($this->statements($file) as $sql) {
                if (!\Db::getInstance()->execute($sql)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function statements(string $file): array
    {
        $path = dirname(__DIR__) . '/sql/' . $file;
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Matterhorn SQL file is missing or unreadable: ' . $path);
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Cannot read Matterhorn SQL file: ' . $path);
        }
        $statements = preg_split('/;\s*(?:\r?\n|$)/', str_replace('PREFIX_', _DB_PREFIX_, $contents));
        if (!is_array($statements)) {
            throw new \RuntimeException('Cannot parse Matterhorn SQL file: ' . $path);
        }
        return array_values(array_filter(array_map('trim', $statements), static fn(string $statement): bool => $statement !== ''));
    }
}
