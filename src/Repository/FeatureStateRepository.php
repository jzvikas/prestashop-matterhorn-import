<?php
namespace Lp\MatterhornImport\Repository;

final class FeatureStateRepository
{
    private const TABLE = 'li_matterhornim_99dfbf_feature_state';

    /** @return array<int,array<string,mixed>> */
    public function allForProduct(int $shopId, string $source, string $sourceKey, int $productId): array
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT * FROM `%s%s` WHERE id_shop=%d AND source='%s' AND source_key='%s' AND id_product=%d",
            _DB_PREFIX_, self::TABLE, $shopId, pSQL($source), pSQL($sourceKey), $productId
        ), true, false) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $featureId = (int) ($row['id_feature'] ?? 0);
            if ($featureId > 0) {
                $out[$featureId] = $row;
            }
        }
        return $out;
    }

    public function save(int $shopId, string $source, string $sourceKey, int $productId, int $featureId, int $valueId, int $runId): void
    {
        $source = trim($source);
        $sourceKey = trim($sourceKey);
        if ($shopId <= 0 || $source === '' || $sourceKey === '' || $productId <= 0 || $featureId <= 0 || $valueId <= 0 || $runId <= 0) {
            throw new \InvalidArgumentException('Feature ownership state save requires complete owner identity');
        }

        $existing = $this->exactOwner($shopId, $source, $sourceKey, $featureId);
        if ($existing !== null) {
            $this->assertSameProductOwner($existing, $productId, $featureId);
            if ((int) $existing['last_seen_run_id'] > $runId) {
                throw new \RuntimeException('Refusing stale feature ownership state save for feature ' . $featureId);
            }
        }

        $db = \Db::getInstance();
        $where = $this->exactOwnerWhere($shopId, $source, $sourceKey, $productId, $featureId)
            . ' AND last_seen_run_id<=' . $runId;
        $data = [
            'id_feature_value' => $valueId,
            'last_seen_run_id' => $runId,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing !== null) {
            if (!$db->update(self::TABLE, $data, $where, 0, true, false)) {
                throw new \RuntimeException('Feature exact-owner state update failed: ' . $db->getMsgError());
            }
            $afterUpdate = $this->exactOwner($shopId, $source, $sourceKey, $featureId);
            if ($afterUpdate === null) {
                throw new \RuntimeException('Feature ownership state disappeared during save for feature ' . $featureId);
            }
            $this->assertSameProductOwner($afterUpdate, $productId, $featureId);
            if ((int) $afterUpdate['id_feature_value'] !== $valueId || (int) $afterUpdate['last_seen_run_id'] !== $runId) {
                throw new \RuntimeException('Feature ownership state changed concurrently during save for feature ' . $featureId);
            }
            return;
        }

        if ($db->insert(self::TABLE, [
            'id_shop' => $shopId,
            'source' => pSQL($source),
            'source_key' => pSQL($sourceKey),
            'id_product' => $productId,
            'id_feature' => $featureId,
        ] + $data, false, true, \Db::INSERT)) {
            return;
        }

        $afterRace = $this->exactOwner($shopId, $source, $sourceKey, $featureId);
        if ($afterRace === null) {
            throw new \RuntimeException('Feature ownership state insert failed: ' . $db->getMsgError());
        }
        $this->assertSameProductOwner($afterRace, $productId, $featureId);
        if ((int) $afterRace['last_seen_run_id'] > $runId) {
            throw new \RuntimeException('Refusing stale feature ownership state save after concurrent insert for feature ' . $featureId);
        }
        if (!$db->update(self::TABLE, $data, $where, 0, true, false)) {
            throw new \RuntimeException('Feature concurrent ownership state refresh failed: ' . $db->getMsgError());
        }
        $final = $this->exactOwner($shopId, $source, $sourceKey, $featureId);
        if ($final === null) {
            throw new \RuntimeException('Feature ownership state disappeared after concurrent save for feature ' . $featureId);
        }
        $this->assertSameProductOwner($final, $productId, $featureId);
        if ((int) $final['id_feature_value'] !== $valueId || (int) $final['last_seen_run_id'] !== $runId) {
            throw new \RuntimeException('Feature ownership state changed concurrently after save for feature ' . $featureId);
        }
    }

    public function delete(
        int $shopId,
        string $source,
        string $sourceKey,
        int $productId,
        int $featureId,
        int $valueId
    ): void {
        $source = trim($source);
        $sourceKey = trim($sourceKey);
        if ($shopId <= 0 || $source === '' || $sourceKey === '' || $productId <= 0 || $featureId <= 0 || $valueId <= 0) {
            throw new \InvalidArgumentException('Feature ownership state delete requires complete owner identity');
        }

        $db = \Db::getInstance();
        if (!$db->delete(
            self::TABLE,
            $this->exactOwnerWhere($shopId, $source, $sourceKey, $productId, $featureId)
                . ' AND id_feature_value=' . $valueId
        )) {
            throw new \RuntimeException('Feature exact-owner state delete failed: ' . $db->getMsgError());
        }
        if ((int) $db->Affected_Rows() !== 1) {
            throw new \RuntimeException('Feature ownership state changed before exact delete for feature ' . $featureId);
        }
    }

    /** @return array{id_product:int,id_feature_value:int,last_seen_run_id:int}|null */
    private function exactOwner(int $shopId, string $source, string $sourceKey, int $featureId): ?array
    {
        $row = \Db::getInstance()->getRow(sprintf(
            "SELECT id_product,id_feature_value,last_seen_run_id FROM `%s%s` WHERE id_shop=%d AND source='%s' AND source_key='%s' AND id_feature=%d",
            _DB_PREFIX_, self::TABLE, $shopId, pSQL($source), pSQL($sourceKey), $featureId
        ), false);
        if (!is_array($row) || $row === []) { return null; }
        return [
            'id_product' => (int) ($row['id_product'] ?? 0),
            'id_feature_value' => (int) ($row['id_feature_value'] ?? 0),
            'last_seen_run_id' => (int) ($row['last_seen_run_id'] ?? 0),
        ];
    }

    private function exactOwnerWhere(int $shopId, string $source, string $sourceKey, int $productId, int $featureId): string
    {
        return sprintf(
            "id_shop=%d AND source='%s' AND source_key='%s' AND id_product=%d AND id_feature=%d",
            $shopId, pSQL($source), pSQL($sourceKey), $productId, $featureId
        );
    }

    /** @param array{id_product:int,id_feature_value:int,last_seen_run_id:int} $owner */
    private function assertSameProductOwner(array $owner, int $productId, int $featureId): void
    {
        if ((int) $owner['id_product'] !== $productId) {
            throw new \RuntimeException(sprintf(
                'Feature ownership conflict: feature %d belongs to product %d, not product %d',
                $featureId,
                (int) $owner['id_product'],
                $productId
            ));
        }
    }
}
