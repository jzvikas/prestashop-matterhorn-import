<?php
namespace Lp\MatterhornImport\Config;

final class MatterhornPolicy
{
    private const PRESTASHOP_GENERIC_TEXT_PATTERN = '/^[^<>{}]*$/u';

    /** @var array<int,array{source_language_id:int,category_auto_create:bool,feature_auto_create:bool,size_attribute_group_name:string}> */
    private array $cache = [];

    /** @return array{source_language_id:int,category_auto_create:bool,feature_auto_create:bool,size_attribute_group_name:string} */
    public function current(): array
    {
        if (!class_exists('Context', false) || !class_exists('Configuration', false) || !class_exists('Shop', false)) {
            return $this->defaults();
        }
        try {
            $shop = \Context::getContext()->shop ?? null;
            if (!$shop instanceof \Shop || (int) ($shop->id ?? 0) <= 0) {
                return $this->defaults();
            }
            return $this->snapshot((int) $shop->id);
        } catch (\Throwable) {
            return $this->defaults();
        }
    }

    /** @return array{source_language_id:int,category_auto_create:bool,feature_auto_create:bool,size_attribute_group_name:string} */
    public function snapshot(int $shopId, bool $refresh = false): array
    {
        if ($shopId <= 0) { throw new \InvalidArgumentException('Matterhorn policy requires a positive shop ID'); }
        if (!$refresh && isset($this->cache[$shopId])) { return $this->cache[$shopId]; }
        if (!class_exists('Configuration') || !class_exists('Language')) {
            throw new \RuntimeException('Matterhorn policy requires PrestaShop runtime');
        }

        $groupId = $this->shopGroupId($shopId);
        $languageId = (int) \Configuration::get('MATTERHORNIMPORT_SOURCE_LANGUAGE_ID', null, $groupId, $shopId);
        if ($languageId <= 0 || !$this->languageBelongsToShop($languageId, $shopId)) {
            $languageId = (int) \Configuration::get('PS_LANG_DEFAULT', null, $groupId, $shopId);
        }
        if ($languageId <= 0) { $languageId = (int) \Configuration::get('PS_LANG_DEFAULT'); }
        if ($languageId <= 0 || !$this->languageBelongsToShop($languageId, $shopId)) {
            throw new \RuntimeException('Cannot resolve Matterhorn source language for shop #' . $shopId);
        }

        $sizeGroup = trim((string) \Configuration::get('MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME', null, $groupId, $shopId));
        if ($sizeGroup === '') { $sizeGroup = 'Size'; }
        if (strlen($sizeGroup) > 64) { throw new \RuntimeException('Matterhorn Size attribute group name exceeds 64-byte limit'); }
        if (preg_match(self::PRESTASHOP_GENERIC_TEXT_PATTERN, $sizeGroup) !== 1) {
            throw new \RuntimeException(
                'Matterhorn Size attribute group name contains characters rejected by PrestaShop (<, >, {, })'
            );
        }

        return $this->cache[$shopId] = [
            'source_language_id' => $languageId,
            'category_auto_create' => $this->boolConfig('MATTERHORNIMPORT_CATEGORY_AUTO_CREATE', $groupId, $shopId, true),
            'feature_auto_create' => $this->boolConfig('MATTERHORNIMPORT_FEATURE_AUTO_CREATE', $groupId, $shopId, true),
            'size_attribute_group_name' => $sizeGroup,
        ];
    }

    /** @param array<string,mixed> $policy */
    public function hash(array $policy): string
    {
        ksort($policy, SORT_STRING);
        return hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function shopGroupId(int $shopId): int
    {
        $context = \Context::getContext();
        $shop = $context->shop ?? null;
        if ($shop instanceof \Shop && (int) $shop->id === $shopId && (int) $shop->id_shop_group > 0) {
            return (int) $shop->id_shop_group;
        }
        $value = \Db::getInstance()->getValue('SELECT id_shop_group FROM `' . _DB_PREFIX_ . 'shop` WHERE id_shop=' . $shopId);
        if ($value === false || (int) $value <= 0) { throw new \RuntimeException('Cannot resolve shop group for Matterhorn policy: ' . $shopId); }
        return (int) $value;
    }

    private function languageBelongsToShop(int $languageId, int $shopId): bool
    {
        foreach (\Language::getLanguages(false, $shopId) as $language) {
            if ((int) ($language['id_lang'] ?? 0) === $languageId) { return true; }
        }
        return false;
    }

    private function boolConfig(string $key, int $groupId, int $shopId, bool $default): bool
    {
        $raw = \Configuration::get($key, null, $groupId, $shopId);
        if ($raw === false || $raw === null || $raw === '') { return $default; }
        return (int) $raw !== 0;
    }

    /** @return array{source_language_id:int,category_auto_create:bool,feature_auto_create:bool,size_attribute_group_name:string} */
    private function defaults(): array
    {
        return [
            'source_language_id' => 0,
            'category_auto_create' => true,
            'feature_auto_create' => true,
            'size_attribute_group_name' => 'Size',
        ];
    }
}
