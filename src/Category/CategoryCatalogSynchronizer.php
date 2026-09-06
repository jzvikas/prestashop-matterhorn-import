<?php
namespace Lp\MatterhornImport\Category;

use Lp\MatterhornImport\Contract\SourceInterface;
use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
use Lp\MatterhornImport\Repository\CategoryMappingRepository;

final class CategoryCatalogSynchronizer
{
    public function __construct(
        private SourceInterface $source,
        private MatterhornCategoryPathNormalizer $normalizer,
        private CategoryMappingRepository $mapping
    ) {
    }

    /** @return array{scanned:int,categories:int} */
    public function synchronize(int $shopId): array
    {
        if ($shopId <= 0) {
            throw new \InvalidArgumentException('Category synchronization requires a concrete shop');
        }

        $scanned = 0;
        $categories = [];
        foreach ($this->source->rows() as $row) {
            ++$scanned;
            if (!is_array($row)) { continue; }

            $category = is_array($row['category'] ?? null) ? $row['category'] : [];
            $supplierId = trim((string) ($category['id'] ?? ''));
            if ($supplierId === '') { continue; }

            $key = $this->normalizer->key($supplierId);
            $name = trim((string) ($category['name'] ?? ''));
            $path = $this->normalizer->normalize((string) ($row['category_path'] ?? ''));
            if ($name === '') {
                $parts = $this->pathParts($path);
                $name = $parts !== [] ? $parts[array_key_last($parts)] : $supplierId;
            }
            if ($path === '') { $path = $name; }

            $metadata = ['name' => $name, 'path' => $path];
            if (isset($categories[$key]) && $categories[$key] !== $metadata) {
                throw new \RuntimeException('Conflicting Matterhorn category metadata for supplier key ' . $key);
            }
            $categories[$key] = $metadata;
        }

        foreach ($categories as $key => $metadata) {
            $this->mapping->upsertMetadata(
                $shopId,
                $key,
                $metadata['name'],
                null,
                $metadata['path'],
                true
            );
        }

        return ['scanned' => $scanned, 'categories' => count($categories)];
    }

    /** @return list<string> */
    private function pathParts(string $path): array
    {
        return array_values(array_filter(array_map(
            static fn(string $part): string => trim($part),
            preg_split('/\s*>\s*/u', $path) ?: []
        ), static fn(string $part): bool => $part !== ''));
    }
}
