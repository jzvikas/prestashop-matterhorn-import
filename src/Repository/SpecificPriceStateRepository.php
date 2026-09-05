<?php
namespace Lp\MatterhornImport\Repository;

final class SpecificPriceStateRepository
{
    private const TABLE = 'li_matterhornim_99dfbf_specific_price_state';

    public function allForProduct(int $shopId, string $source, string $sourceKey, int $productId): array
    {
        $rows = \Db::getInstance()->executeS(sprintf("SELECT * FROM `%s%s` WHERE id_shop=%d AND source='%s' AND source_key='%s' AND id_product=%d ORDER BY semantic_key", _DB_PREFIX_, self::TABLE, $shopId, pSQL($source), pSQL($sourceKey), $productId), true, false) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['semantic_key'] ?? '');
            if ($key !== '') { $out[$key] = $row; }
        }
        return $out;
    }

    public function save(int $shopId, string $source, string $sourceKey, int $productId, string $semanticKey, int $specificPriceId, string $appliedHash, int $runId): void
    {
        $source = trim($source);
        $sourceKey = trim($sourceKey);
        $semanticKey = trim($semanticKey);
        if ($shopId <= 0 || $source === '' || $sourceKey === '' || $productId <= 0 || $semanticKey === '' || $specificPriceId <= 0 || $appliedHash === '' || $runId <= 0) {
            throw new \InvalidArgumentException('Specific-price ownership state save requires complete owner identity');
        }

        $existing = $this->exactOwner($shopId, $source, $sourceKey, $semanticKey);
        if ($existing !== null) {
            $this->assertSameOwner($existing, $productId, $specificPriceId, $semanticKey);
            if ((int) $existing['last_seen_run_id'] > $runId) {
                throw new \RuntimeException('Refusing stale specific-price ownership state save for semantic key ' . $semanticKey);
            }
        }

        $db = \Db::getInstance();
        $where = $this->exactOwnerWhere($shopId, $source, $sourceKey, $productId, $semanticKey, $specificPriceId)
            . ' AND last_seen_run_id<=' . $runId;
        $data = [
            'applied_hash' => pSQL($appliedHash),
            'last_seen_run_id' => $runId,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing !== null) {
            if (!$db->update(self::TABLE, $data, $where, 0, true, false)) {
                throw new \RuntimeException('Specific-price exact-owner state update failed: ' . $db->getMsgError());
            }
            $afterUpdate = $this->exactOwner($shopId, $source, $sourceKey, $semanticKey);
            if ($afterUpdate === null) {
                throw new \RuntimeException('Specific-price ownership state disappeared during save for semantic key ' . $semanticKey);
            }
            $this->assertSameOwner($afterUpdate, $productId, $specificPriceId, $semanticKey);
            if ((string) $afterUpdate['applied_hash'] !== $appliedHash || (int) $afterUpdate['last_seen_run_id'] !== $runId) {
                throw new \RuntimeException('Specific-price ownership state changed concurrently during save for semantic key ' . $semanticKey);
            }
            return;
        }

        if ($db->insert(self::TABLE, [
            'id_shop' => $shopId,
            'source' => pSQL($source),
            'source_key' => pSQL($sourceKey),
            'id_product' => $productId,
            'semantic_key' => pSQL($semanticKey),
            'id_specific_price' => $specificPriceId,
        ] + $data, false, true, \Db::INSERT)) {
            return;
        }

        $afterRace = $this->exactOwner($shopId, $source, $sourceKey, $semanticKey);
        if ($afterRace === null) {
            throw new \RuntimeException('Specific-price ownership state insert failed: ' . $db->getMsgError());
        }
        $this->assertSameOwner($afterRace, $productId, $specificPriceId, $semanticKey);
        if ((int) $afterRace['last_seen_run_id'] > $runId) {
            throw new \RuntimeException('Refusing stale specific-price ownership state save after concurrent insert for semantic key ' . $semanticKey);
        }
        if (!$db->update(self::TABLE, $data, $where, 0, true, false)) {
            throw new \RuntimeException('Specific-price concurrent ownership state refresh failed: ' . $db->getMsgError());
        }
        $final = $this->exactOwner($shopId, $source, $sourceKey, $semanticKey);
        if ($final === null) {
            throw new \RuntimeException('Specific-price ownership state disappeared after concurrent save for semantic key ' . $semanticKey);
        }
        $this->assertSameOwner($final, $productId, $specificPriceId, $semanticKey);
        if ((string) $final['applied_hash'] !== $appliedHash || (int) $final['last_seen_run_id'] !== $runId) {
            throw new \RuntimeException('Specific-price ownership state changed concurrently after save for semantic key ' . $semanticKey);
        }
    }

    public function delete(
        int $shopId,
        string $source,
        string $sourceKey,
        int $productId,
        string $semanticKey,
        int $specificPriceId,
        string $appliedHash
    ): void {
        $source = trim($source);
        $sourceKey = trim($sourceKey);
        $semanticKey = trim($semanticKey);
        if ($shopId <= 0 || $source === '' || $sourceKey === '' || $productId <= 0 || $semanticKey === '' || $specificPriceId <= 0 || $appliedHash === '') {
            throw new \InvalidArgumentException('Specific-price ownership state delete requires complete owner identity');
        }

        $db = \Db::getInstance();
        if (!$db->delete(
            self::TABLE,
            $this->exactOwnerWhere($shopId, $source, $sourceKey, $productId, $semanticKey, $specificPriceId)
                . " AND applied_hash='" . pSQL($appliedHash) . "'"
        )) {
            throw new \RuntimeException('Specific-price exact-owner state delete failed: ' . $db->getMsgError());
        }
        if ((int) $db->Affected_Rows() !== 1) {
            throw new \RuntimeException('Specific-price ownership state changed before exact delete for semantic key ' . $semanticKey);
        }
    }

    /** @return array{id_product:int,id_specific_price:int,applied_hash:string,last_seen_run_id:int}|null */
    private function exactOwner(int $shopId, string $source, string $sourceKey, string $semanticKey): ?array
    {
        $row = \Db::getInstance()->getRow(sprintf(
            "SELECT id_product,id_specific_price,applied_hash,last_seen_run_id FROM `%s%s` WHERE id_shop=%d AND source='%s' AND source_key='%s' AND semantic_key='%s'",
            _DB_PREFIX_, self::TABLE, $shopId, pSQL($source), pSQL($sourceKey), pSQL($semanticKey)
        ), false);
        if (!is_array($row) || $row === []) { return null; }
        return [
            'id_product' => (int) ($row['id_product'] ?? 0),
            'id_specific_price' => (int) ($row['id_specific_price'] ?? 0),
            'applied_hash' => (string) ($row['applied_hash'] ?? ''),
            'last_seen_run_id' => (int) ($row['last_seen_run_id'] ?? 0),
        ];
    }

    private function exactOwnerWhere(int $shopId, string $source, string $sourceKey, int $productId, string $semanticKey, int $specificPriceId): string
    {
        return sprintf(
            "id_shop=%d AND source='%s' AND source_key='%s' AND id_product=%d AND semantic_key='%s' AND id_specific_price=%d",
            $shopId, pSQL($source), pSQL($sourceKey), $productId, pSQL($semanticKey), $specificPriceId
        );
    }

    /** @param array{id_product:int,id_specific_price:int,applied_hash:string,last_seen_run_id:int} $owner */
    private function assertSameOwner(array $owner, int $productId, int $specificPriceId, string $semanticKey): void
    {
        if ((int) $owner['id_product'] !== $productId || (int) $owner['id_specific_price'] !== $specificPriceId) {
            throw new \RuntimeException(sprintf(
                'Specific-price ownership conflict: semantic key %s belongs to product/specific-price %d/%d, not %d/%d',
                $semanticKey,
                (int) $owner['id_product'],
                (int) $owner['id_specific_price'],
                $productId,
                $specificPriceId
            ));
        }
    }
}
