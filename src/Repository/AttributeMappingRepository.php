<?php
namespace Lp\MatterhornImport\Repository;

final class AttributeMappingRepository
{
    /** @var array<string,array{id_attribute_group:int,id_attribute:int}|null> */
    private array $pairCache = [];

    /** @return array{id_attribute_group:int,id_attribute:int}|null */
    public function resolvePair(int $shopId, string $source, string $groupKey, string $valueKey): ?array
    {
        $cacheKey = $this->cacheKey($shopId, $source, $groupKey, $valueKey);
        if (array_key_exists($cacheKey, $this->pairCache)) {
            return $this->pairCache[$cacheKey];
        }

        $row = \Db::getInstance()->getRow(sprintf(
            "SELECT id_attribute_group,id_attribute FROM `%sli_matterhornim_99dfbf_attribute_value_mapping` " .
            "WHERE id_shop=%d AND source='%s' AND supplier_group_key='%s' AND supplier_value_key='%s'",
            _DB_PREFIX_, $shopId, pSQL($source), pSQL($groupKey), pSQL($valueKey)
        ));
        if (!is_array($row)) { return $this->pairCache[$cacheKey] = null; }
        $groupId = (int) ($row['id_attribute_group'] ?? 0);
        $attributeId = (int) ($row['id_attribute'] ?? 0);
        if ($groupId <= 0 || $attributeId <= 0) { return $this->pairCache[$cacheKey] = null; }
        return $this->pairCache[$cacheKey] = ['id_attribute_group' => $groupId, 'id_attribute' => $attributeId];
    }

    public function saveResolved(
        int $shopId,
        string $source,
        string $groupKey,
        string $groupName,
        string $valueKey,
        string $valueName,
        int $groupId,
        int $attributeId
    ): void {
        $now = date('Y-m-d H:i:s');
        $db = \Db::getInstance();
        $groupSql = sprintf(
            "INSERT INTO `%sli_matterhornim_99dfbf_attribute_group_mapping` " .
            "(`id_shop`,`source`,`supplier_group_key`,`supplier_name`,`id_attribute_group`,`updated_at`) " .
            "VALUES (%d,'%s','%s','%s',%d,'%s') " .
            "ON DUPLICATE KEY UPDATE supplier_name=VALUES(supplier_name),id_attribute_group=VALUES(id_attribute_group),updated_at=VALUES(updated_at)",
            _DB_PREFIX_, $shopId, pSQL($source), pSQL($groupKey), pSQL($groupName), $groupId, pSQL($now)
        );
        if (!$db->execute($groupSql)) { throw new \RuntimeException('Could not save supplier attribute group mapping'); }

        $valueSql = sprintf(
            "INSERT INTO `%sli_matterhornim_99dfbf_attribute_value_mapping` " .
            "(`id_shop`,`source`,`supplier_group_key`,`supplier_value_key`,`supplier_value`,`id_attribute_group`,`id_attribute`,`updated_at`) " .
            "VALUES (%d,'%s','%s','%s','%s',%d,%d,'%s') " .
            "ON DUPLICATE KEY UPDATE supplier_value=VALUES(supplier_value),id_attribute_group=VALUES(id_attribute_group),id_attribute=VALUES(id_attribute),updated_at=VALUES(updated_at)",
            _DB_PREFIX_, $shopId, pSQL($source), pSQL($groupKey), pSQL($valueKey), pSQL($valueName), $groupId, $attributeId, pSQL($now)
        );
        if (!$db->execute($valueSql)) { throw new \RuntimeException('Could not save supplier attribute value mapping'); }

        $this->pairCache[$this->cacheKey($shopId, $source, $groupKey, $valueKey)] = [
            'id_attribute_group' => $groupId,
            'id_attribute' => $attributeId,
        ];
    }

    private function cacheKey(int $shopId, string $source, string $groupKey, string $valueKey): string
    {
        return $shopId . "\0" . $source . "\0" . $groupKey . "\0" . $valueKey;
    }
}
