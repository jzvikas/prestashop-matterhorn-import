<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Lp\MatterhornImport\Matterhorn\MatterhornSizeResolver;

function attrCheck(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$size = (new MatterhornSizeResolver())->attribute(' S/M ');
attrCheck($size['group_key'] === 'matterhorn:size', 'stable Size group supplier key');
attrCheck($size['value_key'] === 'matterhorn:size:s/m', 'stable Size value supplier key');
attrCheck($size['group_name'] === 'Size' && $size['value'] === 'S/M', 'Size display values');

$sizeSource = (string) file_get_contents(dirname(__DIR__) . '/src/Matterhorn/MatterhornSizeResolver.php');
attrCheck(!str_contains($sizeSource, 'Db::') && !str_contains($sizeSource, 'Context::') && !str_contains($sizeSource, 'Configuration::'), 'READ-time Size resolver must stay DB/context free');

$mapping = (string) file_get_contents(dirname(__DIR__) . '/src/Repository/AttributeMappingRepository.php');
$sql = (string) file_get_contents(dirname(__DIR__) . '/sql/attribute-mapping.sql');
attrCheck(str_contains($mapping, 'li_matterhornim_99dfbf_attribute_value_mapping'), 'runtime uses generated Matterhorn attribute mapping token');
attrCheck(str_contains($mapping, 'private array $pairCache'), 'attribute mapping lookups must use process-local cache');
attrCheck(str_contains($mapping, 'array_key_exists($cacheKey, $this->pairCache)'), 'cached misses and hits must both be reused');
attrCheck(str_contains($mapping, "$this->pairCache[$this->cacheKey"), 'newly saved mappings must seed process-local cache');
attrCheck(str_contains($mapping, '), false);'), 'attribute mapping live resolution must bypass Db query cache');
attrCheck(str_contains($sql, 'PREFIX_li_matterhornim_99dfbf_attribute_group_mapping'), 'schema uses generated Matterhorn table token');
attrCheck(!str_contains($mapping, 'lp_import_attribute_'), 'generic skeleton table token must not leak into standalone module');
attrCheck(!str_contains($sql, 'PREFIX_lp_import_attribute_'), 'generic install table token must not leak into standalone module');

$resolver = (string) file_get_contents(dirname(__DIR__) . '/src/Combination/CombinationAttributeResolver.php');
attrCheck(str_contains($resolver, "unset(\$row['attributes'])"), 'persistence resolver replaces supplier descriptors with numeric attribute_ids');
attrCheck(str_contains($resolver, "\$row['attribute_ids'] = \$attributeIds"), 'numeric attribute ids are persisted only after resolution');
attrCheck(str_contains($resolver, 'private array $availabilityCache'), 'shop attribute availability must be cached per process');
attrCheck(str_contains($resolver, 'availabilityKey($shopId, $attributeId)'), 'availability cache must be shop-scoped');

$autoCreate = (string) file_get_contents(dirname(__DIR__) . '/src/Attribute/AttributeResolver.php');
attrCheck(str_contains($autoCreate, 'LOCK_TIMEOUT_SECONDS'), 'attribute auto-create must have bounded advisory-lock wait');
attrCheck(str_contains($autoCreate, "GET_LOCK('"), 'attribute auto-create must serialize duplicate creation');
attrCheck(str_contains($autoCreate, "'lpimp:attr:'"), 'attribute auto-create must use shared cross-import advisory lock namespace');
attrCheck(!str_contains($autoCreate, "'matterhorn:attr:'"), 'supplier-specific attribute advisory locks would not serialize cross-module creation');
attrCheck(str_contains($autoCreate, "'group:' . $shopId"), 'attribute group lock must be shop/name scoped');
attrCheck(str_contains($autoCreate, "'value:' . $shopId . ':' . $groupId"), 'attribute value lock must be shop/group/name scoped');
attrCheck(substr_count($autoCreate, '$this->findGroup(') >= 1 && substr_count($autoCreate, '$this->findAttribute(') >= 1, 'resolver must recheck state while holding creation locks');
attrCheck(substr_count($autoCreate, '), true, false)') >= 2, 'attribute exact group/value reads must bypass Db query cache');
attrCheck(str_contains($autoCreate, 'RELEASE_LOCK'), 'attribute auto-create locks must always be releasable');

echo "Attribute resolution contract checks: OK\n";