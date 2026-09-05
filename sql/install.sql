CREATE TABLE IF NOT EXISTS `PREFIX_li_matterhornim_99dfbf_run` (
  `id_run` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_shop` INT UNSIGNED NOT NULL,
  `source` VARCHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `read_status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `import_status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `update_status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `remove_status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `source_total` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `source_valid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `source_invalid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `source_duplicate` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `read_checkpoint` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `source_fingerprint` CHAR(64) NULL,
  `source_policy_hash` CHAR(64) NULL,
  `import_done` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `import_failed` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `update_done` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `update_skipped` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `update_failed` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `remove_done` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `remove_failed` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `started_at` DATETIME NOT NULL,
  `finished_at` DATETIME NULL,
  PRIMARY KEY (`id_run`),
  KEY `idx_shop_source_status` (`id_shop`,`source`,`status`),
  KEY `idx_shop_source_run` (`id_shop`,`source`,`id_run`),
  KEY `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_li_matterhornim_99dfbf_snapshot` (
  `id_run` BIGINT UNSIGNED NOT NULL,
  `source_key` VARCHAR(191) NOT NULL,
  `reference` VARCHAR(128) NULL,
  `payload_hash` CHAR(16) NOT NULL,
  `core_hash` CHAR(16) NOT NULL,
  `price_hash` CHAR(16) NOT NULL,
  `stock_hash` CHAR(16) NOT NULL,
  `attribute_hash` CHAR(16) NOT NULL,
  `feature_hash` CHAR(16) NOT NULL,
  `category_hash` CHAR(16) NOT NULL,
  `combination_hash` CHAR(16) NOT NULL,
  `combination_stock_hash` CHAR(16) NOT NULL,
  `specific_price_hash` CHAR(16) NOT NULL,
  `image_hash` CHAR(16) NOT NULL,
  `payload` MEDIUMTEXT NOT NULL,
  PRIMARY KEY (`id_run`,`source_key`),
  KEY `idx_run_hash` (`id_run`,`payload_hash`),
  KEY `idx_reference` (`reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_li_matterhornim_99dfbf_mapping` (
  `id_shop` INT UNSIGNED NOT NULL,
  `source` VARCHAR(64) NOT NULL,
  `source_key` VARCHAR(191) NOT NULL,
  `id_product` INT UNSIGNED NOT NULL,
  `payload_hash` CHAR(16) NOT NULL,
  `core_hash` CHAR(16) NOT NULL,
  `price_hash` CHAR(16) NOT NULL,
  `stock_hash` CHAR(16) NOT NULL,
  `attribute_hash` CHAR(16) NOT NULL,
  `feature_hash` CHAR(16) NOT NULL,
  `category_hash` CHAR(16) NOT NULL,
  `combination_hash` CHAR(16) NOT NULL,
  `combination_stock_hash` CHAR(16) NOT NULL,
  `specific_price_hash` CHAR(16) NOT NULL,
  `image_hash` CHAR(16) NOT NULL,
  `out_of_feed` TINYINT(1) NOT NULL DEFAULT 0,
  `last_seen_run_id` BIGINT UNSIGNED NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_shop`,`source`,`source_key`),
  UNIQUE KEY `uq_shop_source_product` (`id_shop`,`source`,`id_product`),
  KEY `idx_seen` (`id_shop`,`source`,`last_seen_run_id`),
  KEY `idx_feed_state` (`id_shop`,`source`,`out_of_feed`,`last_seen_run_id`),
  KEY `idx_feed_product` (`id_shop`,`source`,`out_of_feed`,`id_product`),
  KEY `idx_product` (`id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_li_matterhornim_99dfbf_category_mapping` (
  `id_shop` INT UNSIGNED NOT NULL,
  `supplier_key` VARCHAR(191) NOT NULL,
  `supplier_parent_key` VARCHAR(191) NULL,
  `supplier_name` VARCHAR(255) NOT NULL,
  `supplier_path` TEXT NULL,
  `id_category` INT UNSIGNED NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_shop`,`supplier_key`),
  KEY `idx_category` (`id_shop`,`id_category`),
  KEY `idx_parent` (`id_shop`,`supplier_parent_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_li_matterhornim_99dfbf_feature_mapping` (
  `id_shop` INT UNSIGNED NOT NULL,
  `source` VARCHAR(64) NOT NULL,
  `supplier_feature_key` VARCHAR(191) NOT NULL,
  `supplier_name` VARCHAR(128) NOT NULL,
  `id_feature` INT UNSIGNED NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_shop`,`source`,`supplier_feature_key`),
  KEY `idx_feature` (`id_shop`,`id_feature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_li_matterhornim_99dfbf_feature_value_mapping` (
  `id_shop` INT UNSIGNED NOT NULL,
  `source` VARCHAR(64) NOT NULL,
  `supplier_feature_key` VARCHAR(191) NOT NULL,
  `supplier_value_key` VARCHAR(191) NOT NULL,
  `supplier_value` VARCHAR(255) NOT NULL,
  `id_feature` INT UNSIGNED NOT NULL,
  `id_feature_value` INT UNSIGNED NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_shop`,`source`,`supplier_feature_key`,`supplier_value_key`),
  KEY `idx_value` (`id_shop`,`id_feature`,`id_feature_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_li_matterhornim_99dfbf_feature_state` (
  `id_shop` INT UNSIGNED NOT NULL,
  `source` VARCHAR(64) NOT NULL,
  `source_key` VARCHAR(191) NOT NULL,
  `id_product` INT UNSIGNED NOT NULL,
  `id_feature` INT UNSIGNED NOT NULL,
  `id_feature_value` INT UNSIGNED NOT NULL,
  `last_seen_run_id` BIGINT UNSIGNED NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_shop`,`source`,`source_key`,`id_feature`),
  KEY `idx_product` (`id_shop`,`source`,`id_product`),
  KEY `idx_value` (`id_feature`,`id_feature_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_li_matterhornim_99dfbf_combination_mapping` (
  `id_shop` INT UNSIGNED NOT NULL,
  `source` VARCHAR(64) NOT NULL,
  `source_key` VARCHAR(191) NOT NULL,
  `semantic_key` CHAR(64) NOT NULL,
  `id_product` INT UNSIGNED NOT NULL,
  `id_product_attribute` INT UNSIGNED NOT NULL,
  `structure_hash` CHAR(16) NOT NULL,
  `stock_hash` CHAR(16) NOT NULL,
  `last_seen_run_id` BIGINT UNSIGNED NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_shop`,`source`,`source_key`,`semantic_key`),
  UNIQUE KEY `uq_shop_product_attribute` (`id_shop`,`id_product_attribute`),
  KEY `idx_product` (`id_shop`,`source`,`id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_li_matterhornim_99dfbf_specific_price_state` (
  `id_shop` INT UNSIGNED NOT NULL,
  `source` VARCHAR(64) NOT NULL,
  `source_key` VARCHAR(191) NOT NULL,
  `id_product` INT UNSIGNED NOT NULL,
  `semantic_key` CHAR(64) NOT NULL,
  `id_specific_price` INT UNSIGNED NOT NULL,
  `applied_hash` CHAR(16) NOT NULL,
  `last_seen_run_id` BIGINT UNSIGNED NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_shop`,`source`,`source_key`,`semantic_key`),
  UNIQUE KEY `uq_owned_specific_price` (`id_shop`,`id_specific_price`),
  KEY `idx_product` (`id_shop`,`source`,`id_product`),
  KEY `idx_seen` (`id_shop`,`source`,`last_seen_run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_li_matterhornim_99dfbf_new_product_queue` (
  `id_queue` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_run` BIGINT UNSIGNED NOT NULL,
  `id_shop` INT UNSIGNED NOT NULL,
  `source` VARCHAR(64) NOT NULL,
  `source_key` VARCHAR(191) NOT NULL,
  `payload` MEDIUMTEXT NOT NULL,
  `payload_hash` CHAR(16) NOT NULL,
  `id_product` INT UNSIGNED NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `available_at` DATETIME NULL,
  `locked_by` VARCHAR(64) NULL,
  `locked_until` DATETIME NULL,
  `last_error` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_queue`),
  UNIQUE KEY `uq_shop_source_key` (`id_shop`,`source`,`source_key`),
  KEY `idx_claim` (`status`,`available_at`,`locked_until`,`id_queue`),
  KEY `idx_shop_claim` (`id_shop`,`status`,`available_at`,`id_queue`),
  KEY `idx_run` (`id_run`,`status`),
  KEY `idx_product` (`id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_li_matterhornim_99dfbf_error` (
  `id_error` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_run` BIGINT UNSIGNED NOT NULL,
  `stage` VARCHAR(16) NOT NULL,
  `source_key` VARCHAR(191) NULL,
  `message` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_error`),
  KEY `idx_run_stage` (`id_run`,`stage`,`id_error`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_li_matterhornim_99dfbf_image_state` (
  `id_shop` INT UNSIGNED NOT NULL,
  `source` VARCHAR(64) NOT NULL,
  `source_key` VARCHAR(191) NOT NULL,
  `url_hash` CHAR(64) NOT NULL,
  `content_hash` CHAR(64) NULL,
  `etag` VARCHAR(255) NULL,
  `last_modified` VARCHAR(255) NULL,
  `mime` VARCHAR(64) NULL,
  `width` INT UNSIGNED NOT NULL DEFAULT 0,
  `height` INT UNSIGNED NOT NULL DEFAULT 0,
  `bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `id_product` INT UNSIGNED NOT NULL,
  `id_image` INT UNSIGNED NOT NULL,
  `position` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_cover` TINYINT(1) NOT NULL DEFAULT 0,
  `last_seen_run_id` BIGINT UNSIGNED NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_shop`,`source`,`source_key`,`url_hash`),
  KEY `idx_product` (`id_product`),
  KEY `idx_image` (`id_image`),
  KEY `idx_content` (`id_shop`,`source`,`id_product`,`content_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_li_matterhornim_99dfbf_image_queue` (
  `id_queue` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_run` BIGINT UNSIGNED NOT NULL,
  `id_shop` INT UNSIGNED NOT NULL,
  `source` VARCHAR(64) NOT NULL,
  `source_key` VARCHAR(191) NOT NULL,
  `id_product` INT UNSIGNED NOT NULL,
  `url` TEXT NOT NULL,
  `url_hash` CHAR(64) NOT NULL,
  `position` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_cover` TINYINT(1) NOT NULL DEFAULT 0,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `available_at` DATETIME NULL,
  `locked_by` VARCHAR(64) NULL,
  `locked_until` DATETIME NULL,
  `last_error` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_queue`),
  UNIQUE KEY `uq_product_url` (`id_shop`,`id_product`,`url_hash`),
  KEY `idx_claim` (`status`,`available_at`,`locked_until`,`id_queue`),
  KEY `idx_shop_claim` (`id_shop`,`status`,`available_at`,`id_queue`),
  KEY `idx_run` (`id_run`,`status`),
  KEY `idx_product` (`id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
