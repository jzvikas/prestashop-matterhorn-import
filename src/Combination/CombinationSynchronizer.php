<?php
namespace Lp\MatterhornImport\Combination;

use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Repository\CombinationMappingRepository;
use Lp\MatterhornImport\Util\ShopContextManager;

final class CombinationSynchronizer
{
    public function __construct(
        private ShopContextManager $shopContext,
        private CombinationNormalizer $normalizer,
        private CombinationMappingRepository $mapping
    ) {}

    public function sync(int $runId, int $shopId, string $source, int $productId, ProductData $product): void
    {
        if (!array_key_exists('combinations', $product->extra)) {
            return;
        }
        $desired = $this->normalizer->normalize($product);
        $this->shopContext->activate($shopId);
        $mapped = $this->mapping->allForProduct($shopId, $source, $product->sourceKey, $productId);
        $actual = $this->actualBySemantic($productId, $shopId);
        $survivors = [];
        $desiredKeys = [];
        $explicitDefault = null;

        foreach ($desired as $item) {
            $semanticKey = $item['semantic_key'];
            $desiredKeys[$semanticKey] = true;
            if ($item['default']) {
                if ($explicitDefault !== null) {
                    throw new \RuntimeException('More than one supplier combination is marked default for ' . $product->sourceKey);
                }
                $explicitDefault = $semanticKey;
            }

            $candidates = $actual[$semanticKey] ?? [];
            $mappedRow = $mapped[$semanticKey] ?? null;
            $mappedId = (int) ($mappedRow['id_product_attribute'] ?? 0);
            $survivor = $this->chooseCandidate($candidates, $mappedId, $item);
            $created = false;
            if ($survivor === null) {
                $id = $this->createCombination($productId, $shopId, $item);
                $survivor = [
                    'id_product_attribute' => $id,
                    'shop_count' => 1,
                    'reference' => $item['reference'],
                    'ean13' => $item['ean13'],
                    'upc' => $item['upc'],
                    'mpn' => $item['mpn'],
                    'price' => $item['price_impact'],
                    'weight' => $item['weight_impact'],
                    'wholesale_price' => $item['wholesale_price'],
                    'minimal_quantity' => $item['minimal_quantity'],
                ];
                $created = true;
            }

            $id = (int) $survivor['id_product_attribute'];
            $mappingMatches = $mappedRow !== null && (int) $mappedRow['id_product_attribute'] === $id;
            $structureChanged = !$created && (
                !$mappingMatches || !hash_equals((string) ($mappedRow['structure_hash'] ?? ''), $item['structure_hash']) || !$this->structureMatches($survivor, $item)
            );
            if ($structureChanged) {
                if ((int) ($survivor['shop_count'] ?? 1) > 1) {
                    if (!$this->globalStructureMatches($survivor, $item)) {
                        throw new \RuntimeException('Refusing to mutate global fields of shared combination ' . $id);
                    }
                    $this->updateSharedShopFields($id, $shopId, $item);
                } else {
                    $this->updateExclusiveCombination($productId, $id, $shopId, $item);
                }
            }

            if (!$created && (!$mappingMatches || !hash_equals((string) ($mappedRow['stock_hash'] ?? ''), $item['stock_hash']))) {
                \StockAvailable::setQuantity($productId, $id, $item['quantity'], $shopId);
            }

            $this->mapping->save(
                $shopId, $source, $product->sourceKey, $semanticKey, $productId, $id,
                $item['structure_hash'], $item['stock_hash'], $runId
            );
            $survivors[$semanticKey] = $id;

            foreach ($candidates as $candidate) {
                $candidateId = (int) $candidate['id_product_attribute'];
                if ($candidateId === $id) {
                    continue;
                }
                $this->removeFromTargetShop($productId, $candidateId, $shopId);
                $this->mapping->deleteByAttribute($shopId, $candidateId);
            }
        }

        if (!empty($product->extra['combinations_authoritative'])) {
            foreach ($mapped as $semanticKey => $row) {
                if (isset($desiredKeys[$semanticKey])) {
                    continue;
                }
                $id = (int) ($row['id_product_attribute'] ?? 0);
                if ($id > 0 && $this->belongsToProductShop($productId, $id, $shopId)) {
                    $this->removeFromTargetShop($productId, $id, $shopId);
                }
                $this->mapping->deleteSemantic($shopId, $source, $product->sourceKey, $semanticKey);
            }
        }

        $this->healDefault($productId, $shopId, $survivors, $explicitDefault);
    }

