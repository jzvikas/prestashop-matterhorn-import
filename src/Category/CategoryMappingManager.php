<?php
namespace Lp\MatterhornImport\Category;

use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Repository\CategoryMappingRepository;

final class CategoryMappingManager
{
    public function __construct(
        private CategoryMappingRepository $mapping,
        private CategoryAutoMapper $autoMapper
    ) {
    }

    /** @return array{mapped:int,skipped_disabled:int} */
    public function autoMapExisting(int $shopId): array
    {
        $mapped = 0;
        $skippedDisabled = 0;
        foreach ($this->mapping->findUnmapped($shopId) as $row) {
            if ((int) ($row['active'] ?? 0) !== 1) {
                ++$skippedDisabled;
                continue;
            }
            $before = $this->mapping->findOne($shopId, (string) $row['supplier_key']);
            $this->autoMapper->prepare($this->productFor($row, false), $shopId);
            $after = $this->mapping->findOne($shopId, (string) $row['supplier_key']);
            if (empty($before['id_category']) && !empty($after['id_category'])) { ++$mapped; }
        }
        return ['mapped' => $mapped, 'skipped_disabled' => $skippedDisabled];
    }

    /** @return array{mapped:int,skipped_disabled:int} */
    public function createAndMapMissing(int $shopId): array
    {
        $mapped = 0;
        $skippedDisabled = 0;
        foreach ($this->mapping->findUnmapped($shopId) as $row) {
            if ((int) ($row['active'] ?? 0) !== 1) {
                ++$skippedDisabled;
                continue;
            }
            $before = $this->mapping->findOne($shopId, (string) $row['supplier_key']);
            $this->autoMapper->prepare($this->productFor($row, true), $shopId);
            $after = $this->mapping->findOne($shopId, (string) $row['supplier_key']);
            if (empty($before['id_category']) && !empty($after['id_category'])) { ++$mapped; }
        }
        return ['mapped' => $mapped, 'skipped_disabled' => $skippedDisabled];
    }

    /** @param array<string,mixed> $row */
    private function productFor(array $row, bool $autoCreate): ProductData
    {
        $key = trim((string) ($row['supplier_key'] ?? ''));
        $name = trim((string) ($row['supplier_name'] ?? ''));
        $path = trim((string) ($row['supplier_path'] ?? ''));
        if ($key === '') { throw new \RuntimeException('Supplier category key is missing'); }
        if ($name === '') { $name = $key; }
        if ($path === '') { $path = $name; }

        return new ProductData(
            'category-admin',
            'category-admin',
            ['default' => 'Category mapping'],
            0.0,
            0,
            true,
            [],
            ['categories' => [[
                'key' => $key,
                'name' => $name,
                'path' => $path,
                'auto_create' => $autoCreate,
            ]]]
        );
    }
}
