<?php
if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_0_1_3($module): bool
{
    $db = \Db::getInstance();
    $indexes = [
        'li_matterhornim_99dfbf_run' => [
            'idx_shop_source_run' => '(`id_shop`,`source`,`id_run`)',
        ],
        'li_matterhornim_99dfbf_mapping' => [
            'idx_feed_product' => '(`id_shop`,`source`,`out_of_feed`,`id_product`)',
        ],
        'li_matterhornim_99dfbf_image_queue' => [
            'idx_shop_claim' => '(`id_shop`,`status`,`available_at`,`id_queue`)',
        ],
        'li_matterhornim_99dfbf_new_product_queue' => [
            'idx_shop_claim' => '(`id_shop`,`status`,`available_at`,`id_queue`)',
        ],
    ];

    foreach ($indexes as $suffix => $definitions) {
        $table = _DB_PREFIX_ . $suffix;
        foreach ($definitions as $index => $definition) {
            $exists = (bool) $db->getValue(
                "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() " .
                "AND TABLE_NAME='" . pSQL($table) . "' AND INDEX_NAME='" . pSQL($index) . "' LIMIT 1"
            );
            if (!$exists && !$db->execute(
                'ALTER TABLE `' . bqSQL($table) . '` ADD KEY `' . bqSQL($index) . '` ' . $definition
            )) {
                return false;
            }
        }
    }

    return true;
}
