<?php
namespace Lp\MatterhornImport\DTO;

final class ProductData
{
    public function __construct(
        public readonly string $sourceKey,
        public readonly string $reference,
        public readonly array $name,
        public readonly float $price,
        public readonly int $quantity,
        public readonly bool $active = true,
        public readonly array $images = [],
        public readonly array $extra = [],
    ) {
    }

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

    public function payloadHash(): string { return $this->hashValue($this->payload()); }

    public function coreHash(): string
    {
        $extra = $this->extra;
        unset(
            $extra['attributes'], $extra['features'], $extra['features_authoritative'],
            $extra['features_auto_create'], $extra['categories'], $extra['combinations'],
            $extra['combinations_authoritative'], $extra['specific_prices'],
            $extra['specific_prices_authoritative'], $extra['specific_prices_adopt_existing']
        );
        return $this->hashValue([
            'reference' => $this->reference,
            'name' => $this->name,
            'active' => $this->active,
            'extra' => $extra,
        ]);
    }

    public function priceHash(): string { return $this->hashValue(['price' => $this->price]); }
    public function stockHash(): string { return $this->hashValue(['quantity' => $this->quantity]); }
    public function attributeHash(): string { return $this->hashValue($this->extra['attributes'] ?? []); }
    public function categoryHash(): string { return $this->hashValue($this->extra['categories'] ?? []); }
    public function imageHash(): string { return $this->hashValue(array_values($this->images)); }

    public function featureHash(): string
    {
        return $this->hashValue([
            'authoritative' => !empty($this->extra['features_authoritative']),
            'auto_create' => !empty($this->extra['features_auto_create']),
            'rows' => $this->featureProjection(),
        ]);
    }

    public function combinationHash(): string
    {
        return $this->hashValue([
            'authoritative' => !empty($this->extra['combinations_authoritative']),
            'rows' => $this->combinationProjection(false),
        ]);
    }

    public function combinationStockHash(): string
    {
        return $this->hashValue($this->combinationProjection(true));
    }

    public function specificPriceHash(): string
    {
        return $this->hashValue([
            'authoritative' => !empty($this->extra['specific_prices_authoritative']),
            'adopt_existing' => !empty($this->extra['specific_prices_adopt_existing']),
            'rows' => $this->extra['specific_prices'] ?? [],
        ]);
    }

    public function domainHashes(): array
    {
        return [
            'core' => $this->coreHash(),
            'price' => $this->priceHash(),
            'stock' => $this->stockHash(),
            'attribute' => $this->attributeHash(),
            'feature' => $this->featureHash(),
            'category' => $this->categoryHash(),
            'combination' => $this->combinationHash(),
            'combination_stock' => $this->combinationStockHash(),
            'specific_price' => $this->specificPriceHash(),
            'image' => $this->imageHash(),
        ];
    }

    public function toJson(): string
    {
        return json_encode([
            'sourceKey' => $this->sourceKey,
            'reference' => $this->reference,
            'name' => $this->name,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'active' => $this->active,
            'images' => $this->images,
            'extra' => $this->extra,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function fromJson(string $json): self
    {
        $v = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return new self(
            (string) $v['sourceKey'], (string) $v['reference'], (array) $v['name'],
            (float) $v['price'], (int) $v['quantity'], (bool) $v['active'],
            (array) ($v['images'] ?? []), (array) ($v['extra'] ?? [])
        );
    }

    private function featureProjection(): array
    {
        $rows = $this->extra['features'] ?? [];
        if (!is_array($rows)) { return ['__invalid__' => get_debug_type($rows)]; }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $out[] = [
                'key' => trim((string) ($row['key'] ?? '')),
                'value_key' => trim((string) ($row['value_key'] ?? '')),
                'name' => trim((string) ($row['name'] ?? '')),
                'value' => trim((string) ($row['value'] ?? '')),
            ];
        }
        usort($out, static fn(array $a, array $b): int => [$a['key'], $a['value_key']] <=> [$b['key'], $b['value_key']]);
        return $out;
    }

    private function combinationProjection(bool $stockOnly): array
    {
        $rows = $this->extra['combinations'] ?? [];
        if (!is_array($rows)) { return ['__invalid__' => get_debug_type($rows)]; }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $ids = $row['attribute_ids'] ?? $row['attributes'] ?? [];
            $ids = is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
            sort($ids, SORT_NUMERIC);
            $semantic = implode(':', $ids);
            if ($stockOnly) {
                $out[] = ['semantic' => $semantic, 'quantity' => (int) ($row['quantity'] ?? 0)];
                continue;
            }
            $out[] = [
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
        usort($out, static fn(array $a, array $b): int => strcmp((string) $a['semantic'], (string) $b['semantic']));
        return $out;
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
