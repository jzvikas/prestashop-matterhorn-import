<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Feature\FeatureNormalizer;

function featureCheck(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$root = dirname(__DIR__);
$mapping = (string) file_get_contents($root . '/src/Repository/FeatureMappingRepository.php');
$state = (string) file_get_contents($root . '/src/Repository/FeatureStateRepository.php');
$sync = (string) file_get_contents($root . '/src/Feature/FeatureSynchronizer.php');
$resolver = (string) file_get_contents($root . '/src/Feature/FeatureResolver.php');
featureCheck(str_contains($mapping, 'li_matterhornim_99dfbf_feature_mapping'), 'feature mapping uses standalone DB token');
featureCheck(str_contains($state, 'li_matterhornim_99dfbf_feature_state'), 'feature state uses standalone DB token');
featureCheck(!str_contains($mapping . $state, 'lp_import_'), 'generic skeleton DB token does not leak into feature runtime');
featureCheck(str_contains($mapping, 'getRow(sprintf(') && str_contains($mapping, '), false);'), 'feature mapping live resolution must bypass Db query cache');
featureCheck(str_contains($mapping, 'private array $pairCache'), 'feature mapping must retain bounded process cache');
featureCheck(str_contains($mapping, '$this->pairCache[$this->cacheKey'), 'feature mapping save must seed the process cache with the committed resolution');
featureCheck(str_contains($sync, 'ownershipDelete') && str_contains($sync, 'ownedValue'), 'authoritative feature sync preserves manual overrides and relinquishes ownership');
featureCheck(str_contains($sync, 'not exclusive to target shop'), 'non-exclusive product feature mutation fails closed');
featureCheck(str_contains($sync, 'assertExclusiveTargetShop($productId, $shopId)'), 'feature mutations must revalidate target-shop exclusivity');
featureCheck(str_contains($sync, '$latestActual = $this->actual($productId)'), 'feature sync must refresh live state before mutation');
featureCheck(str_contains($sync, 'state changed concurrently before synchronization'), 'feature sync must fail closed on pre-mutation concurrent change');
featureCheck(str_contains($sync, "' AND id_feature_value=' . (int) \$valueId"), 'feature delete must fence the exact previously observed value');
featureCheck(str_contains($sync, '$db->Affected_Rows() !== 1'), 'feature delete must detect concurrent row replacement/removal');
featureCheck(str_contains($sync, "executeS(\n            'SELECT id_feature,id_feature_value") && str_contains($sync, "true,\n            false"), 'feature live state reads must bypass Db query cache');
featureCheck(str_contains($sync, 'target_shop_count'), 'feature mutation ownership proof must include target-shop membership');

featureCheck(str_contains($resolver, 'LOCK_TIMEOUT_SECONDS = 10'), 'feature resolver must bound advisory-lock wait');
featureCheck(str_contains($resolver, "'lpimp:feat:'"), 'feature resolver must share lock namespace across import modules');
featureCheck(str_contains($resolver, "'feature:' . \$shopId"), 'feature creation lock must be target-shop/name scoped');
featureCheck(str_contains($resolver, "'value:' . \$featureId"), 'feature-value creation lock must be global feature/value scoped');
featureCheck(substr_count($resolver, '), true, false)') >= 2, 'feature/value exact reads must bypass Db query cache');
featureCheck(str_contains($resolver, 'GET_LOCK') && str_contains($resolver, 'RELEASE_LOCK'), 'feature resolver must acquire and release advisory locks');
featureCheck(!str_contains($resolver, 'featureShopCount'), 'shared feature must be reused instead of creating duplicate same-name feature for a missing value');

$product = new ProductData('206161', 'MH-206161', ['default' => 'Panties'], 14.9, 0, true, [], [
    'features' => [
        ['key' => 'matterhorn:color', 'value_key' => 'matterhorn:color:pink', 'name' => 'Color', 'value' => 'pink'],
        ['key' => 'matterhorn:type', 'value_key' => 'matterhorn:type:panties', 'name' => 'Type', 'value' => 'Panties'],
    ],
]);
$features = (new FeatureNormalizer())->normalize($product);
featureCheck(count($features) === 2, 'two Matterhorn features normalized');
featureCheck($features[0]['key'] === 'matterhorn:color', 'feature normalization is deterministic');

echo "Feature sync contract checks: OK\n";
