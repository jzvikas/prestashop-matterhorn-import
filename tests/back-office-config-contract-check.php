<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$module = file_get_contents($root . '/matterhornimport.php');
$settings = file_get_contents($root . '/src/Config/OperationalSettings.php');
$images = file_get_contents($root . '/src/Command/ImagesCommand.php');
$newProducts = file_get_contents($root . '/src/Command/NewProductsCommand.php');
$retry = file_get_contents($root . '/src/Command/RetryCommand.php');
$mapper = file_get_contents($root . '/src/Mapper/MatterhornProductMapper.php');
$writer = file_get_contents($root . '/src/Product/MatterhornProductWriter.php');

$checks = [
    [$settings, "MATTERHORNIMPORT_IMAGE_WORKER_LIMIT", 'shop-scoped image setting key'],
    [$settings, "MATTERHORNIMPORT_NEW_PRODUCT_WORKER_LIMIT", 'shop-scoped new-product setting key'],
    [$settings, 'shop/group mismatch', 'shop/group ownership validation'],
    [$module, 'Select one concrete shop before configuring Matterhorn Import.', 'concrete-shop BO guard'],
    [$module, '$settings->save($shopId, $shopGroupId', 'shop-scoped BO settings persistence'],
    [$module, 'Current shop status', 'BO status panel'],
    [$module, 'matterhornimport:doctor --shop=', 'BO CLI diagnostics documentation'],
    [$module, 'MATTERHORNIMPORT_SOURCE_LANGUAGE_ID', 'source-language BO field'],
    [$module, 'MATTERHORNIMPORT_CATEGORY_AUTO_CREATE', 'category auto-create BO field'],
    [$module, 'MATTERHORNIMPORT_FEATURE_AUTO_CREATE', 'feature auto-create BO field'],
    [$module, 'Source language must belong to the selected shop.', 'source-language shop validation'],
    [$mapper, "configurationBool('MATTERHORNIMPORT_CATEGORY_AUTO_CREATE'", 'category policy applied during READ mapping'],
    [$mapper, "configurationBool('MATTERHORNIMPORT_FEATURE_AUTO_CREATE'", 'feature policy applied during READ mapping'],
    [$mapper, "extra['source_language_id']", 'source language captured in snapshot payload'],
    [$writer, "data->extra['source_language_id']", 'writer consumes snapshot language policy'],
    [$images, '$this->settings->imageWorkerLimit($shopId)', 'image worker BO default'],
    [$newProducts, '$this->settings->newProductWorkerLimit($shopId)', 'new-product worker BO default'],
    [$retry, '$this->settings->retryLimit($shopId)', 'retry BO default'],
];
foreach ($checks as [$haystack, $needle, $label]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}

echo "Back Office config contract: OK\n";
