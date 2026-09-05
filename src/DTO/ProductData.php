<?php
namespace Lp\MatterhornImport\DTO;

final class ProductData
{
    private ?string $jsonCache = null;
    /** @var array<string,string> */
    private array $hashCache = [];

    public function __construct(
        public readonly string $sourceKey,
        public readonly string $reference,
        public readonly array $name,
        public readonly float $price,
        public readonly int $quantity,
        public readonly bool $active = true,
        public readonly array $images = [],
        public readonly array $extra = [],
    ) {}

    public function payload(): array
    {
        return [
            'sourceKey' => $this->sourceKey,
            'reference' => $this->reference,
            'name' => $this->name,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'active' => $this->active,
            'extra' => $this->extra,
        ];
    }

    public function payloadHash(): string
    {
        return $this->hashCache['payload'] ??= $this->hashValue($this->payload());
    }

    public function coreHash(): string
    {
        if (isset($this->hashCache['core'])) { return $this->hashCache['core']; }
        $extra = $this->extra;
        unset(
            $extra['attributes'],
            $extra['features'],
            $extra['features_authoritative'],
            $extra['features_auto_create'],
            $extra['categories'],
            $extra['combinations'],
            $extra['combinations_authoritative'],
            $extra['combination_attributes_auto_create'],
            $extra['specific_prices'],
            $extra['specific_prices_authoritative'],
            $extra['specific_prices_adopt_existing'],
            $extra['supplier_warnings']
        );
        return $this->hashCache['core'] = $this->hashValue([
            'reference' => $this->reference,
            'name' => $this->name,
            'active' => $this->active,
            'extra' => $extra,
        ]);
    }

    public function priceHash(): string { return $this->hashCache['price'] ??= $this->hashValue(['price' => $this->price]); }
    public function stockHash(): string { return $this->hashCache['stock'] ??= $this->hashValue(['quantity' => $this->quantity]); }
    public function attributeHash(): string { return $this->hashCache['attribute'] ??= $this->hashValue($this->extra['attributes'] ?? []); }

    public function featureHash(): string
    {
        if (isset($this->hashCache['feature'])) { return $this->hashCache['feature']; }
        if (!array_key_exists('features', $this->extra) && empty($this->extra['features_authoritative']) && empty($this->extra['features_auto_create'])) {
            return $this->hashCache['feature'] = $this->hashValue([]);
        }
        return $this->hashCache['feature'] = $this->hashValue([
            'authoritative' => !empty($this->extra['features_authoritative']),
            'auto_create' => !empty($this->extra['features_auto_create']),
            'rows' => $this->featureProjection(),
        ]);
    }

    public function categoryHash(): string { return $this->hashCache['category'] ??= $this->hashValue($this->extra['categories'] ?? []); }

    public function combinationHash(): string
    {
        return $this->hashCache['combination'] ??= $this->hashValue([
            'authoritative' => !empty($this->extra['combinations_authoritative']),
            'attributes_auto_create' => !empty($this->extra['combination_attributes_auto_create']),
            'rows' => $this->combinationProjection(false),
        ]);
    }

    public function combinationStockHash(): string
    {
        return $this->hashCache['combination_stock'] ??= $this->hashValue($this->combinationProjection(true));
    }

    public function specificPriceHash(): string
    {
        if (isset($this->hashCache['specific_price'])) { return $this->hashCache['specific_price']; }
        if (!array_key_exists('specific_prices', $this->extra) && empty($this->extra['specific_prices_authoritative']) && empty($this->extra['specific_prices_adopt_existing'])) {
            return $this->hashCache['specific_price'] = $this->hashValue([]);
        }
        return $this->hashCache['specific_price'] = $this->hashValue([
            'authoritative' => !empty($this->extra['specific_prices_authoritative']),
            'adopt_existing' => !empty($this->extra['specific_prices_adopt_existing']),
            'rows' => $this->specificPriceProjection(),
        ]);
    }

    public function imageHash(): string { return $this->hashCache['image'] ??= $this->hashValue(array_values($this->images)); }

    public function domainHashes(): array
    {
        return [
            'core' => $this->coreHash(), 'price' => $this->priceHash(), 'stock' => $this->stockHash(),
            'attribute' => $this->attributeHash(), 'feature' => $this->featureHash(), 'category' => $this->categoryHash(),
            'combination' => $this->combinationHash(), 'combination_stock' => $this->combinationStockHash(),
            'specific_price' => $this->specificPriceHash(), 'image' => $this->imageHash(),
        ];
    }

