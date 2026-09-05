<?php
namespace Lp\MatterhornImport\Util;

final class DatabaseSafety
{
    private const TABLES = [
        'product','product_shop','product_lang','stock_available',
        'category','category_shop','category_lang','category_product',
        'manufacturer','manufacturer_shop','manufacturer_lang',
        'feature','feature_shop','feature_lang','feature_value','feature_value_lang','feature_product',
        'attribute_group','attribute_group_shop','attribute_group_lang','attribute','attribute_shop','attribute_lang',
        'product_attribute','product_attribute_shop','product_attribute_combination','product_attribute_image',
        'specific_price','cart_product','image','image_shop',
        'li_matterhornim_99dfbf_run','li_matterhornim_99dfbf_snapshot','li_matterhornim_99dfbf_mapping',
        'li_matterhornim_99dfbf_category_mapping','li_matterhornim_99dfbf_feature_mapping',
        'li_matterhornim_99dfbf_feature_value_mapping','li_matterhornim_99dfbf_feature_state',
        'li_matterhornim_99dfbf_combination_mapping','li_matterhornim_99dfbf_specific_price_state',
        'li_matterhornim_99dfbf_new_product_queue','li_matterhornim_99dfbf_error',
        'li_matterhornim_99dfbf_image_queue','li_matterhornim_99dfbf_image_state','li_matterhornim_99dfbf_image_orphan',
        'li_matterhornim_99dfbf_attribute_group_mapping','li_matterhornim_99dfbf_attribute_value_mapping',
    ];
    private bool $checked = false;

    public function assertTransactionalCore(): void
    {
        if ($this->checked) { return; }
        $database = defined('_DB_NAME_') ? trim((string) _DB_NAME_) : '';
        if ($database === '') { throw new \RuntimeException('Matterhorn import cannot verify transactional safety: database name unavailable'); }
        $db = \Db::getInstance();
        $rows = $db->executeS('SHOW TABLE STATUS FROM `' . str_replace('`', '``', $database) . '`', true, false);
        if (!is_array($rows)) { throw new \RuntimeException('Matterhorn import table-engine discovery failed: ' . $db->getMsgError()); }
        $engines = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['Name'])) { $engines[(string) $row['Name']] = strtoupper(trim((string) ($row['Engine'] ?? ''))); }
        }
        foreach (self::TABLES as $table) {
            $name = _DB_PREFIX_ . $table;
            $engine = $engines[$name] ?? '';
            if ($engine !== 'INNODB') {
                throw new \RuntimeException('Matterhorn import requires InnoDB; table ' . $name . ' engine=' . ($engine === '' ? 'missing' : $engine));
            }
        }

        $mappingTable = _DB_PREFIX_ . 'li_matterhornim_99dfbf_mapping';
        $ownerRows = $db->executeS(
            "SELECT COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS " .
            "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . pSQL($mappingTable) . "' " .
            "AND INDEX_NAME='uq_shop_product_owner' ORDER BY SEQ_IN_INDEX",
            true,
            false
        ) ?: [];
        $ownerColumns = array_map(static fn(array $row): string => (string) ($row['COLUMN_NAME'] ?? ''), $ownerRows);
        $ownerUnique = $ownerRows !== [];
        foreach ($ownerRows as $row) { $ownerUnique = $ownerUnique && (int) ($row['NON_UNIQUE'] ?? 1) === 0; }
        if ($ownerColumns !== ['id_shop', 'id_product'] || !$ownerUnique) {
            throw new \RuntimeException(
                'Matterhorn import requires exclusive product ownership index uq_shop_product_owner(id_shop,id_product); ' .
                'run module upgrade 0.1.7 and resolve any legacy cross-source ownership conflicts'
            );
        }

        $this->checked = true;
    }
}
