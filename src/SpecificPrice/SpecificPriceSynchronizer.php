<?php
namespace Lp\MatterhornImport\SpecificPrice;

use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Repository\SpecificPriceStateRepository;
use Lp\MatterhornImport\Util\ShopContextManager;

final class SpecificPriceSynchronizer
{
    public function __construct(private SpecificPriceStateRepository $state, private ShopContextManager $shopContext) {}

    public function sync(int $runId, int $shopId, string $source, int $productId, ProductData $product): void
    {
        if (!array_key_exists('specific_prices', $product->extra) && empty($product->extra['specific_prices_authoritative'])) { return; }
        $this->shopContext->activate($shopId);
        $desired = $this->normalize($product->extra['specific_prices'] ?? [], $shopId, $productId);
        $owned = $this->state->allForProduct($shopId, $source, $product->sourceKey, $productId);
        $adopt = !empty($product->extra['specific_prices_adopt_existing']);
        $authoritative = !empty($product->extra['specific_prices_authoritative']);

        foreach ($desired as $semanticKey => $row) {
            $ownedRow = $owned[$semanticKey] ?? null;
            $id = $ownedRow ? (int) $ownedRow['id_specific_price'] : 0;
            if ($id <= 0 || $this->fetchLive($id, $productId, $shopId) === null) {
                if ($ownedRow !== null) { $this->state->delete($shopId, $source, $product->sourceKey, $semanticKey); }
                $matches = $this->findSemanticMatches($row, $productId, $shopId);
                if (count($matches) > 1) { throw new \RuntimeException('Multiple existing specific prices match supplier semantic scope ' . $semanticKey); }
                if ($matches !== []) {
                    if (!$adopt) { throw new \RuntimeException('Manual specific price conflicts with supplier semantic scope ' . $semanticKey); }
                    $id = (int) $matches[0];
                } else {
                    $id = $this->insert($row, $productId, $shopId);
                }
            }
            $this->update($id, $row, $productId, $shopId);
            $this->state->save($shopId, $source, $product->sourceKey, $productId, $semanticKey, $id, $this->ruleHash($row), $runId);
            unset($owned[$semanticKey]);
        }

        if (!$authoritative) { return; }
        foreach ($owned as $semanticKey => $ownedRow) {
            $id = (int) $ownedRow['id_specific_price'];
            $live = $this->fetchLive($id, $productId, $shopId);
            if ($live !== null && hash_equals((string) $ownedRow['applied_hash'], $this->ruleHash($this->normalizeLiveRule($live)))) {
                $this->deleteOwnedIfUnchanged($live, $id, $productId, $shopId);
            }
            $this->state->delete($shopId, $source, $product->sourceKey, (string) $semanticKey);
        }
    }

    private function normalize(mixed $rows, int $shopId, int $productId): array
    {
        if (!is_array($rows)) { throw new \InvalidArgumentException('specific_prices must be an array'); }
        $out = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) { throw new \InvalidArgumentException('specific_prices[' . $index . '] must be an array'); }
            $type = strtolower(trim((string) ($row['reduction_type'] ?? 'amount')));
            if (!in_array($type, ['amount','percentage'], true)) { throw new \InvalidArgumentException('Invalid specific-price reduction type'); }
            $price = (float) ($row['price'] ?? -1.0);
            $reduction = (float) ($row['reduction'] ?? 0.0);
            if ($price < -1.0 || $reduction < 0.0 || ($type === 'percentage' && $reduction > 1.0)) { throw new \InvalidArgumentException('Invalid specific-price amount'); }
            $n = [
                'id_product_attribute'=>max(0,(int)($row['id_product_attribute']??0)), 'id_currency'=>max(0,(int)($row['id_currency']??0)),
                'id_country'=>max(0,(int)($row['id_country']??0)), 'id_group'=>max(0,(int)($row['id_group']??0)), 'id_customer'=>max(0,(int)($row['id_customer']??0)),
                'from_quantity'=>max(1,(int)($row['from_quantity']??1)), 'from'=>$this->date((string)($row['from']??'')), 'to'=>$this->date((string)($row['to']??'')),
                'price'=>$price, 'reduction'=>$reduction, 'reduction_tax'=>!empty($row['reduction_tax'])?1:0, 'reduction_type'=>$type,
            ];
            if ($n['id_product_attribute'] > 0 && !$this->combinationBelongsToShopProduct($n['id_product_attribute'], $productId, $shopId)) {
                throw new \RuntimeException('Specific price references combination outside target shop/product ' . $productId . '/' . $shopId);
            }
            $key = hash('sha256', json_encode([$shopId,$n['id_product_attribute'],$n['id_currency'],$n['id_country'],$n['id_group'],$n['id_customer'],$n['from_quantity'],$n['from'],$n['to']], JSON_THROW_ON_ERROR));
            if (isset($out[$key])) { throw new \InvalidArgumentException('Duplicate semantic specific-price scope'); }
            $out[$key] = $n;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    private function combinationBelongsToShopProduct(int $attributeId, int $productId, int $shopId): bool
    {
        return (bool) \Db::getInstance()->getValue(sprintf(
            'SELECT 1 FROM `%sproduct_attribute` pa INNER JOIN `%sproduct_attribute_shop` pas ON pas.id_product_attribute=pa.id_product_attribute AND pas.id_shop=%d WHERE pa.id_product_attribute=%d AND pa.id_product=%d LIMIT 1',
            _DB_PREFIX_, _DB_PREFIX_, $shopId, $attributeId, $productId
        ), false);
    }

