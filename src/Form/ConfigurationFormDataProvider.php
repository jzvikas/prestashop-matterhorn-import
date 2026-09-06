<?php
namespace Lp\MatterhornImport\Form;

use Lp\MatterhornImport\Config\OperationalSettings;
use Lp\MatterhornImport\Source\SourceLocation;
use PrestaShop\PrestaShop\Core\Form\FormDataProviderInterface;

final class ConfigurationFormDataProvider implements FormDataProviderInterface
{
    public const SOURCE_LOCATION = 'source_location';
    public const SOURCE_LANGUAGE_ID = 'source_language_id';
    public const FEATURE_AUTO_CREATE = 'feature_auto_create';
    public const SIZE_ATTRIBUTE_GROUP = 'size_attribute_group';
    public const MAX_REMOVE_PERCENT = 'max_remove_percent';

    public function __construct(
        private OperationalSettings $operationalSettings,
        private SourceLocation $sourceLocation
    ) {
    }

    public function getData(): array
    {
        [$shopId, $shopGroupId] = $this->shopContext();
        $languages = $this->languageIds($shopId);

        $sourceLanguageId = (int) \Configuration::get(
            'MATTERHORNIMPORT_SOURCE_LANGUAGE_ID',
            null,
            $shopGroupId,
            $shopId
        );
        if (!isset($languages[$sourceLanguageId])) {
            $sourceLanguageId = (int) \Configuration::get('PS_LANG_DEFAULT', null, $shopGroupId, $shopId);
        }
        if (!isset($languages[$sourceLanguageId])) {
            $sourceLanguageId = (int) array_key_first($languages);
        }

        $data = [
            self::SOURCE_LOCATION => (string) \Configuration::get('MATTERHORNIMPORT_SOURCE_FILE', null, $shopGroupId, $shopId),
            self::SOURCE_LANGUAGE_ID => $sourceLanguageId,
            self::FEATURE_AUTO_CREATE => $this->boolConfig('MATTERHORNIMPORT_FEATURE_AUTO_CREATE', $shopGroupId, $shopId, true),
            self::SIZE_ATTRIBUTE_GROUP => (string) \Configuration::get('MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME', null, $shopGroupId, $shopId),
            self::MAX_REMOVE_PERCENT => (int) \Configuration::get('MATTERHORNIMPORT_MAX_REMOVE_PERCENT', null, $shopGroupId, $shopId),
        ];

        if ($data[self::SOURCE_LOCATION] === '') {
            $data[self::SOURCE_LOCATION] = _PS_MODULE_DIR_ . 'matterhornimport/var/source.xml';
        }
        if ($data[self::SIZE_ATTRIBUTE_GROUP] === '') {
            $data[self::SIZE_ATTRIBUTE_GROUP] = 'Size';
        }
        if ($data[self::MAX_REMOVE_PERCENT] < 1 || $data[self::MAX_REMOVE_PERCENT] > 100) {
            $data[self::MAX_REMOVE_PERCENT] = 25;
        }

        foreach ($this->operationalSettings->values($shopId) as $key => $value) {
            $data[$key] = $value;
        }

        return $data;
    }

    public function setData(array $data): array
    {
        try {
            [$shopId, $shopGroupId] = $this->shopContext();
            $languages = $this->languageIds($shopId);

            $source = $this->sourceLocation->validate((string) ($data[self::SOURCE_LOCATION] ?? ''));
            $sourceLanguageId = filter_var(
                $data[self::SOURCE_LANGUAGE_ID] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($sourceLanguageId === false || !isset($languages[(int) $sourceLanguageId])) {
                throw new \InvalidArgumentException('Source language must belong to the selected shop.');
            }

            $featureAutoCreate = !empty($data[self::FEATURE_AUTO_CREATE]);
            $sizeGroup = trim((string) ($data[self::SIZE_ATTRIBUTE_GROUP] ?? ''));
            if ($sizeGroup === '' || strlen($sizeGroup) > 64) {
                throw new \InvalidArgumentException('Size attribute group must contain 1 to 64 bytes.');
            }

            $maxRemove = filter_var(
                $data[self::MAX_REMOVE_PERCENT] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 100]]
            );
            if ($maxRemove === false) {
                throw new \InvalidArgumentException('Maximum REMOVE percentage must be from 1 to 100.');
            }

            $operationalRaw = [];
            foreach ($this->operationalSettings->values($shopId) as $key => $_value) {
                $operationalRaw[$key] = $data[$key] ?? null;
            }
            $validatedOperational = $this->operationalSettings->validate($operationalRaw);

            $writes = [
                'MATTERHORNIMPORT_SOURCE_FILE' => $source,
                'MATTERHORNIMPORT_SOURCE_LANGUAGE_ID' => (string) $sourceLanguageId,
                'MATTERHORNIMPORT_FEATURE_AUTO_CREATE' => $featureAutoCreate ? '1' : '0',
                'MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME' => $sizeGroup,
                'MATTERHORNIMPORT_MAX_REMOVE_PERCENT' => (string) $maxRemove,
            ];

            foreach ($writes as $key => $value) {
                if (!\Configuration::updateValue($key, $value, false, $shopGroupId, $shopId)) {
                    throw new \RuntimeException('Could not persist configuration key: ' . $key);
                }
            }
            $this->operationalSettings->save($shopId, $shopGroupId, $validatedOperational);

            return [];
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }
    }

    /** @return array{0:int,1:int} */
    private function shopContext(): array
    {
        $shop = \Context::getContext()->shop;
        $shopId = (int) ($shop->id ?? 0);
        $shopGroupId = (int) ($shop->id_shop_group ?? 0);
        if ($shopId <= 0 || $shopGroupId <= 0) {
            throw new \RuntimeException('Select one concrete shop before configuring Matterhorn Import.');
        }

        return [$shopId, $shopGroupId];
    }

    /** @return array<int,true> */
    private function languageIds(int $shopId): array
    {
        $ids = [];
        foreach (\Language::getLanguages(false, $shopId) as $language) {
            $id = (int) ($language['id_lang'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            throw new \RuntimeException('Selected shop has no active languages.');
        }

        return $ids;
    }

    private function boolConfig(string $key, int $shopGroupId, int $shopId, bool $default): bool
    {
        $raw = \Configuration::get($key, null, $shopGroupId, $shopId);
        if ($raw === false || $raw === null || $raw === '') {
            return $default;
        }

        return (int) $raw !== 0;
    }
}
