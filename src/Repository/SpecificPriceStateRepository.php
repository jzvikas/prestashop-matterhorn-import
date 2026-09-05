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
        $sql = sprintf("INSERT INTO `%s%s` (`id_shop`,`source`,`source_key`,`id_product`,`semantic_key`,`id_specific_price`,`applied_hash`,`last_seen_run_id`,`updated_at`) VALUES (%d,'%s','%s',%d,'%s',%d,'%s',%d,NOW()) ON DUPLICATE KEY UPDATE id_product=VALUES(id_product),id_specific_price=VALUES(id_specific_price),applied_hash=VALUES(applied_hash),last_seen_run_id=VALUES(last_seen_run_id),updated_at=NOW()", _DB_PREFIX_, self::TABLE, $shopId, pSQL($source), pSQL($sourceKey), $productId, pSQL($semanticKey), $specificPriceId, pSQL($appliedHash), $runId);
        if (!\Db::getInstance()->execute($sql)) { throw new \RuntimeException('Matterhorn specific-price ownership save failed'); }
    }

    public function delete(int $shopId, string $source, string $sourceKey, string $semanticKey): void
    {
        if (!\Db::getInstance()->delete(self::TABLE, sprintf("id_shop=%d AND source='%s' AND source_key='%s' AND semantic_key='%s'", $shopId, pSQL($source), pSQL($sourceKey), pSQL($semanticKey)))) {
            throw new \RuntimeException('Matterhorn specific-price ownership delete failed');
        }
    }
}
