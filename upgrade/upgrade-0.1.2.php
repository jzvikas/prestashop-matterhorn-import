<?php
if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_0_1_2($module): bool
{
    $table = bqSQL(_DB_PREFIX_ . 'li_matterhornim_99dfbf_image_orphan');
    $sql = 'CREATE TABLE IF NOT EXISTS `' . $table . '` (' .
        '`id_orphan` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,' .
        '`id_queue` BIGINT UNSIGNED NOT NULL,' .
        '`id_run` BIGINT UNSIGNED NOT NULL,' .
        '`id_shop` INT UNSIGNED NOT NULL,' .
        '`source` VARCHAR(64) NOT NULL,' .
        '`source_key` VARCHAR(191) NOT NULL,' .
        '`id_product` INT UNSIGNED NOT NULL,' .
        '`id_image` INT UNSIGNED NOT NULL,' .
        '`reason` VARCHAR(64) NOT NULL,' .
        '`attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,' .
        '`available_at` DATETIME NULL,' .
        '`last_error` TEXT NULL,' .
        '`created_at` DATETIME NOT NULL,' .
        '`updated_at` DATETIME NOT NULL,' .
        'PRIMARY KEY (`id_orphan`),' .
        'UNIQUE KEY `uq_shop_product_image` (`id_shop`,`id_product`,`id_image`),' .
        'KEY `idx_retry` (`available_at`,`id_orphan`),' .
        'KEY `idx_shop_retry` (`id_shop`,`available_at`,`id_orphan`),' .
        'KEY `idx_queue` (`id_queue`),' .
        'KEY `idx_run` (`id_run`)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    return \Db::getInstance()->execute($sql);
}