    private function date(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') { return '0000-00-00 00:00:00'; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value) ?: \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date) { throw new \InvalidArgumentException('Invalid specific-price date: ' . $value); }
        return $date->format('Y-m-d H:i:s');
    }

    private function insert(array $row, int $productId, int $shopId): int
    {
        if (!\Db::getInstance()->insert('specific_price', $this->dbData($row, $productId, $shopId), true)) { throw new \RuntimeException('Could not create specific price'); }
        $id = (int) \Db::getInstance()->Insert_ID();
        if ($id <= 0) { throw new \RuntimeException('Specific price insert returned no identifier'); }
        return $id;
    }

    private function update(int $id, array $row, int $productId, int $shopId): void
    {
        if (!\Db::getInstance()->update('specific_price', $this->dbData($row, $productId, $shopId), 'id_specific_price=' . $id . ' AND id_product=' . $productId . ' AND id_shop=' . $shopId)) { throw new \RuntimeException('Could not update specific price ' . $id); }
    }

    private function dbData(array $row, int $productId, int $shopId): array
    {
        return ['id_specific_price_rule'=>0,'id_cart'=>0,'id_product'=>$productId,'id_shop'=>$shopId,'id_shop_group'=>0,'id_currency'=>$row['id_currency'],'id_country'=>$row['id_country'],'id_group'=>$row['id_group'],'id_customer'=>$row['id_customer'],'id_product_attribute'=>$row['id_product_attribute'],'price'=>$row['price'],'from_quantity'=>$row['from_quantity'],'reduction'=>$row['reduction'],'reduction_tax'=>$row['reduction_tax'],'reduction_type'=>pSQL($row['reduction_type']),'from'=>pSQL($row['from']),'to'=>pSQL($row['to'])];
    }

    /** @param array<string,mixed> $live */
    private function deleteOwnedIfUnchanged(array $live, int $id, int $productId, int $shopId): void
    {
        $db = \Db::getInstance();
        $where = implode(' AND ', [
            '`id_specific_price`=' . $id,
            '`id_product`=' . $productId,
            '`id_shop`=' . $shopId,
            '`id_specific_price_rule`=' . (int) ($live['id_specific_price_rule'] ?? 0),
            '`id_cart`=' . (int) ($live['id_cart'] ?? 0),
            '`id_shop_group`=' . (int) ($live['id_shop_group'] ?? 0),
            '`id_currency`=' . (int) ($live['id_currency'] ?? 0),
            '`id_country`=' . (int) ($live['id_country'] ?? 0),
            '`id_group`=' . (int) ($live['id_group'] ?? 0),
            '`id_customer`=' . (int) ($live['id_customer'] ?? 0),
            '`id_product_attribute`=' . (int) ($live['id_product_attribute'] ?? 0),
            '`from_quantity`=' . max(1, (int) ($live['from_quantity'] ?? 1)),
            "`from`='" . pSQL((string) ($live['from'] ?? '0000-00-00 00:00:00')) . "'",
            "`to`='" . pSQL((string) ($live['to'] ?? '0000-00-00 00:00:00')) . "'",
            "`price`='" . pSQL((string) ($live['price'] ?? '-1')) . "'",
            "`reduction`='" . pSQL((string) ($live['reduction'] ?? '0')) . "'",
            '`reduction_tax`=' . (int) ($live['reduction_tax'] ?? 0),
            "`reduction_type`='" . pSQL((string) ($live['reduction_type'] ?? 'amount')) . "'",
        ]);
        if (!$db->delete('specific_price', $where)) {
            throw new \RuntimeException('Could not remove owned specific price ' . $id);
        }
        if ((int) $db->Affected_Rows() > 1) {
            throw new \RuntimeException('Unexpected specific-price delete count for ' . $id);
        }
    }

    private function fetchLive(int $id, int $productId, int $shopId): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'specific_price` WHERE id_specific_price=' . $id . ' AND id_product=' . $productId . ' AND id_shop=' . $shopId,
            false
        );
        return is_array($row) && $row !== [] ? $row : null;
    }

    private function findSemanticMatches(array $desired, int $productId, int $shopId): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'specific_price` WHERE id_product=' . $productId . ' AND id_shop=' . $shopId . ' AND id_specific_price_rule=0 AND id_cart=0',
            true,
            false
        ) ?: [];
        $matches = [];
        foreach ($rows as $row) { if ($this->sameIdentity($desired, $this->normalizeLiveRule($row))) { $matches[] = (int) $row['id_specific_price']; } }
        return $matches;
    }

    private function normalizeLiveRule(array $row): array
    {
        return ['id_product_attribute'=>(int)($row['id_product_attribute']??0),'id_currency'=>(int)($row['id_currency']??0),'id_country'=>(int)($row['id_country']??0),'id_group'=>(int)($row['id_group']??0),'id_customer'=>(int)($row['id_customer']??0),'from_quantity'=>max(1,(int)($row['from_quantity']??1)),'from'=>(string)($row['from']??'0000-00-00 00:00:00'),'to'=>(string)($row['to']??'0000-00-00 00:00:00'),'price'=>(float)($row['price']??-1.0),'reduction'=>(float)($row['reduction']??0.0),'reduction_tax'=>(int)($row['reduction_tax']??0),'reduction_type'=>(string)($row['reduction_type']??'amount')];
    }

    private function sameIdentity(array $a, array $b): bool
    {
        foreach (['id_product_attribute','id_currency','id_country','id_group','id_customer','from_quantity','from','to'] as $key) { if ((string)$a[$key] !== (string)$b[$key]) { return false; } }
        return true;
    }

    private function ruleHash(array $row): string
    {
        ksort($row, SORT_STRING);
        return hash('xxh3', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}