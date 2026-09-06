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

    /** @return array{scanned:int,categories:int,conflicts:int,partial_source:bool} */
    public function synchronize(int $shopId): array
    {
        if ($shopId <= 0) {
            throw new \InvalidArgumentException('Category synchronization requires a concrete shop');
        }

        $scanned = 0;
        $partialSource = false;

        /**
         * Matterhorn may emit the same supplier category id with more than one
         * name/path variant. Supplier category id is the identity; descriptive
         * metadata must not abort category synchronization.
         *
         * @var array<string,array<string,array{name:string,path:string,count:int}>>
         */
        $variants = [];

        try {
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

                $fingerprint = hash('sha256', $name . "\0" . $path);

                if (!isset($variants[$key][$fingerprint])) {
                    $variants[$key][$fingerprint] = [
                        'name' => $name,
                        'path' => $path,
                        'count' => 0,
                    ];
                }
                ++$variants[$key][$fingerprint]['count'];
            }
        } catch (\RuntimeException $e) {
            /*
             * The working Laravel importer uses a unique-node streamer: complete
             * <product> fragments are consumed and an unfinished trailing product
             * is effectively ignored. Mirror that behaviour ONLY for category
             * discovery. Core READ/import remains strict, because accepting a
             * partial feed there could incorrectly mark products as removed.
             */
            if ($variants === [] || !$this->isTrailingMatterhornEof($e)) {
                throw $e;
            }
            $partialSource = true;
        }

        if ($variants === []) {
            throw new \RuntimeException('No Matterhorn supplier categories were found');
        }

        $conflicts = 0;
        foreach ($variants as $key => $keyVariants) {
            if (count($keyVariants) > 1) {
                ++$conflicts;
            }

            $metadata = $this->canonicalVariant(array_values($keyVariants));
            $this->mapping->upsertMetadata(
                $shopId,
                $key,
                $metadata['name'],
                null,
                $metadata['path'],
                true
            );
        }

        return [
            'scanned' => $scanned,
            'categories' => count($variants),
            'conflicts' => $conflicts,
            'partial_source' => $partialSource,
        ];
    }

    private function isTrailingMatterhornEof(\RuntimeException $e): bool
    {
        $message = $e->getMessage();

        return str_starts_with($message, 'Unexpected EOF inside Matterhorn <')
            && str_contains($message, ' at source record ');
    }

    /**
     * Prefer the most frequently emitted metadata for a supplier category.
     * Ties are resolved deterministically so XML row ordering cannot change
     * the chosen mapping metadata.
     *
     * @param list<array{name:string,path:string,count:int}> $variants
     * @return array{name:string,path:string,count:int}
     */
    private function canonicalVariant(array $variants): array
    {
        if ($variants === []) {
            throw new \LogicException('Category metadata variant list cannot be empty');
        }

        usort($variants, static function (array $left, array $right): int {
            $byCount = $right['count'] <=> $left['count'];
            if ($byCount !== 0) { return $byCount; }

            $byPath = strcmp($left['path'], $right['path']);
            if ($byPath !== 0) { return $byPath; }

            return strcmp($left['name'], $right['name']);
        });

        return $variants[0];
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
