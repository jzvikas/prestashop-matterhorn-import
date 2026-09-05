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
featureCheck(str_contains($mapping, 'li_matterhornim_99dfbf_feature_mapping'), 'feature mapping uses standalone DB token');
featureCheck(str_contains($state, 'li_matterhornim_99dfbf_feature_state'), 'feature state uses standalone DB token');
featureCheck(!str_contains($mapping . $state, 'lp_import_'), 'generic skeleton DB token does not leak into feature runtime');
featureCheck(str_contains($sync, 'ownershipDelete') && str_contains($sync, 'ownedValue'), 'authoritative feature sync preserves manual overrides and relinquishes ownership');
featureCheck(str_contains($sync, 'product shared across multiple shops'), 'shared product feature mutation fails closed');

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
