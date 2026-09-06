<?php
namespace Lp\MatterhornImport\Admin;

final class StatusProvider
{
    /** @return array<string,mixed> */
    public function forShop(int $shopId): array
    {
        if ($shopId <= 0) {
            throw new \InvalidArgumentException('Status requires a concrete shop.');
        }

        $db = \Db::getInstance();
        $source = 'matterhorn';
        $sourceSql = pSQL($source);
        $run = $db->getRow(
            'SELECT id_run,status,read_status,import_status,update_status,remove_status,' .
            'image_reconcile_status,image_reconcile_checkpoint,image_reconcile_done,started_at,finished_at ' .
            'FROM `' . _DB_PREFIX_ . 'li_matterhornim_99dfbf_run` ' .
            'WHERE id_shop=' . $shopId . " AND source='" . $sourceSql . "' ORDER BY id_run DESC",
            false
        );

        return [
            'run' => is_array($run) ? $run : null,
            'images' => $this->queueCounts($shopId, $source, 'li_matterhornim_99dfbf_image_queue'),
            'new_products' => $this->queueCounts($shopId, $source, 'li_matterhornim_99dfbf_new_product_queue'),
            'image_orphans' => (int) $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'li_matterhornim_99dfbf_image_orphan` ' .
                'WHERE id_shop=' . $shopId . " AND source='" . $sourceSql . "'",
                false
            ),
        ];
    }

    /** @return array{pending:int,processing:int,failed:int,done:int} */
    private function queueCounts(int $shopId, string $source, string $table): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT status,COUNT(*) qty FROM `' . _DB_PREFIX_ . $table . '` ' .
            'WHERE id_shop=' . $shopId . " AND source='" . pSQL($source) . "' GROUP BY status",
            true,
            false
        ) ?: [];

        $counts = ['pending' => 0, 'processing' => 0, 'failed' => 0, 'done' => 0];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) ($row['qty'] ?? 0);
            }
        }

        return $counts;
    }
}
