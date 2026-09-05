ALTER TABLE `PREFIX_li_matterhornim_99dfbf_run`
  ADD KEY `idx_shop_source_run` (`id_shop`,`source`,`id_run`);

ALTER TABLE `PREFIX_li_matterhornim_99dfbf_mapping`
  ADD KEY `idx_feed_product` (`id_shop`,`source`,`out_of_feed`,`id_product`);

ALTER TABLE `PREFIX_li_matterhornim_99dfbf_image_queue`
  ADD KEY `idx_shop_claim` (`id_shop`,`status`,`available_at`,`id_queue`);

ALTER TABLE `PREFIX_li_matterhornim_99dfbf_new_product_queue`
  ADD KEY `idx_shop_claim` (`id_shop`,`status`,`available_at`,`id_queue`);
