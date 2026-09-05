<?php
declare(strict_types=1);

chdir('/var/www/html');
require 'config/config.inc.php';

function domainAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function matterhornFailureDiagnostics(): string
{
    try {
        $db = Db::getInstance();
        $prefix = _DB_PREFIX_ . 'li_matterhornim_99dfbf_';
        $run = $db->getRow(
            "SELECT id_run,status,read_status,import_status,update_status,remove_status,read_done,read_failed,import_done,import_failed,update_done,update_failed,remove_done,remove_failed FROM `{$prefix}run` WHERE id_shop=1 AND source='matterhorn' ORDER BY id_run DESC",
            false
        );
        if (!is_array($run)) { return 'No Matterhorn run diagnostics available.'; }
        $runId = (int) ($run['id_run'] ?? 0);
        $errors = $runId > 0 ? ($db->executeS(
            "SELECT stage,source_key,message,created_at FROM `{$prefix}error` WHERE id_run={$runId} ORDER BY id_error DESC LIMIT 20",
            true,
            false
        ) ?: []) : [];
        $lines = ['Persisted Matterhorn diagnostics: ' . json_encode($run, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        foreach (array_reverse($errors) as $error) {
            $lines[] = sprintf(
                '[%s] stage=%s source_key=%s %s',
                (string) ($error['created_at'] ?? '-'),
                (string) ($error['stage'] ?? '-'),
                (string) (($error['source_key'] ?? '') ?: '-'),
                (string) ($error['message'] ?? '')
            );
        }
        return implode("\n", $lines);
    } catch (Throwable $e) {
        return 'Could not read persisted Matterhorn diagnostics: ' . get_class($e) . ': ' . $e->getMessage();
    }
}

function runMatterhornConsole(array $arguments): string
{
    $parts = ['APP_ENV=prod', 'APP_DEBUG=0', 'php', '-d', 'memory_limit=512M', 'bin/console'];
    foreach ($arguments as $argument) { $parts[] = escapeshellarg((string) $argument); }
    $command = implode(' ', $parts) . ' 2>&1';
    $lines = [];
    $code = 0;
    exec($command, $lines, $code);
    $output = implode("\n", $lines);
    if ($code !== 0) {
        throw new RuntimeException('Console command failed (' . $code . '): ' . $command . "\n" . $output . "\n" . matterhornFailureDiagnostics());
    }
    return $output;
}

function copyRuntimeFeed(string $fixture): void
{
    $source = '/var/www/html/modules/matterhornimport/tests/fixtures/' . $fixture;
    if (!is_file($source) || !copy($source, '/tmp/matterhorn-domain.xml')) {
        throw new RuntimeException('Could not prepare runtime fixture ' . $fixture);
    }
}

function freshStockQuantity(int $productId, int $attributeId, int $shopId): int
{
    // The lifecycle invokes Matterhorn through separate bin/console processes. PrestaShop's
    // StockAvailable API caches quantities inside the current PHP process, so the parent test
    // process must invalidate its own stale view before asserting writes made by a child process.
    Cache::clean('StockAvailable::getQuantityAvailableByProduct_' . $productId . '*');
    return StockAvailable::getQuantityAvailableByProduct($productId, $attributeId, $shopId);
}

$db = Db::getInstance();
Shop::setContext(Shop::CONTEXT_SHOP, 1);
$shop = new Shop(1);
domainAssert(Validate::isLoadedObject($shop), 'Runtime shop #1 missing');
Context::getContext()->shop = $shop;
$languageId = (int) $db->getValue('SELECT id_lang FROM `' . _DB_PREFIX_ . 'lang` WHERE active=1 ORDER BY id_lang ASC', false);
$language = new Language($languageId);
domainAssert($languageId > 0 && Validate::isLoadedObject($language), 'Runtime language missing');
Context::getContext()->language = $language;
$groupId = (int) $shop->id_shop_group;

foreach ([
    'MATTERHORNIMPORT_SOURCE_FILE' => '/tmp/matterhorn-domain.xml',
    'MATTERHORNIMPORT_SOURCE_LANGUAGE_ID' => (string) $languageId,
    'MATTERHORNIMPORT_CATEGORY_AUTO_CREATE' => '1',
    'MATTERHORNIMPORT_FEATURE_AUTO_CREATE' => '1',
    'MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME' => 'Runtime Size',
    'MATTERHORNIMPORT_MAX_REMOVE_PERCENT' => '100',
] as $key => $value) {
    domainAssert(Configuration::updateValue($key, $value, false, $groupId, 1), 'Could not configure ' . $key);
}

$table = _DB_PREFIX_ . 'li_matterhornim_99dfbf_';
copyRuntimeFeed('matterhorn-sample.xml');
$createOutput = runMatterhornConsole(['matterhornimport:run','--shop=1','--batch=10','--max-items=100','--time-limit=120','--json']);
$createRun = (int) $db->getValue("SELECT id_run FROM `{$table}run` WHERE id_shop=1 AND source='matterhorn' ORDER BY id_run DESC", false);
domainAssert($createRun > 0, 'CREATE run missing');
domainAssert((string) $db->getValue("SELECT status FROM `{$table}run` WHERE id_run={$createRun}", false) === 'completed', 'CREATE run did not complete: ' . $createOutput);
domainAssert((int) $db->getValue("SELECT COUNT(*) FROM `{$table}mapping` WHERE id_shop=1 AND source='matterhorn' AND out_of_feed=0", false) === 3, 'CREATE mapping count mismatch');

$p206 = (int) $db->getValue("SELECT id_product FROM `{$table}mapping` WHERE id_shop=1 AND source='matterhorn' AND source_key='206161'", false);
$p228 = (int) $db->getValue("SELECT id_product FROM `{$table}mapping` WHERE id_shop=1 AND source='matterhorn' AND source_key='228723'", false);
domainAssert($p206 > 0 && $p228 > 0, 'Matterhorn product mappings missing');
domainAssert((string) $db->getValue("SELECT reference FROM `" . _DB_PREFIX_ . "product` WHERE id_product={$p206}", false) === 'MH-206161', 'Stable product reference mismatch');
domainAssert(abs((float) $db->getValue("SELECT price FROM `" . _DB_PREFIX_ . "product_shop` WHERE id_product={$p206} AND id_shop=1", false) - 14.9) < 0.0001, 'Initial price mismatch');
domainAssert((string) $db->getValue("SELECT m.name FROM `" . _DB_PREFIX_ . "product` p JOIN `" . _DB_PREFIX_ . "manufacturer` m ON m.id_manufacturer=p.id_manufacturer WHERE p.id_product={$p206}", false) === 'Axami', 'Manufacturer mapping failed');
domainAssert((int) $db->getValue("SELECT COUNT(*) FROM `{$table}category_mapping` WHERE id_shop=1 AND supplier_key='matterhorn-category:3' AND id_category IS NOT NULL", false) === 1, 'Category mapping failed');
domainAssert((int) $db->getValue("SELECT COUNT(*) FROM `{$table}feature_state` WHERE id_shop=1 AND source='matterhorn' AND source_key='206161'", false) === 2, 'Color/Type feature state mismatch');
domainAssert((int) $db->getValue("SELECT COUNT(*) FROM `{$table}combination_mapping` WHERE id_shop=1 AND source='matterhorn' AND source_key='206161'", false) === 2, 'Size combination count mismatch');

$combination = $db->getRow("SELECT pa.id_product_attribute,pa.reference,pa.ean13 FROM `" . _DB_PREFIX_ . "product_attribute` pa JOIN `{$table}combination_mapping` cm ON cm.id_product_attribute=pa.id_product_attribute WHERE cm.id_shop=1 AND cm.source='matterhorn' AND cm.source_key='206161' AND pa.reference='M1188149'", false);
domainAssert(is_array($combination) && (int) $combination['id_product_attribute'] > 0, 'XS combination missing');
domainAssert((string) $combination['ean13'] === '5902934981668', 'Combination EAN mismatch');
$combinationId = (int) $combination['id_product_attribute'];
domainAssert(freshStockQuantity($p206, $combinationId, 1) === 2, 'Initial XS stock mismatch');
$description = (string) $db->getValue("SELECT description FROM `" . _DB_PREFIX_ . "product_lang` WHERE id_product={$p206} AND id_shop=1 AND id_lang={$languageId}", false);
domainAssert(str_contains($description, 'Charming figs'), 'Matterhorn description missing');
domainAssert((int) $db->getValue("SELECT COUNT(*) FROM `{$table}image_queue` WHERE id_run={$createRun} AND id_shop=1 AND source='matterhorn' AND source_key='206161'", false) === 4, 'Image manifest queue count mismatch');

$old = $db->getRow("SELECT core_hash,price_hash,feature_hash,category_hash,combination_hash,combination_stock_hash,image_hash FROM `{$table}mapping` WHERE id_shop=1 AND source='matterhorn' AND source_key='206161'", false);
domainAssert(is_array($old), 'Initial domain hash row missing');

copyRuntimeFeed('matterhorn-sample-updated.xml');
runMatterhornConsole(['matterhornimport:read','--shop=1','--max-items=100','--time-limit=120','--json']);
$updateRun = (int) $db->getValue("SELECT id_run FROM `{$table}run` WHERE id_shop=1 AND source='matterhorn' ORDER BY id_run DESC", false);
domainAssert($updateRun > $createRun, 'Changed feed did not create a new run');
runMatterhornConsole(['matterhornimport:import','--run=' . $updateRun,'--shop=1','--batch=10','--max-items=100','--time-limit=120','--json']);
runMatterhornConsole(['matterhornimport:update','--run=' . $updateRun,'--shop=1','--batch=10','--max-items=100','--time-limit=120','--json']);

domainAssert((int) $db->getValue("SELECT id_product FROM `{$table}mapping` WHERE id_shop=1 AND source='matterhorn' AND source_key='206161'", false) === $p206, 'Stable product identity changed during UPDATE');
domainAssert(abs((float) $db->getValue("SELECT price FROM `" . _DB_PREFIX_ . "product_shop` WHERE id_product={$p206} AND id_shop=1", false) - 15.9) < 0.0001, 'Updated price mismatch');
domainAssert(freshStockQuantity($p206, $combinationId, 1) === 7, 'Updated XS stock mismatch');
$new = $db->getRow("SELECT core_hash,price_hash,feature_hash,category_hash,combination_hash,combination_stock_hash,image_hash FROM `{$table}mapping` WHERE id_shop=1 AND source='matterhorn' AND source_key='206161'", false);
domainAssert(is_array($new), 'Updated domain hash row missing');
domainAssert((string) $new['price_hash'] !== (string) $old['price_hash'], 'Price hash did not change');
domainAssert((string) $new['combination_stock_hash'] !== (string) $old['combination_stock_hash'], 'Combination-stock hash did not change');
foreach (['core_hash','feature_hash','category_hash','combination_hash','image_hash'] as $key) {
    domainAssert((string) $new[$key] === (string) $old[$key], $key . ' changed unexpectedly');
}

$dryRunOutput = runMatterhornConsole(['matterhornimport:remove','--run=' . $updateRun,'--shop=1','--batch=10','--max-items=100','--time-limit=120','--dry-run']);
$dryRunLine = trim((string) array_reverse(array_filter(preg_split('/\R/', $dryRunOutput) ?: []))[0]);
$dryRun = json_decode($dryRunLine, true, 512, JSON_THROW_ON_ERROR);
domainAssert((int) ($dryRun['candidates'] ?? -1) === 1 && (bool) ($dryRun['safe'] ?? false), 'REMOVE dry-run plan mismatch: ' . $dryRunOutput);
domainAssert((int) $db->getValue("SELECT active FROM `" . _DB_PREFIX_ . "product_shop` WHERE id_product={$p228} AND id_shop=1", false) === 1, 'REMOVE dry-run mutated product');
runMatterhornConsole(['matterhornimport:remove','--run=' . $updateRun,'--shop=1','--batch=10','--max-items=100','--time-limit=120','--json']);
domainAssert((int) $db->getValue("SELECT active FROM `" . _DB_PREFIX_ . "product_shop` WHERE id_product={$p228} AND id_shop=1", false) === 0, 'Out-of-feed product not deactivated');
domainAssert(freshStockQuantity($p228, 0, 1) === 0, 'Out-of-feed base stock not zero');
foreach (Product::getProductAttributesIds($p228) as $attributeRow) {
    $attributeId = (int) ($attributeRow['id_product_attribute'] ?? 0);
    if ($attributeId > 0) {
        domainAssert(freshStockQuantity($p228, $attributeId, 1) === 0, 'Out-of-feed combination stock not zero: ' . $attributeId);
    }
}
domainAssert((int) $db->getValue("SELECT out_of_feed FROM `{$table}mapping` WHERE id_shop=1 AND source='matterhorn' AND source_key='228723'", false) === 1, 'Out-of-feed mapping state missing');
domainAssert((string) $db->getValue("SELECT status FROM `{$table}run` WHERE id_run={$updateRun}", false) === 'completed', 'Changed-feed lifecycle did not complete');

echo "MATTERHORN_DOMAIN_RUNTIME_OK create_run={$createRun} update_run={$updateRun} product={$p206}\n";
