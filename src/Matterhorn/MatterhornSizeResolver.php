<?php
namespace Lp\MatterhornImport\Matterhorn;

use Lp\MatterhornImport\Contract\SizeResolverInterface;

final class MatterhornSizeResolver implements SizeResolverInterface
{
    /** @var array<string,int> */
    private array $cache = [];
    private int $groupId = 0;

    public function resolve(string $size): int
    {
        $size = trim($size);
        if ($size === '') {
            throw new \InvalidArgumentException('Matterhorn size cannot be empty');
        }
        $key = $this->normalize($size);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $shopId = $this->shopId();
        $langId = $this->languageId($shopId);
        $groupId = $this->groupId($shopId, $langId);
        $db = \Db::getInstance();
        $id = (int) $db->getValue(sprintf(
            "SELECT a.id_attribute FROM `%sattribute` a " .
            "INNER JOIN `%sattribute_lang` al ON al.id_attribute=a.id_attribute AND al.id_lang=%d " .
            "INNER JOIN `%sattribute_shop` ash ON ash.id_attribute=a.id_attribute AND ash.id_shop=%d " .
            "WHERE a.id_attribute_group=%d AND LOWER(TRIM(al.name))=LOWER('%s')",
            _DB_PREFIX_, _DB_PREFIX_, _DB_PREFIX_, $langId, $shopId, $groupId, pSQL($size)
        ));
        if ($id > 0) {
            return $this->cache[$key] = $id;
        }

        $attribute = new \ProductAttribute();
        $attribute->id_attribute_group = $groupId;
        $attribute->id_shop_list = [$shopId];
        foreach (\Language::getLanguages(false, $shopId) as $language) {
            $idLang = (int) ($language['id_lang'] ?? 0);
            if ($idLang > 0) {
                $attribute->name[$idLang] = mb_substr($size, 0, 128, 'UTF-8');
            }
        }
        if (!$attribute->add() || (int) $attribute->id <= 0) {
            throw new \RuntimeException('Could not create Matterhorn Size attribute value: ' . $size);
        }
        return $this->cache[$key] = (int) $attribute->id;
    }

    private function groupId(int $shopId, int $langId): int
    {
        if ($this->groupId > 0) {
            return $this->groupId;
        }
        $name = trim((string) \Configuration::get('MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME', null, null, $shopId));
        if ($name === '') {
            $name = 'Size';
        }
        $db = \Db::getInstance();
        $id = (int) $db->getValue(sprintf(
            "SELECT ag.id_attribute_group FROM `%sattribute_group` ag " .
            "INNER JOIN `%sattribute_group_lang` agl ON agl.id_attribute_group=ag.id_attribute_group AND agl.id_lang=%d " .
            "INNER JOIN `%sattribute_group_shop` ags ON ags.id_attribute_group=ag.id_attribute_group AND ags.id_shop=%d " .
            "WHERE LOWER(TRIM(agl.name))=LOWER('%s')",
            _DB_PREFIX_, _DB_PREFIX_, _DB_PREFIX_, $langId, $shopId, pSQL($name)
        ));
        if ($id > 0) {
            return $this->groupId = $id;
        }

        $group = new \AttributeGroup();
        $group->group_type = 'select';
        $group->id_shop_list = [$shopId];
        foreach (\Language::getLanguages(false, $shopId) as $language) {
            $idLang = (int) ($language['id_lang'] ?? 0);
            if ($idLang > 0) {
                $label = mb_substr($name, 0, 128, 'UTF-8');
                $group->name[$idLang] = $label;
                $group->public_name[$idLang] = $label;
            }
        }
        if (!$group->add() || (int) $group->id <= 0) {
            throw new \RuntimeException('Could not create Matterhorn Size attribute group');
        }
        return $this->groupId = (int) $group->id;
    }

    private function shopId(): int
    {
        $shop = \Context::getContext()->shop ?? null;
        $id = $shop instanceof \Shop ? (int) $shop->id : 0;
        if ($id <= 0) {
            throw new \RuntimeException('Matterhorn Size resolver requires an explicit shop context');
        }
        return $id;
    }

    private function languageId(int $shopId): int
    {
        $id = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $shopId);
        if ($id <= 0) {
            $id = (int) \Configuration::get('PS_LANG_DEFAULT');
        }
        if ($id <= 0) {
            throw new \RuntimeException('Could not resolve target-shop language for Matterhorn Size attributes');
        }
        return $id;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }
}