    public function toJson(): string
    {
        return $this->jsonCache ??= json_encode([
            'sourceKey' => $this->sourceKey, 'reference' => $this->reference, 'name' => $this->name,
            'price' => $this->price, 'quantity' => $this->quantity, 'active' => $this->active,
            'images' => $this->images, 'extra' => $this->extra,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function fromJson(string $json): self
    {
        $v = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $product = new self((string) $v['sourceKey'], (string) $v['reference'], (array) $v['name'], (float) $v['price'],
            (int) $v['quantity'], (bool) $v['active'], (array) ($v['images'] ?? []), (array) ($v['extra'] ?? []));
        $product->jsonCache = $json;
        return $product;
    }

    private function featureProjection(): array
    {
        $rows = $this->extra['features'] ?? [];
        if (!is_array($rows)) { return ['__invalid__' => get_debug_type($rows)]; }
        $projected = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) { $projected[] = ['invalid' => $index, 'type' => get_debug_type($row)]; continue; }
            $projected[] = [
                'key' => trim((string) ($row['key'] ?? '')),
                'value_key' => trim((string) ($row['value_key'] ?? '')),
                'name' => trim((string) ($row['name'] ?? '')),
                'value' => trim((string) ($row['value'] ?? '')),
            ];
        }
        usort($projected, static fn(array $a, array $b): int => [(string) ($a['key'] ?? ''), (string) ($a['value_key'] ?? '')] <=> [(string) ($b['key'] ?? ''), (string) ($b['value_key'] ?? '')]);
        return $projected;
    }

    private function combinationProjection(bool $stockOnly): array
    {
        $rows = $this->extra['combinations'] ?? [];
        if (!is_array($rows)) { return ['__invalid__' => get_debug_type($rows)]; }
        $projected = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) { $projected[] = ['invalid' => $index, 'type' => get_debug_type($row)]; continue; }
            $semantic = implode('|', $this->combinationAttributeTokens($row['attribute_ids'] ?? $row['attributes'] ?? []));
            if ($stockOnly) { $projected[] = ['semantic' => $semantic, 'quantity' => (int) ($row['quantity'] ?? 0)]; continue; }
            $projected[] = [
                'semantic' => $semantic,
                'reference' => (string) ($row['reference'] ?? ''),
                'price_impact' => (float) ($row['price_impact'] ?? 0.0),
                'weight_impact' => (float) ($row['weight_impact'] ?? 0.0),
                'wholesale_price' => (float) ($row['wholesale_price'] ?? 0.0),
                'minimal_quantity' => (int) ($row['minimal_quantity'] ?? 1),
                'ean13' => (string) ($row['ean13'] ?? ''),
                'upc' => (string) ($row['upc'] ?? ''),
                'mpn' => (string) ($row['mpn'] ?? ''),
                'default' => !empty($row['default']),
            ];
        }
        usort($projected, static fn(array $a, array $b): int => strcmp((string) ($a['semantic'] ?? ''), (string) ($b['semantic'] ?? '')));
        return $projected;
    }

    private function combinationAttributeTokens(mixed $attributes): array
    {
        if (!is_array($attributes)) { return ['__invalid__:' . get_debug_type($attributes)]; }
        $tokens = [];
        foreach (array_values($attributes) as $index => $attribute) {
            if (is_int($attribute) || (is_string($attribute) && ctype_digit($attribute))) {
                $tokens[] = 'id:' . (int) $attribute;
                continue;
            }
            if (!is_array($attribute)) { $tokens[] = 'invalid:' . $index . ':' . get_debug_type($attribute); continue; }
            $groupKey = trim((string) ($attribute['group_key'] ?? ''));
            $valueKey = trim((string) ($attribute['value_key'] ?? ''));
            $tokens[] = 'supplier:' . $groupKey . ':' . $valueKey;
        }
        $tokens = array_values(array_unique($tokens));
        sort($tokens, SORT_STRING);
        return $tokens;
    }

    private function specificPriceProjection(): array
    {
        $rows = $this->extra['specific_prices'] ?? [];
        if (!is_array($rows)) { return ['__invalid__' => get_debug_type($rows)]; }
        $projected = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) { $projected[] = ['invalid' => $index, 'type' => get_debug_type($row)]; continue; }
            $projected[] = [
                'id_product_attribute' => max(0, (int) ($row['id_product_attribute'] ?? 0)),
                'id_currency' => max(0, (int) ($row['id_currency'] ?? 0)),
                'id_country' => max(0, (int) ($row['id_country'] ?? 0)),
                'id_group' => max(0, (int) ($row['id_group'] ?? 0)),
                'id_customer' => max(0, (int) ($row['id_customer'] ?? 0)),
                'from_quantity' => max(1, (int) ($row['from_quantity'] ?? 1)),
                'from' => trim((string) ($row['from'] ?? '')),
                'to' => trim((string) ($row['to'] ?? '')),
                'price' => (float) ($row['price'] ?? -1.0),
                'reduction' => (float) ($row['reduction'] ?? 0.0),
                'reduction_tax' => !empty($row['reduction_tax']),
                'reduction_type' => strtolower(trim((string) ($row['reduction_type'] ?? 'amount'))),
            ];
        }
        usort($projected, static fn(array $a, array $b): int => [$a['id_product_attribute'],$a['id_currency'],$a['id_country'],$a['id_group'],$a['id_customer'],$a['from_quantity'],$a['from'],$a['to']] <=> [$b['id_product_attribute'],$b['id_currency'],$b['id_country'],$b['id_group'],$b['id_customer'],$b['from_quantity'],$b['from'],$b['to']]);
        return $projected;
    }

    private function hashValue(mixed $value): string
    {
        return hash('xxh3', json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (array_is_list($value)) { return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value); }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) { $value[$key] = $this->canonicalize($item); }
        return $value;
    }
}
