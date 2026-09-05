<?php
namespace Lp\MatterhornImport\Feature;

use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Repository\FeatureMappingRepository;
use Lp\MatterhornImport\Repository\FeatureStateRepository;
use Lp\MatterhornImport\Util\ShopContextManager;

final class FeatureSynchronizer
{
    public function __construct(
        private FeatureNormalizer $normalizer,
        private FeatureResolver $resolver,
        private FeatureMappingRepository $mapping,
        private FeatureStateRepository $state,
        private ShopContextManager $shopContext
    ) {}

    public function sync(int $runId, int $shopId, string $source, int $productId, ProductData $product): void
    {
        if (!array_key_exists('features', $product->extra)) {
            return;
        }
        $this->shopContext->activate($shopId);
        $rows = $this->normalizer->normalize($product);
        $autoCreate = !empty($product->extra['features_auto_create']);
        $authoritative = !empty($product->extra['features_authoritative']);
        $desired = [];

        foreach ($rows as $row) {
            $resolved = $this->mapping->resolvePair($shopId, $source, $row['key'], $row['value_key']);
            if ($resolved === null) {
                if (!$autoCreate) {
                    throw new \RuntimeException('Unmapped supplier feature/value: ' . $row['key'] . '/' . $row['value_key']);
                }
                if ($row['name'] === '' || $row['value'] === '') {
                    throw new \RuntimeException('Feature auto-create requires name/value labels for ' . $row['key'] . '/' . $row['value_key']);
                }
                $resolved = $this->resolver->resolveOrCreate($shopId, $row['name'], $row['value']);
                $this->mapping->saveResolved(
                    $shopId, $source, $row['key'], $row['name'], $row['value_key'], $row['value'],
                    $resolved['id_feature'], $resolved['id_feature_value']
                );
            }

            $featureId = (int) $resolved['id_feature'];
            $valueId = (int) $resolved['id_feature_value'];
            if (isset($desired[$featureId]) && $desired[$featureId] !== $valueId) {
                throw new \RuntimeException('Supplier payload resolves multiple values to one PrestaShop feature for ' . $product->sourceKey);
            }
            $desired[$featureId] = $valueId;
        }

        $actual = $this->actual($productId);
        $owned = $this->state->allForProduct($shopId, $source, $product->sourceKey, $productId);
        $final = $actual;
        $ownershipSave = [];
        $ownershipDelete = [];

        foreach ($desired as $featureId => $valueId) {
            $previous = $actual[$featureId] ?? null;
            $ownedValue = isset($owned[$featureId]) ? (int) $owned[$featureId]['id_feature_value'] : null;
            $final[$featureId] = $valueId;
            if ($ownedValue === $valueId || $previous === null || $previous !== $valueId) {
                $ownershipSave[$featureId] = $valueId;
            }
        }

        if ($authoritative) {
            foreach ($owned as $featureId => $row) {
                if (isset($desired[$featureId])) {
                    continue;
                }
                $ownedValue = (int) $row['id_feature_value'];
                if (($actual[$featureId] ?? null) === $ownedValue) {
                    unset($final[$featureId]);
                }
                $ownershipDelete[$featureId] = true;
            }
        }

        ksort($actual, SORT_NUMERIC);
        ksort($final, SORT_NUMERIC);
        $needsProductMutation = $actual !== $final;
        if ($needsProductMutation && $this->productShopCount($productId) > 1) {
            throw new \RuntimeException('Refusing feature_product mutation for product shared across multiple shops: ' . $productId);
        }

        $db = \Db::getInstance();
        if ($needsProductMutation) {
            foreach ($actual as $featureId => $valueId) {
                if (($final[$featureId] ?? null) === $valueId) {
                    continue;
                }
                if (!$db->delete('feature_product', 'id_product=' . $productId . ' AND id_feature=' . (int) $featureId)) {
                    throw new \RuntimeException('Could not remove previous product feature ' . $featureId);
                }
            }
            foreach ($final as $featureId => $valueId) {
                if (($actual[$featureId] ?? null) === $valueId) {
                    continue;
                }
                if (!$db->insert('feature_product', [
                    'id_feature' => (int) $featureId,
                    'id_product' => $productId,
                    'id_feature_value' => (int) $valueId,
                ])) {
                    throw new \RuntimeException('Could not assign product feature ' . $featureId);
                }
            }
        }

        foreach (array_keys($ownershipDelete) as $featureId) {
            $this->state->delete($shopId, $source, $product->sourceKey, (int) $featureId);
        }
        foreach ($ownershipSave as $featureId => $valueId) {
            $this->state->save($shopId, $source, $product->sourceKey, $productId, (int) $featureId, (int) $valueId, $runId);
        }
    }

    /** @return array<int,int> */
    private function actual(int $productId): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT id_feature,id_feature_value FROM `' . _DB_PREFIX_ . 'feature_product` WHERE id_product=' . $productId . ' ORDER BY id_feature'
        ) ?: [];
        $actual = [];
        foreach ($rows as $row) {
            $featureId = (int) $row['id_feature'];
            $valueId = (int) $row['id_feature_value'];
            if ($featureId > 0 && $valueId > 0) {
                if (isset($actual[$featureId]) && $actual[$featureId] !== $valueId) {
                    throw new \RuntimeException('Product has duplicate values for feature ' . $featureId);
                }
                $actual[$featureId] = $valueId;
            }
        }
        return $actual;
    }

    private function productShopCount(int $productId): int
    {
        return (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product=' . $productId);
    }
}
