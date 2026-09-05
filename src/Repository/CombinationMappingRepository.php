<?php
namespace Lp\MatterhornImport\Repository;

final class CombinationMappingRepository
{
    private const TABLE = 'li_matterhornim_99dfbf_combination_mapping';

    /** @return array<string,array<string,mixed>> */
    public function allForProduct(int $shopId, string $source, string $sourceKey, int $productId): array
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT * FROM `%s%s` WHERE id_shop=%d AND source='%s' AND source_key='%s' AND id_product=%d",
            _DB_PREFIX_, self::TABLE, $shopId, pSQL($source), pSQL($sourceKey), $productId
        ), true, false) ?: [];
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['semantic_key']] = $row;
        }
        return $indexed;
    }

    /** @return array{source:string,source_key:string,semantic_key:string,id_product:int,id_product_attribute:int}|null */
    public function ownerForAttribute(int $shopId, int $productAttributeId): ?array
    {
        if ($shopId <= 0 || $productAttributeId <= 0) {
            throw new \InvalidArgumentException('Combination owner lookup requires shop and product attribute');
        }
        $row = \Db::getInstance()->getRow(sprintf(
            "SELECT source,source_key,semantic_key,id_product,id_product_attribute FROM `%s%s` " .
            "WHERE id_shop=%d AND id_product_attribute=%d",
            _DB_PREFIX_, self::TABLE, $shopId, $productAttributeId
        ), false);
        if (!is_array($row) || $row === []) { return null; }
        return [
            'source' => (string) ($row['source'] ?? ''),
            'source_key' => (string) ($row['source_key'] ?? ''),
            'semantic_key' => (string) ($row['semantic_key'] ?? ''),
            'id_product' => (int) ($row['id_product'] ?? 0),
            'id_product_attribute' => (int) ($row['id_product_attribute'] ?? 0),
        ];
    }

    public function save(
        int $shopId,
        string $source,
        string $sourceKey,
        string $semanticKey,
        int $productId,
        int $productAttributeId,
        string $structureHash,
        string $stockHash,
        int $runId
    ): void {
        $source = trim($source);
        $sourceKey = trim($sourceKey);
        $semanticKey = trim($semanticKey);
        if ($shopId <= 0 || $source === '' || $sourceKey === '' || $semanticKey === '' || $productId <= 0 || $productAttributeId <= 0 || $runId <= 0) {
            throw new \InvalidArgumentException('Combination mapping save requires complete owner identity');
        }

        $attributeOwner = $this->ownerForAttribute($shopId, $productAttributeId);
        if ($attributeOwner !== null && !$this->matchesOwner(
            $attributeOwner,
            $source,
            $sourceKey,
            $semanticKey,
            $productId,
            $productAttributeId
        )) {
            throw $this->attributeOwnershipConflict($shopId, $productAttributeId, $attributeOwner);
        }

        $db = \Db::getInstance();
        $where = $this->semanticOwnerWhere($shopId, $source, $sourceKey, $semanticKey);
        $data = [
            'id_product' => $productId,
            'id_product_attribute' => $productAttributeId,
            'structure_hash' => pSQL($structureHash),
            'stock_hash' => pSQL($stockHash),
            'last_seen_run_id' => $runId,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (!$db->update(self::TABLE, $data, $where, 0, true, false)) {
            $ownerAfterFailure = $this->ownerForAttribute($shopId, $productAttributeId);
            if ($ownerAfterFailure !== null && !$this->matchesOwner(
                $ownerAfterFailure,
                $source,
                $sourceKey,
                $semanticKey,
                $productId,
                $productAttributeId
            )) {
                throw $this->attributeOwnershipConflict($shopId, $productAttributeId, $ownerAfterFailure);
            }
            throw new \RuntimeException('Combination exact-owner mapping update failed: ' . $db->getMsgError());
        }

        $existing = $this->exactOwner($shopId, $source, $sourceKey, $semanticKey);
        if ($existing !== null) {
            if ((int) $existing['id_product'] !== $productId || (int) $existing['id_product_attribute'] !== $productAttributeId) {
                throw new \RuntimeException('Combination mapping ownership changed while saving semantic key ' . $semanticKey);
            }
            return;
        }

        $insert = [
            'id_shop' => $shopId,
            'source' => pSQL($source),
            'source_key' => pSQL($sourceKey),
            'semantic_key' => pSQL($semanticKey),
        ] + $data;
        if ($db->insert(self::TABLE, $insert, false, true, \Db::INSERT)) {
            return;
        }

        $afterRace = $this->exactOwner($shopId, $source, $sourceKey, $semanticKey);
        if ($afterRace !== null) {
            if ((int) $afterRace['id_product'] !== $productId || (int) $afterRace['id_product_attribute'] !== $productAttributeId) {
                throw new \RuntimeException('Combination semantic owner changed during concurrent save: ' . $semanticKey);
            }
            if (!$db->update(self::TABLE, $data, $where, 0, true, false)) {
                throw new \RuntimeException('Combination concurrent mapping refresh failed: ' . $db->getMsgError());
            }
            return;
        }

        $attributeOwner = $this->ownerForAttribute($shopId, $productAttributeId);
        if ($attributeOwner !== null) {
            throw $this->attributeOwnershipConflict($shopId, $productAttributeId, $attributeOwner);
        }

        throw new \RuntimeException('Combination mapping insert failed: ' . $db->getMsgError());
    }

    public function deleteExact(
        int $shopId,
        string $source,
        string $sourceKey,
        string $semanticKey,
        int $productId,
        int $productAttributeId
    ): void {
        if ($shopId <= 0 || $source === '' || $sourceKey === '' || $semanticKey === '' || $productId <= 0 || $productAttributeId <= 0) {
            throw new \InvalidArgumentException('Exact combination mapping delete requires complete owner identity');
        }
        $db = \Db::getInstance();
        if (!$db->delete(
            self::TABLE,
            sprintf(
                "id_shop=%d AND source='%s' AND source_key='%s' AND semantic_key='%s' AND id_product=%d AND id_product_attribute=%d",
                $shopId, pSQL($source), pSQL($sourceKey), pSQL($semanticKey), $productId, $productAttributeId
            )
        )) {
            throw new \RuntimeException('Combination mapping exact delete failed');
        }
        if ((int) $db->Affected_Rows() !== 1) {
            throw new \RuntimeException('Combination mapping ownership changed before exact delete');
        }
    }

    /** @return array{id_product:int,id_product_attribute:int}|null */
    private function exactOwner(int $shopId, string $source, string $sourceKey, string $semanticKey): ?array
    {
        $row = \Db::getInstance()->getRow(sprintf(
            "SELECT id_product,id_product_attribute FROM `%s%s` WHERE %s",
            _DB_PREFIX_, self::TABLE, $this->semanticOwnerWhere($shopId, $source, $sourceKey, $semanticKey)
        ), false);
        if (!is_array($row) || $row === []) { return null; }
        return [
            'id_product' => (int) ($row['id_product'] ?? 0),
            'id_product_attribute' => (int) ($row['id_product_attribute'] ?? 0),
        ];
    }

    private function semanticOwnerWhere(int $shopId, string $source, string $sourceKey, string $semanticKey): string
    {
        return sprintf(
            "id_shop=%d AND source='%s' AND source_key='%s' AND semantic_key='%s'",
            $shopId, pSQL($source), pSQL($sourceKey), pSQL($semanticKey)
        );
    }

    /** @param array{source:string,source_key:string,semantic_key:string,id_product:int,id_product_attribute:int} $owner */
    private function matchesOwner(
        array $owner,
        string $source,
        string $sourceKey,
        string $semanticKey,
        int $productId,
        int $productAttributeId
    ): bool {
        return hash_equals($source, (string) $owner['source'])
            && hash_equals($sourceKey, (string) $owner['source_key'])
            && hash_equals($semanticKey, (string) $owner['semantic_key'])
            && (int) $owner['id_product'] === $productId
            && (int) $owner['id_product_attribute'] === $productAttributeId;
    }

    /** @param array{source:string,source_key:string,semantic_key:string,id_product:int,id_product_attribute:int} $owner */
    private function attributeOwnershipConflict(int $shopId, int $productAttributeId, array $owner): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            'Combination attribute ownership conflict: shop %d attribute %d is already owned by %s/%s/%s (product %d)',
            $shopId,
            $productAttributeId,
            $owner['source'],
            $owner['source_key'],
            $owner['semantic_key'],
            $owner['id_product']
        ));
    }
}
