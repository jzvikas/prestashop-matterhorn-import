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
attrCheck(str_contains($sql, 'PREFIX_li_matterhornim_99dfbf_attribute_group_mapping'), 'schema uses generated Matterhorn table token');
attrCheck(!str_contains($mapping, 'lp_import_attribute_'), 'generic skeleton table token must not leak into standalone module');
attrCheck(!str_contains($sql, 'PREFIX_lp_import_attribute_'), 'generic install table token must not leak into standalone module');

$resolver = (string) file_get_contents(dirname(__DIR__) . '/src/Combination/CombinationAttributeResolver.php');
attrCheck(str_contains($resolver, "unset(\$row['attributes'])"), 'persistence resolver replaces supplier descriptors with numeric attribute_ids');
attrCheck(str_contains($resolver, "\$row['attribute_ids'] = \$attributeIds"), 'numeric attribute ids are persisted only after resolution');

echo "Attribute resolution contract checks: OK\n";
