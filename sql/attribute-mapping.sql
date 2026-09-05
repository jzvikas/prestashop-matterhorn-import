CREATE TABLE IF NOT EXISTS `PREFIX_li_matterhornim_99dfbf_attribute_group_mapping` (
  `id_shop` INT UNSIGNED NOT NULL,
  `source` VARCHAR(64) NOT NULL,
  `supplier_group_key` VARCHAR(191) NOT NULL,
  `supplier_name` VARCHAR(128) NOT NULL,
  `id_attribute_group` INT UNSIGNED NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_shop`,`source`,`supplier_group_key`),
  KEY `idx_attribute_group` (`id_shop`,`id_attribute_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_li_matterhornim_99dfbf_attribute_value_mapping` (
  `id_shop` INT UNSIGNED NOT NULL,
  `source` VARCHAR(64) NOT NULL,
  `supplier_group_key` VARCHAR(191) NOT NULL,
  `supplier_value_key` VARCHAR(191) NOT NULL,
  `supplier_value` VARCHAR(255) NOT NULL,
  `id_attribute_group` INT UNSIGNED NOT NULL,
  `id_attribute` INT UNSIGNED NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_shop`,`source`,`supplier_group_key`,`supplier_value_key`),
  KEY `idx_attribute` (`id_shop`,`id_attribute_group`,`id_attribute`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
