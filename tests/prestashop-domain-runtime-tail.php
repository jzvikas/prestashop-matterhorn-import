<?php

domainAssert(freshStockQuantity($p206, $combinationId, 1) === 2, 'Initial XS stock mismatch');

$combinationMapping = $db->getRow(
    "SELECT source,source_key,semantic_key,id_product,id_product_attribute,structure_hash,stock_hash,last_seen_run_id FROM `{$table}combination_mapping` WHERE id_shop=1 AND id_product_attribute={$combinationId}",
    false
);
domainAssert(is_array($combinationMapping), 'Combination ownership row missing before collision test');
$foreignSource = 'runtime-foreign';
$foreignSourceKey = 'runtime-foreign-key';
$foreignSemanticKey = hash('sha256', 'runtime-foreign-combination-owner');
domainAssert($db->update(
    'li_matterhornim_99dfbf_combination_mapping',
    ['source' => $foreignSource, 'source_key' => $foreignSourceKey, 'semantic_key' => $foreignSemanticKey],
    'id_shop=1 AND id_product_attribute=' . $combinationId,
    0,
    false,
    false
), 'Could not prepare foreign combination owner collision');
$foreignPrepared = $db->getRow(
    "SELECT source,source_key,semantic_key,id_product,id_product_attribute,structure_hash,stock_hash,last_seen_run_id FROM `{$table}combination_mapping` WHERE id_shop=1 AND id_product_attribute={$combinationId}",
    false
);
domainAssert(
    is_array($foreignPrepared)
    && (string) $foreignPrepared['source'] === $foreignSource
    && (string) $foreignPrepared['source_key'] === $foreignSourceKey
    && (string) $foreignPrepared['semantic_key'] === $foreignSemanticKey,
    'Foreign combination owner fixture was not persisted'
);
$collisionCaught = false;
try {
    (new \Lp\MatterhornImport\Repository\CombinationMappingRepository())->save(
        1,
        (string) $combinationMapping['source'],
        (string) $combinationMapping['source_key'],
        (string) $combinationMapping['semantic_key'],
        (int) $combinationMapping['id_product'],
        (int) $combinationMapping['id_product_attribute'],
        (string) $combinationMapping['structure_hash'],
        (string) $combinationMapping['stock_hash'],
        (int) $combinationMapping['last_seen_run_id']
    );
} catch (RuntimeException $e) {
    $collisionCaught = str_contains($e->getMessage(), 'Combination attribute ownership conflict:');
}
domainAssert($collisionCaught, 'Foreign combination attribute owner did not fail closed');
$afterCollision = $db->getRow(
    "SELECT source,source_key,semantic_key,id_product,id_product_attribute,structure_hash,stock_hash,last_seen_run_id FROM `{$table}combination_mapping` WHERE id_shop=1 AND id_product_attribute={$combinationId}",
    false
);
domainAssert(
    is_array($afterCollision)
    && (string) $afterCollision['source'] === $foreignSource
    && (string) $afterCollision['source_key'] === $foreignSourceKey
    && (string) $afterCollision['semantic_key'] === $foreignSemanticKey
    && (int) $afterCollision['id_product'] === (int) $foreignPrepared['id_product']
    && (string) $afterCollision['structure_hash'] === (string) $foreignPrepared['structure_hash']
    && (string) $afterCollision['stock_hash'] === (string) $foreignPrepared['stock_hash']
    && (int) $afterCollision['last_seen_run_id'] === (int) $foreignPrepared['last_seen_run_id'],
    'Rejected combination ownership collision mutated the foreign row'
);
domainAssert($db->update(
    'li_matterhornim_99dfbf_combination_mapping',
    [
        'source' => (string) $combinationMapping['source'],
        'source_key' => (string) $combinationMapping['source_key'],
        'semantic_key' => (string) $combinationMapping['semantic_key'],
    ],
    "id_shop=1 AND id_product_attribute={$combinationId} AND source='" . pSQL($foreignSource) . "' AND source_key='" . pSQL($foreignSourceKey) . "'",
    0,
    false,
    false
), 'Could not restore Matterhorn combination owner after collision test');
$restoredOwner = $db->getRow(
    "SELECT source,source_key,semantic_key,id_product,id_product_attribute FROM `{$table}combination_mapping` WHERE id_shop=1 AND id_product_attribute={$combinationId}",
    false
);
domainAssert(
    is_array($restoredOwner)
    && (string) $restoredOwner['source'] === (string) $combinationMapping['source']
    && (string) $restoredOwner['source_key'] === (string) $combinationMapping['source_key']
    && (string) $restoredOwner['semantic_key'] === (string) $combinationMapping['semantic_key']
    && (int) $restoredOwner['id_product'] === (int) $combinationMapping['id_product']
    && (int) $restoredOwner['id_product_attribute'] === $combinationId,
    'Matterhorn combination owner was not restored after collision test'
);

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
