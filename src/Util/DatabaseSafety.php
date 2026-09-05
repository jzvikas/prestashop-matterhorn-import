<?php
namespace Lp\MatterhornImport\Util;

final class DatabaseSafety
{
    private const TABLES = [
        'product','product_shop','product_lang','stock_available',
        'category','category_shop','category_lang','category_product',
        'manufacturer','manufacturer_shop',
        'feature','feature_shop','feature_lang','feature_value','feature_value_lang','feature_product',
        'attribute_group','attribute_group_shop','attribute_group_lang','attribute','attribute_shop','attribute_lang',
        'product_attribute','product_attribute_shop','product_attribute_combination','product_attribute_image',
        'specific_price','cart_product','image','image_shop',
        'li_matterhornim_99dfbf_run','li_matterhornim_99dfbf_snapshot','li_matterhornim_99dfbf_mapping',
        'li_matterhornim_99dfbf_category_mapping','li_matterhornim_99dfbf_feature_mapping',
        'li_matterhornim_99dfbf_feature_value_mapping','li_matterhornim_99dfbf_feature_state',
        'li_matterhornim_99dfbf_combination_mapping','li_matterhornim_99dfbf_specific_price_state',
        'li_matterhornim_99dfbf_new_product_queue','li_matterhornim_99dfbf_error',
        'li_matterhornim_99dfbf_image_queue','li_matterhornim_99dfbf_image_state',
        'li_matterhornim_99dfbf_attribute_group_mapping','li_matterhornim_99dfbf_attribute_value_mapping',
    ];
    private bool $checked = false;

    public function assertTransactionalCore(): void
    {
        if ($this->checked) { return; }
        $database = defined('_DB_NAME_') ? trim((string) _DB_NAME_) : '';
        if ($database === '') { throw new \RuntimeException('Matterhorn import cannot verify transactional safety: database name unavailable'); }
        $db = \Db::getInstance();
        $rows = $db->executeS('SHOW TABLE STATUS FROM `' . str_replace('`', '``', $database) . '`');
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
        $this->checked = true;
    }
}