    private function actualBySemantic(int $productId, int $shopId): array
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT pa.id_product_attribute,pa.reference,pa.ean13,pa.upc,pa.mpn," .
            "pas.default_on,pas.price,pas.weight,pas.wholesale_price,pas.minimal_quantity," .
            "(SELECT COUNT(*) FROM `%sproduct_attribute_shop` x WHERE x.id_product_attribute=pa.id_product_attribute) AS shop_count," .
            "GROUP_CONCAT(pac.id_attribute ORDER BY pac.id_attribute ASC SEPARATOR ',') AS attribute_ids " .
            "FROM `%sproduct_attribute` pa " .
            "INNER JOIN `%sproduct_attribute_shop` pas ON pas.id_product_attribute=pa.id_product_attribute AND pas.id_shop=%d " .
            "INNER JOIN `%sproduct_attribute_combination` pac ON pac.id_product_attribute=pa.id_product_attribute " .
            "WHERE pa.id_product=%d GROUP BY pa.id_product_attribute,pa.reference,pa.ean13,pa.upc,pa.mpn," .
            "pas.default_on,pas.price,pas.weight,pas.wholesale_price,pas.minimal_quantity",
            _DB_PREFIX_, _DB_PREFIX_, _DB_PREFIX_, $shopId, _DB_PREFIX_, $productId
        ), true, false) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $row['attribute_ids'])), static fn(int $id): bool => $id > 0));
            if ($ids === []) {
                continue;
            }
            $semantic = $this->normalizer->semanticKey($ids);
            $out[$semantic][] = [
                'id_product_attribute' => (int) $row['id_product_attribute'],
                'default_on' => (int) ($row['default_on'] ?? 0),
                'reference' => (string) ($row['reference'] ?? ''),
                'ean13' => (string) ($row['ean13'] ?? ''),
                'upc' => (string) ($row['upc'] ?? ''),
                'mpn' => (string) ($row['mpn'] ?? ''),
                'price' => (float) ($row['price'] ?? 0),
                'weight' => (float) ($row['weight'] ?? 0),
                'wholesale_price' => (float) ($row['wholesale_price'] ?? 0),
                'minimal_quantity' => (int) ($row['minimal_quantity'] ?? 1),
                'shop_count' => (int) ($row['shop_count'] ?? 1),
            ];
        }
        return $out;
    }

    private function chooseCandidate(array $candidates, int $mappedId, array $item): ?array
    {
        if ($mappedId > 0) {
            foreach ($candidates as $candidate) {
                if ((int) $candidate['id_product_attribute'] === $mappedId && $this->candidateReusable($candidate, $item)) {
                    return $candidate;
                }
            }
        }
        usort($candidates, static fn(array $a, array $b): int => (int) $a['id_product_attribute'] <=> (int) $b['id_product_attribute']);
        foreach ($candidates as $candidate) {
            if ($this->candidateReusable($candidate, $item)) {
                return $candidate;
            }
        }
        return null;
    }

    private function candidateReusable(array $candidate, array $item): bool
    {
        return (int) ($candidate['shop_count'] ?? 1) <= 1 || $this->globalStructureMatches($candidate, $item);
    }

    private function globalStructureMatches(array $actual, array $item): bool
    {
        foreach (['reference', 'ean13', 'upc', 'mpn'] as $field) {
            if ((string) ($actual[$field] ?? '') !== (string) $item[$field]) {
                return false;
            }
        }
        return true;
    }

    private function structureMatches(array $actual, array $item): bool
    {
        return $this->globalStructureMatches($actual, $item)
            && $this->sameFloat((float) ($actual['price'] ?? 0), (float) $item['price_impact'])
            && $this->sameFloat((float) ($actual['weight'] ?? 0), (float) $item['weight_impact'])
            && $this->sameFloat((float) ($actual['wholesale_price'] ?? 0), (float) $item['wholesale_price'])
            && (int) ($actual['minimal_quantity'] ?? 1) === (int) $item['minimal_quantity'];
    }

    private function sameFloat(float $a, float $b): bool
    {
        return abs($a - $b) < 0.000001;
    }

    private function createCombination(int $productId, int $shopId, array $item): int
    {
        $combination = new \Combination();
        $combination->id_product = $productId;
        $combination->id_shop_list = [$shopId];
        $this->applyStructure($combination, $item);
        $combination->default_on = null;
        if (!$combination->add()) {
            throw new \RuntimeException('PrestaShop combination create failed for product ' . $productId);
        }
        if (!$combination->setAttributes($item['attribute_ids'])) {
            throw new \RuntimeException('PrestaShop combination attribute assignment failed for product ' . $productId);
        }
        \StockAvailable::setQuantity($productId, (int) $combination->id, $item['quantity'], $shopId);
        return (int) $combination->id;
    }

    private function updateExclusiveCombination(int $productId, int $id, int $shopId, array $item): void
    {
        if ($this->shopAssociationCount($id) > 1) {
            throw new \RuntimeException('Refusing ObjectModel update of shared combination ' . $id);
        }
        $combination = new \Combination($id, null, $shopId);
        if (!\Validate::isLoadedObject($combination) || (int) $combination->id_product !== $productId) {
            throw new \RuntimeException('Combination not found or belongs to another product: ' . $id);
        }
        $this->applyStructure($combination, $item);
        if (!$combination->update()) {
            throw new \RuntimeException('PrestaShop combination update failed: ' . $id);
        }
    }

    private function updateSharedShopFields(int $id, int $shopId, array $item): void
    {
        if (!\Db::getInstance()->update('product_attribute_shop', [
            'price' => (float) $item['price_impact'],
            'weight' => (float) $item['weight_impact'],
            'wholesale_price' => (float) $item['wholesale_price'],
            'minimal_quantity' => (int) $item['minimal_quantity'],
        ], 'id_product_attribute=' . $id . ' AND id_shop=' . $shopId)) {
            throw new \RuntimeException('Could not update target-shop fields for shared combination ' . $id);
        }
    }

    private function applyStructure(\Combination $combination, array $item): void
    {
        $combination->reference = $item['reference'];
        $combination->price = $item['price_impact'];
        $combination->weight = $item['weight_impact'];
        $combination->wholesale_price = $item['wholesale_price'];
        $combination->minimal_quantity = $item['minimal_quantity'];
        $combination->ean13 = $item['ean13'];
        $combination->upc = $item['upc'];
        $combination->mpn = $item['mpn'];
    }

    private function removeFromTargetShop(int $productId, int $id, int $shopId): void
    {
        $count = $this->shopAssociationCount($id);
        if ($count <= 1) {
            $this->deleteExclusiveCombination($productId, $id, $shopId);
            return;
        }

        $db = \Db::getInstance();
        $detached = $db->execute(sprintf(
            "DELETE target FROM `%sproduct_attribute_shop` target " .
            "INNER JOIN `%sproduct_attribute_shop` other " .
            "ON other.id_product_attribute=target.id_product_attribute AND other.id_shop<>target.id_shop " .
            "INNER JOIN `%sproduct_attribute` pa " .
            "ON pa.id_product_attribute=target.id_product_attribute AND pa.id_product=%d " .
            "WHERE target.id_product_attribute=%d AND target.id_shop=%d",
            _DB_PREFIX_, _DB_PREFIX_, _DB_PREFIX_, $productId, $id, $shopId
        ));
        if (!$detached) {
            throw new \RuntimeException('Could not detach shared combination ' . $id . ' from shop ' . $shopId);
        }

        $affected = (int) $db->Affected_Rows();
        if ($affected === 0) {
            if ($this->belongsToProductShop($productId, $id, $shopId)) {
                $this->deleteExclusiveCombination($productId, $id, $shopId);
            }
            return;
        }
        if ($affected !== 1) {
            throw new \RuntimeException('Unexpected shared combination detach count for ' . $id);
        }

        if (!\StockAvailable::removeProductFromStockAvailable($productId, $id, $shopId)) {
            throw new \RuntimeException('Could not detach shared combination stock ' . $id . ' from shop ' . $shopId);
        }
        if (!$db->delete('cart_product', 'id_product_attribute=' . $id . ' AND id_shop=' . $shopId)) {
            throw new \RuntimeException('Could not clean target-shop cart rows for combination ' . $id);
        }
    }

    private function deleteExclusiveCombination(int $productId, int $id, int $shopId): void
    {
        $db = \Db::getInstance();
        $row = $db->getRow(sprintf(
            "SELECT pa.id_product,COUNT(pas.id_shop) AS shop_count," .
            "SUM(CASE WHEN pas.id_shop=%d THEN 1 ELSE 0 END) AS target_shop_count " .
            "FROM `%sproduct_attribute` pa " .
            "LEFT JOIN `%sproduct_attribute_shop` pas ON pas.id_product_attribute=pa.id_product_attribute " .
            "WHERE pa.id_product_attribute=%d GROUP BY pa.id_product",
            $shopId, _DB_PREFIX_, _DB_PREFIX_, $id
        ), false);
        if (!is_array($row) || $row === []) {
            return;
        }
        if ((int) ($row['id_product'] ?? 0) !== $productId) {
            throw new \RuntimeException('Refusing to delete combination from another product: ' . $id);
        }
        $shopCount = (int) ($row['shop_count'] ?? 0);
        $targetShopCount = (int) ($row['target_shop_count'] ?? 0);
        if ($targetShopCount === 0) {
            return;
        }
        if ($shopCount !== 1 || $targetShopCount !== 1) {
            throw new \RuntimeException('Refusing global delete of shared or ambiguously associated combination ' . $id);
        }

        $combination = new \Combination($id, null, $shopId);
        if (!\Validate::isLoadedObject($combination)) {
            return;
        }
        if ((int) $combination->id_product !== $productId) {
            throw new \RuntimeException('Refusing to delete combination from another product: ' . $id);
        }
        if (!$combination->delete()) {
            throw new \RuntimeException('Could not delete exclusive combination ' . $id);
        }
    }

    private function shopAssociationCount(int $id): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute_shop` WHERE id_product_attribute=' . $id,
            false
        );
    }

    private function belongsToProductShop(int $productId, int $id, int $shopId): bool
    {
        return (bool) \Db::getInstance()->getValue(sprintf(
            "SELECT 1 FROM `%sproduct_attribute` pa INNER JOIN `%sproduct_attribute_shop` pas " .
            "ON pas.id_product_attribute=pa.id_product_attribute AND pas.id_shop=%d WHERE pa.id_product=%d AND pa.id_product_attribute=%d",
            _DB_PREFIX_, _DB_PREFIX_, $shopId, $productId, $id
        ), false);
    }

    private function healDefault(int $productId, int $shopId, array $survivors, ?string $explicitDefault): void
    {
        if ($survivors === []) {
            return;
        }
        $defaultId = $explicitDefault !== null ? (int) ($survivors[$explicitDefault] ?? 0) : 0;
        if ($defaultId <= 0) {
            $ids = array_values(array_map('intval', $survivors));
            sort($ids, SORT_NUMERIC);
            $defaultId = $ids[0] ?? 0;
        }
        if ($defaultId <= 0) {
            return;
        }
        $db = \Db::getInstance();
        $ids = array_values(array_unique(array_map('intval', $survivors)));
        if ($ids === []) {
            return;
        }
        $idList = implode(',', $ids);
        $externalDefault = (int) $db->getValue(sprintf(
            'SELECT pa.id_product_attribute FROM `%sproduct_attribute` pa INNER JOIN `%sproduct_attribute_shop` pas ON pas.id_product_attribute=pa.id_product_attribute AND pas.id_shop=%d WHERE pa.id_product=%d AND pas.default_on=1 AND pa.id_product_attribute NOT IN (%s) ORDER BY pa.id_product_attribute LIMIT 1',
            _DB_PREFIX_, _DB_PREFIX_, $shopId, $productId, $idList
        ), false);
        if ($externalDefault > 0) {
            throw new \RuntimeException('Refusing to override default combination owned outside Matterhorn: ' . $externalDefault);
        }
        if (!$db->update('product_attribute_shop', ['default_on' => null], 'id_shop=' . $shopId . ' AND id_product_attribute IN (' . $idList . ')')) {
            throw new \RuntimeException('Could not clear target-shop default combination');
        }
        if (!$db->update('product_attribute_shop', ['default_on' => 1], 'id_shop=' . $shopId . ' AND id_product_attribute=' . $defaultId)) {
            throw new \RuntimeException('Could not set target-shop default combination ' . $defaultId);
        }
    }
}