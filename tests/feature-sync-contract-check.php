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
$genericDbToken = 'lp_' . 'import_';
featureCheck(str_contains($mapping, 'li_matterhornim_99dfbf_feature_mapping'), 'feature mapping uses standalone DB token');
featureCheck(str_contains($state, 'li_matterhornim_99dfbf_feature_state'), 'feature state uses standalone DB token');
featureCheck(!str_contains($mapping . $state, $genericDbToken), 'generic skeleton DB token does not leak into feature runtime');
featureCheck(str_contains($mapping, 'getRow(sprintf(') && str_contains($mapping, '), false);'), 'feature mapping live resolution must bypass Db query cache');
featureCheck(str_contains($mapping, 'private array $pairCache'), 'feature mapping must retain bounded process cache');
featureCheck(str_contains($mapping, '$this->pairCache[$cacheKey]'), 'feature mapping save must seed the process cache with the committed resolution');
featureCheck(str_contains($mapping, 'assertSemanticIdentity('), 'feature mapping must expose persistent semantic identity preflight');
featureCheck(str_contains($sync, '->assertSemanticIdentity('), 'feature synchronization must preflight semantic identity before resolve/create');
featureCheck(str_contains($mapping, 'Feature semantic identity collision'), 'feature semantic collisions must fail closed explicitly');
featureCheck(str_contains($mapping, "'lpimp:featmap:'"), 'feature semantic writes must use a shared advisory-lock namespace');
featureCheck(str_contains($mapping, 'assertSemanticIdentityFresh('), 'feature mapping save must re-read semantic identity under lock');
featureCheck(str_contains($mapping, 'GET_LOCK') && str_contains($mapping, 'RELEASE_LOCK'), 'feature semantic mapping write must be serialized');
featureCheck(str_contains($mapping, 'supplier_name') && str_contains($mapping, 'supplier_value'), 'feature semantic identity must compare persisted supplier labels');
featureCheck(str_contains($sync, 'ownershipDelete') && str_contains($sync, 'ownedValue'), 'authoritative feature sync preserves manual overrides and relinquishes ownership');
featureCheck(str_contains($sync, 'not exclusive to target shop'), 'non-exclusive product feature mutation fails closed');
featureCheck(str_contains($sync, 'assertExclusiveTargetShop($productId, $shopId)'), 'feature mutations must revalidate target-shop exclusivity');
featureCheck(str_contains($sync, '$latestActual = $this->actual($productId)'), 'feature sync must refresh live state before mutation');
featureCheck(str_contains($sync, 'state changed concurrently before synchronization'), 'feature sync must fail closed on pre-mutation concurrent change');
featureCheck(str_contains($sync, "' AND id_feature_value=' . (int) \$valueId"), 'feature delete must fence the exact previously observed value');
featureCheck(str_contains($sync, '$db->Affected_Rows() !== 1'), 'feature delete must detect concurrent row replacement/removal');
featureCheck(str_contains($sync, "executeS(\n            'SELECT id_feature,id_feature_value") && str_contains($sync, "true,\n            false"), 'feature live state reads must bypass Db query cache');
featureCheck(str_contains($sync, 'target_shop_count'), 'feature mutation ownership proof must include target-shop membership');
featureCheck(!str_contains($state, 'ON DUPLICATE KEY UPDATE'), 'feature ownership state save must not overwrite a semantic owner through broad upsert');
featureCheck(str_contains($state, 'Feature ownership conflict:'), 'feature ownership state save must fail closed when the semantic owner points at another product');
featureCheck(str_contains($state, 'last_seen_run_id<=') && str_contains($state, 'Refusing stale feature ownership state save'), 'feature ownership state writes must be monotonic by run generation');
featureCheck(str_contains($state, 'private function exactOwner(') && str_contains($state, '), false);'), 'feature ownership verification must use fresh uncached reads');
featureCheck(str_contains($state, 'id_product=%d AND id_feature=%d'), 'feature ownership persistence must fence the exact product and feature');
featureCheck(str_contains($state, ". ' AND id_feature_value=' . \$valueId"), 'feature ownership delete must fence the exact previously owned value');
featureCheck(str_contains($state, '$db->Affected_Rows() !== 1'), 'feature ownership delete must detect concurrent state replacement/removal');
featureCheck(str_contains($sync, '$ownershipDelete[$featureId] = $ownedValue'), 'feature synchronizer must carry the observed owned value into exact state deletion');
featureCheck(str_contains($sync, '$productId,') && str_contains($sync, '(int) $ownedValue'), 'feature synchronizer must pass exact product/value identity to ownership deletion');

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
