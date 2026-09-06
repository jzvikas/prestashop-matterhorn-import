<?php
declare(strict_types=1);

namespace Lp\MatterhornImport\Contract {
    interface SourceInterface { public function rows(): iterable; }
}

namespace Lp\MatterhornImport\Matterhorn {
    final class MatterhornCategoryPathNormalizer {
        public function key(string $id): string { return 'matterhorn-category:' . trim($id); }
        public function normalize(string $path): string {
            return implode(' > ', array_values(array_filter(array_map(
                static fn(string $part): string => trim($part),
                preg_split('#/+#u', trim($path)) ?: []
            ))));
        }
    }
}

namespace Lp\MatterhornImport\Repository {
    final class CategoryMappingRepository {
        public array $saved = [];
        public function upsertMetadata(
            int $shopId,
            string $supplierKey,
            string $supplierName,
            ?string $supplierParentKey,
            ?string $supplierPath,
            bool $active = true
        ): void {
            $this->saved[$supplierKey] = [$supplierName, $supplierPath, $active];
        }
    }
}

namespace HotfixTest {
    use Lp\MatterhornImport\Contract\SourceInterface;
    use Lp\MatterhornImport\Matterhorn\MatterhornCategoryPathNormalizer;
    use Lp\MatterhornImport\Repository\CategoryMappingRepository;

    require dirname(__DIR__) . '/src/Category/CategoryCatalogSynchronizer.php';

    final class TruncatedSource implements SourceInterface {
        public function rows(): iterable {
            yield [
                'category' => ['id' => '42', 'name' => 'Lingerie'],
                'category_path' => '/Women/Lingerie',
            ];
            yield [
                'category' => ['id' => '42', 'name' => 'Lingerie'],
                'category_path' => '/Women/Lingerie',
            ];
            yield [
                'category' => ['id' => '99', 'name' => 'Shoes'],
                'category_path' => '/Women/Shoes',
            ];
            throw new \RuntimeException(
                'Unexpected EOF inside Matterhorn <product> at source record 21967'
            );
        }
    }

    final class BrokenSource implements SourceInterface {
        public function rows(): iterable {
            yield [
                'category' => ['id' => '42', 'name' => 'Lingerie'],
                'category_path' => '/Women/Lingerie',
            ];
            throw new \RuntimeException('Matterhorn XML parse error: invalid entity');
        }
    }

    $repo = new CategoryMappingRepository();
    $sync = new \Lp\MatterhornImport\Category\CategoryCatalogSynchronizer(
        new TruncatedSource(),
        new MatterhornCategoryPathNormalizer(),
        $repo
    );
    $result = $sync->synchronize(1);

    if ($result['categories'] !== 2) {
        throw new \RuntimeException('Expected two categories from complete product nodes');
    }
    if ($result['partial_source'] !== true) {
        throw new \RuntimeException('Trailing EOF was not marked as partial source');
    }
    if (!isset($repo->saved['matterhorn-category:42'], $repo->saved['matterhorn-category:99'])) {
        throw new \RuntimeException('Complete categories before EOF were not persisted');
    }

    $strictRepo = new CategoryMappingRepository();
    $strict = new \Lp\MatterhornImport\Category\CategoryCatalogSynchronizer(
        new BrokenSource(),
        new MatterhornCategoryPathNormalizer(),
        $strictRepo
    );
    $thrown = false;
    try {
        $strict->synchronize(1);
    } catch (\RuntimeException $e) {
        $thrown = str_contains($e->getMessage(), 'parse error');
    }
    if (!$thrown) {
        throw new \RuntimeException('Non-EOF XML errors must remain strict');
    }

    echo "Laravel-style category trailing EOF tolerance: OK\n";
}
