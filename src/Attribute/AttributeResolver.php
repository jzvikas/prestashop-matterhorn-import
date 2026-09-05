<?php
namespace Lp\MatterhornImport\Attribute;

use Lp\MatterhornImport\Util\ShopContextManager;

final class AttributeResolver
{
    public function __construct(private ShopContextManager $shopContext) {}

    /** @return array{id_attribute_group:int,id_attribute:int} */
    public function resolveOrCreate(int $shopId, string $groupName, string $valueName): array
    {
        $groupName = trim($groupName);
        $valueName = trim($valueName);
        if ($shopId <= 0 || $groupName === '' || $valueName === '') {
            throw new \InvalidArgumentException('Attribute auto-create requires shop, group name and value');
        }
        if (strlen($groupName) > 64) { throw new \InvalidArgumentException('Attribute group public name exceeds PrestaShop 64-byte limit'); }
        if (strlen($valueName) > 128) { throw new \InvalidArgumentException('Attribute value exceeds PrestaShop 128-byte limit'); }

        $this->shopContext->activate($shopId);
        $langId = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $shopId);
        if ($langId <= 0) { throw new \RuntimeException('Target shop has no valid default language for attribute resolution'); }

        $groupId = $this->findGroup($shopId, $langId, $groupName);
        if ($groupId <= 0) { $groupId = $this->createGroup($shopId, $groupName); }
        $attributeId = $this->findAttribute($shopId, $langId, $groupId, $valueName);
        if ($attributeId <= 0) { $attributeId = $this->createAttribute($shopId, $groupId, $valueName); }
        return ['id_attribute_group' => $groupId, 'id_attribute' => $attributeId];
    }

    private function findGroup(int $shopId, int $langId, string $name): int
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT ag.id_attribute_group FROM `%sattribute_group` ag " .
            "INNER JOIN `%sattribute_group_shop` ags ON ags.id_attribute_group=ag.id_attribute_group AND ags.id_shop=%d " .
            "INNER JOIN `%sattribute_group_lang` agl ON agl.id_attribute_group=ag.id_attribute_group AND agl.id_lang=%d " .
            "WHERE BINARY agl.name=BINARY '%s' ORDER BY ag.id_attribute_group LIMIT 2",
            _DB_PREFIX_, _DB_PREFIX_, $shopId, _DB_PREFIX_, $langId, pSQL($name)
        )) ?: [];
        if (count($rows) > 1) { throw new \RuntimeException('Ambiguous exact attribute group name in target shop: ' . $name); }
        return (int) ($rows[0]['id_attribute_group'] ?? 0);
    }

    private function findAttribute(int $shopId, int $langId, int $groupId, string $value): int
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT a.id_attribute FROM `%sattribute` a " .
            "INNER JOIN `%sattribute_shop` ash ON ash.id_attribute=a.id_attribute AND ash.id_shop=%d " .
            "INNER JOIN `%sattribute_lang` al ON al.id_attribute=a.id_attribute AND al.id_lang=%d " .
            "WHERE a.id_attribute_group=%d AND BINARY al.name=BINARY '%s' ORDER BY a.id_attribute LIMIT 2",
            _DB_PREFIX_, _DB_PREFIX_, $shopId, _DB_PREFIX_, $langId, $groupId, pSQL($value)
        )) ?: [];
        if (count($rows) > 1) { throw new \RuntimeException('Ambiguous exact attribute value for group ' . $groupId . ': ' . $value); }
        return (int) ($rows[0]['id_attribute'] ?? 0);
    }

    private function createGroup(int $shopId, string $name): int
    {
        $db = \Db::getInstance();
        $position = (int) $db->getValue('SELECT COALESCE(MAX(position),-1)+1 FROM `' . _DB_PREFIX_ . 'attribute_group`');
        if (!$db->insert('attribute_group', ['is_color_group' => 0, 'group_type' => 'select', 'position' => max(0, $position)])) {
            throw new \RuntimeException('Could not create attribute group: ' . $name);
        }
        $groupId = (int) $db->Insert_ID();
        if ($groupId <= 0) { throw new \RuntimeException('Created attribute group has invalid ID: ' . $name); }
        foreach (\Language::getLanguages(false, $shopId) as $lang) {
            if (!$db->insert('attribute_group_lang', [
                'id_attribute_group' => $groupId, 'id_lang' => (int) $lang['id_lang'], 'name' => $name, 'public_name' => $name,
            ])) { throw new \RuntimeException('Could not create attribute group translation: ' . $name); }
        }
        if (!$db->insert('attribute_group_shop', ['id_attribute_group' => $groupId, 'id_shop' => $shopId])) {
            throw new \RuntimeException('Could not associate attribute group to shop: ' . $name);
        }
        return $groupId;
    }

    private function createAttribute(int $shopId, int $groupId, string $value): int
    {
        $db = \Db::getInstance();
        $position = (int) $db->getValue('SELECT COALESCE(MAX(position),-1)+1 FROM `' . _DB_PREFIX_ . 'attribute` WHERE id_attribute_group=' . $groupId);
        if (!$db->insert('attribute', ['id_attribute_group' => $groupId, 'color' => '', 'position' => max(0, $position)])) {
            throw new \RuntimeException('Could not create attribute value: ' . $value);
        }
        $attributeId = (int) $db->Insert_ID();
        if ($attributeId <= 0) { throw new \RuntimeException('Created attribute value has invalid ID: ' . $value); }
        foreach (\Language::getLanguages(false, $shopId) as $lang) {
            if (!$db->insert('attribute_lang', ['id_attribute' => $attributeId, 'id_lang' => (int) $lang['id_lang'], 'name' => $value])) {
                throw new \RuntimeException('Could not create attribute translation: ' . $value);
            }
        }
        if (!$db->insert('attribute_shop', ['id_attribute' => $attributeId, 'id_shop' => $shopId])) {
            throw new \RuntimeException('Could not associate attribute to shop: ' . $value);
        }
        return $attributeId;
    }
}
