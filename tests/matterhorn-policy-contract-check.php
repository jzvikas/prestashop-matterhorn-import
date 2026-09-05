<?php
declare(strict_types=1);

final class Shop
{
    public int $id;
    public int $id_shop_group;
    public function __construct(int $id, int $group = 1) { $this->id = $id; $this->id_shop_group = $group; }
}

final class Context
{
    public Shop $shop;
    private static ?self $instance = null;
    public static function getContext(): self { return self::$instance ??= new self(); }
}

final class Configuration
{
    /** @var array<string,string> */
    public static array $values = [];
    public static function get(string $key, mixed $unused = null, ?int $groupId = null, ?int $shopId = null): string|false
    {
        $scoped = $key . ':' . (int) $groupId . ':' . (int) $shopId;
        if (array_key_exists($scoped, self::$values)) { return self::$values[$scoped]; }
        return self::$values[$key . ':0:0'] ?? false;
    }
}

final class Language
{
    public static function getLanguages(bool $active = false, ?int $shopId = null): array
    {
        return match ((int) $shopId) {
            1 => [['id_lang' => 1]],
            2 => [['id_lang' => 2]],
            default => [],
        };
    }
}

require_once dirname(__DIR__) . '/autoload.php';

use Lp\MatterhornImport\Config\MatterhornPolicy;
use Lp\MatterhornImport\Mapper\MatterhornProductMapper;
use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
use Lp\MatterhornImport\Matterhorn\MatterhornHtmlSanitizer;
use Lp\MatterhornImport\Matterhorn\MatterhornSizeResolver;

function policyCheck(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

Configuration::$values = [
    'PS_LANG_DEFAULT:1:1' => '1',
    'PS_LANG_DEFAULT:1:2' => '2',
    'MATTERHORNIMPORT_SOURCE_LANGUAGE_ID:1:1' => '1',
    'MATTERHORNIMPORT_SOURCE_LANGUAGE_ID:1:2' => '2',
    'MATTERHORNIMPORT_CATEGORY_AUTO_CREATE:1:1' => '1',
    'MATTERHORNIMPORT_CATEGORY_AUTO_CREATE:1:2' => '0',
    'MATTERHORNIMPORT_FEATURE_AUTO_CREATE:1:1' => '0',
    'MATTERHORNIMPORT_FEATURE_AUTO_CREATE:1:2' => '1',
    'MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME:1:1' => 'EU Size',
    'MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME:1:2' => 'US Size',
];

$policy = new MatterhornPolicy();
Context::getContext()->shop = new Shop(1);
$shop1 = $policy->snapshot(1);
policyCheck($shop1['source_language_id'] === 1, 'shop 1 source language');
policyCheck($shop1['category_auto_create'] === true, 'shop 1 category policy');
policyCheck($shop1['feature_auto_create'] === false, 'shop 1 feature policy');
policyCheck($shop1['size_attribute_group_name'] === 'EU Size', 'shop 1 Size group');

Configuration::$values['MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME:1:1'] = 'Changed Mid Run';
policyCheck($policy->snapshot(1)['size_attribute_group_name'] === 'EU Size', 'policy snapshot must stay cached during one process');
policyCheck($policy->snapshot(1, true)['size_attribute_group_name'] === 'Changed Mid Run', 'explicit policy refresh must re-read configuration');

Context::getContext()->shop = new Shop(2);
$shop2 = $policy->snapshot(2);
policyCheck($shop2['source_language_id'] === 2, 'shop 2 source language');
policyCheck($shop2['category_auto_create'] === false, 'shop 2 category policy');
policyCheck($shop2['feature_auto_create'] === true, 'shop 2 feature policy');
policyCheck($shop2['size_attribute_group_name'] === 'US Size', 'shop 2 Size group');
policyCheck($policy->hash($shop1) !== $policy->hash($shop2), 'different shop semantic policies must hash differently');

$resolver = new MatterhornSizeResolver($policy);
$attribute = $resolver->attribute('S/M');
policyCheck(($attribute['group_name'] ?? '') === 'US Size', 'Size resolver must honor current shop policy');
policyCheck(($attribute['group_key'] ?? '') === 'matterhorn:size', 'Size group semantic identity must stay stable');

$row = [
    'id' => '99', 'name' => 'Policy Product', 'price' => '10', 'brand' => 'Brand',
    'category' => ['id' => '7', 'name' => 'Category'], 'category_path' => '/Root/Category',
    'color' => 'blue', 'type' => 'Test', 'images' => [], 'description' => '<strong>Safe</strong>',
    'options' => [['id' => 'OPT-1', 'name' => 'S/M', 'stock' => '3', 'ean' => '']],
];
$mapper = new MatterhornProductMapper($resolver, new MatterhornCategoryPathNormalizer(), new MatterhornHtmlSanitizer(), $policy);
$product = $mapper->map($row);
policyCheck(($product->extra['source_language_id'] ?? 0) === 2, 'mapper must persist source language policy into snapshot payload');
policyCheck(($product->extra['categories'][0]['auto_create'] ?? true) === false, 'mapper must honor category auto-create policy');
policyCheck(($product->extra['features_auto_create'] ?? false) === true, 'mapper must honor feature auto-create policy');
policyCheck(($product->extra['combinations'][0]['attributes'][0]['group_name'] ?? '') === 'US Size', 'mapper Size descriptor must use shop policy');

echo "Matterhorn shop-scoped policy contract: OK\n";
