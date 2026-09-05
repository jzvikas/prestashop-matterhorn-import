<?php
namespace Lp\MatterhornImport\Repository;

final class CategoryMappingRepository
{
    private const PRELOAD_CHUNK = 500;
    /** @var array<int,array<string,array<string,mixed>|null>> */
    private array $cache = [];

    /** @param list<string> $supplierKeys @return array<string,int> */
    public function resolveActiveCategoryIds(array $supplierKeys, int $shopId): array
    {
        $supplierKeys = array_values(array_unique(array_filter(
            array_map(static fn(mixed $key): string => trim((string) $key), $supplierKeys),
            static fn(string $key): bool => $key !== ''
        )));
        if ($supplierKeys === []) { return []; }
        $this->preload($supplierKeys, $shopId);
        $resolved = [];
        foreach ($supplierKeys as $key) {
            $row = $this->cache[$shopId][$key] ?? null;
            if (is_array($row) && (int) ($row['active'] ?? 0) === 1 && (int) ($row['id_category'] ?? 0) > 0) {
                $resolved[$key] = (int) $row['id_category'];
            }
        }
        return $resolved;
    }

    /** @param list<string> $supplierKeys */
    public function preload(array $supplierKeys, int $shopId): void
    {
        if ($shopId <= 0) { throw new \InvalidArgumentException('Category mapping requires a concrete shop'); }
        $pending = [];
        foreach ($supplierKeys as $key) {
            $key = trim((string) $key);
            if ($key !== '' && !array_key_exists($key, $this->cache[$shopId] ?? [])) { $pending[$key] = $key; }
        }
        if ($pending === []) { return; }

        foreach (array_chunk(array_values($pending), self::PRELOAD_CHUNK) as $chunk) {
            $quoted = array_map(static fn(string $key): string => "'" . pSQL($key) . "'", $chunk);
            $rows = \Db::getInstance()->executeS(sprintf(
                "SELECT supplier_key,id_category,active FROM `%sli_matterhornim_99dfbf_category_mapping` WHERE id_shop=%d AND supplier_key IN (%s)",
                _DB_PREFIX_, $shopId, implode(',', $quoted)
            )) ?: [];
            foreach ($chunk as $key) { $this->cache[$shopId][$key] = null; }
            foreach ($rows as $row) {
                $key = (string) ($row['supplier_key'] ?? '');
                if ($key !== '') { $this->cache[$shopId][$key] = $row; }
            }
        }
    }

    public function upsertMetadata(int $shopId, string $supplierKey, string $supplierName, ?string $supplierParentKey, ?string $supplierPath, bool $active = true): void
    {
        $supplierKey = trim($supplierKey);
        if ($shopId <= 0 || $supplierKey === '') { throw new \InvalidArgumentException('Category mapping requires shop and supplier key'); }
        $sql = sprintf(
            "INSERT INTO `%sli_matterhornim_99dfbf_category_mapping` (`id_shop`,`supplier_key`,`supplier_parent_key`,`supplier_name`,`supplier_path`,`id_category`,`active`,`updated_at`) VALUES (%d,'%s',%s,'%s',%s,NULL,%d,'%s') ON DUPLICATE KEY UPDATE `supplier_parent_key`=VALUES(`supplier_parent_key`),`supplier_name`=VALUES(`supplier_name`),`supplier_path`=VALUES(`supplier_path`),`active`=VALUES(`active`),`updated_at`=VALUES(`updated_at`)",
            _DB_PREFIX_, $shopId, pSQL($supplierKey),
            $supplierParentKey === null ? 'NULL' : "'" . pSQL($supplierParentKey) . "'",
            pSQL($supplierName), $supplierPath === null ? 'NULL' : "'" . pSQL($supplierPath, true) . "'",
            $active ? 1 : 0, date('Y-m-d H:i:s')
        );
        if (!\Db::getInstance()->execute($sql)) { throw new \RuntimeException('Category mapping metadata upsert failed'); }
        unset($this->cache[$shopId][$supplierKey]);
    }

    public function assign(int $shopId, string $supplierKey, int $categoryId, bool $active = true): void
    {
        if ($shopId <= 0 || trim($supplierKey) === '' || $categoryId <= 0) {
            throw new \InvalidArgumentException('Category assignment requires shop, supplier key and category');
        }
        if (!\Db::getInstance()->update(
            'li_matterhornim_99dfbf_category_mapping',
            ['id_category' => $categoryId, 'active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')],
            sprintf("id_shop=%d AND supplier_key='%s'", $shopId, pSQL(trim($supplierKey)))
        )) { throw new \RuntimeException('Category mapping assignment failed'); }
        unset($this->cache[$shopId][trim($supplierKey)]);
    }
}
