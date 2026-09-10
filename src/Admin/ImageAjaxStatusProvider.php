<?php
namespace Lp\MatterhornImport\Admin;

final class ImageAjaxStatusProvider
{
    private const QUEUE_TABLE = 'li_matterhornim_99dfbf_image_queue';
    private const MAPPING_TABLE = 'li_matterhornim_99dfbf_mapping';

    /** @param array<string,mixed> $run @return array<string,mixed> */
    public function present(array $run): array
    {
        $runId = (int) ($run['id_run'] ?? 0);
        $shopId = (int) ($run['id_shop'] ?? 0);
        $source = trim((string) ($run['source'] ?? ''));
        if ($runId <= 0 || $shopId <= 0 || $source === '') {
            return $this->emptyStatus($run);
        }

        $runCounts = $this->queueCountsForRun($runId, $shopId, $source);
        $sourceCounts = $this->activeSourceQueueCounts($shopId, $source);
        $catalogStatus = (string) ($run['status'] ?? '');
        $importStatus = (string) ($run['import_status'] ?? 'pending');
        $reconcileStatus = (string) ($run['image_reconcile_status'] ?? 'pending');
        $catalogCompleted = $catalogStatus === 'completed'
            && $this->allCatalogStagesCompleted($run);

        $sourcePending = $sourceCounts['pending'];
        $sourceProcessing = $sourceCounts['processing'];
        $sourceFailed = $sourceCounts['failed'];
        $sourceDownloadWork = ($sourcePending + $sourceProcessing) > 0;
        $runDownloadWork = $runCounts['pending'] + $runCounts['processing'];
        $sourceDownloadWorkCount = $sourcePending + $sourceProcessing;
        // A later idempotent run can legitimately own no new image rows while its BO page
        // continues durable work left by an older generation. In that case a run-scoped
        // percentage (0/0, or a smaller run subset) is not a truthful source-backlog
        // percentage. Keep the progress bar animated/indeterminate while counts remain visible.
        $progressScopeMismatch = $sourceDownloadWork
            && $runDownloadWork !== $sourceDownloadWorkCount;
        $catalogCanProduceImages = in_array($catalogStatus, ['running', 'paused'], true)
            && in_array($importStatus, ['running', 'completed'], true);

        $workerActive = $sourceDownloadWork || $catalogCanProduceImages;
        if (!in_array($catalogStatus, ['running', 'paused', 'completed'], true)) {
            $workerActive = false;
        }

        $blockedByFailed = $catalogCompleted
            && !$sourceDownloadWork
            && $sourceFailed > 0;
        $reconcileFailed = $catalogCompleted && $reconcileStatus === 'failed';
        $needsReconcile = $catalogCompleted
            && !$sourceDownloadWork
            && $sourceFailed === 0
            && !in_array($reconcileStatus, ['completed', 'failed'], true);
        $active = $workerActive || $needsReconcile;

        $total = array_sum($runCounts);
        $done = $runCounts['done'];
        $percent = $total > 0 ? (int) floor(($done / $total) * 100) : 0;
        $indeterminate = !$catalogCompleted || $needsReconcile || $progressScopeMismatch;
        $stage = 'waiting';
        $status = 'waiting';

        if ($blockedByFailed || $reconcileFailed) {
            $stage = $reconcileFailed ? 'reconcile' : 'download';
            $status = 'failed';
            $indeterminate = false;
        } elseif ($needsReconcile) {
            $stage = 'reconcile';
            $status = in_array($reconcileStatus, ['running', 'paused'], true)
                ? 'reconciling'
                : 'reconcile_pending';
        } elseif ($workerActive) {
            $stage = 'download';
            $status = $sourceDownloadWork ? 'running' : 'waiting_for_images';
        } elseif ($catalogCompleted && $reconcileStatus === 'completed') {
            $stage = 'completed';
            $status = 'completed';
            $percent = 100;
            $indeterminate = false;
        }

        return [
            'status' => $status,
            'stage' => $stage,
            'active' => $active,
            'worker_active' => $workerActive,
            'needs_reconcile' => $needsReconcile,
            'indeterminate' => $indeterminate,
            'percent' => max(0, min(100, $percent)),
            'total' => $total,
            'done' => $done,
            'pending' => $runCounts['pending'],
            'processing' => $runCounts['processing'],
            'failed' => $runCounts['failed'],
            'source_pending' => $sourcePending,
            'source_processing' => $sourceProcessing,
            'source_failed' => $sourceFailed,
            'source_unresolved' => $sourcePending + $sourceProcessing + $sourceFailed,
            'reconcile_status' => $reconcileStatus,
            'reconcile_done' => (int) ($run['image_reconcile_done'] ?? 0),
        ];
    }

    /** @return array{pending:int,processing:int,failed:int,done:int} */
    private function queueCountsForRun(int $runId, int $shopId, string $source): array
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT status,COUNT(*) qty FROM `%s%s` WHERE id_run=%d AND id_shop=%d AND source='%s' GROUP BY status",
            _DB_PREFIX_,
            self::QUEUE_TABLE,
            $runId,
            $shopId,
            pSQL($source)
        ), true, false) ?: [];

        return $this->normalizeCounts($rows);
    }

    /** @return array{pending:int,processing:int,failed:int,done:int} */
    private function activeSourceQueueCounts(int $shopId, string $source): array
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT q.status,COUNT(*) qty FROM `%s%s` q " .
            "INNER JOIN `%s%s` m ON m.id_shop=q.id_shop AND m.source=q.source " .
            "AND m.source_key=q.source_key AND m.id_product=q.id_product AND m.out_of_feed=0 " .
            "WHERE q.id_shop=%d AND q.source='%s' AND q.status<>'done' GROUP BY q.status",
            _DB_PREFIX_,
            self::QUEUE_TABLE,
            _DB_PREFIX_,
            self::MAPPING_TABLE,
            $shopId,
            pSQL($source)
        ), true, false) ?: [];

        return $this->normalizeCounts($rows);
    }

    /** @param list<array<string,mixed>> $rows @return array{pending:int,processing:int,failed:int,done:int} */
    private function normalizeCounts(array $rows): array
    {
        $counts = ['pending' => 0, 'processing' => 0, 'failed' => 0, 'done' => 0];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = max(0, (int) ($row['qty'] ?? 0));
            }
        }

        return $counts;
    }

    /** @param array<string,mixed> $run */
    private function allCatalogStagesCompleted(array $run): bool
    {
        foreach (['read_status', 'import_status', 'update_status', 'remove_status'] as $field) {
            if ((string) ($run[$field] ?? '') !== 'completed') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $run @return array<string,mixed> */
    private function emptyStatus(array $run): array
    {
        return [
            'status' => 'waiting',
            'stage' => 'waiting',
            'active' => false,
            'worker_active' => false,
            'needs_reconcile' => false,
            'indeterminate' => false,
            'percent' => 0,
            'total' => 0,
            'done' => 0,
            'pending' => 0,
            'processing' => 0,
            'failed' => 0,
            'source_pending' => 0,
            'source_processing' => 0,
            'source_failed' => 0,
            'source_unresolved' => 0,
            'reconcile_status' => (string) ($run['image_reconcile_status'] ?? 'pending'),
            'reconcile_done' => (int) ($run['image_reconcile_done'] ?? 0),
        ];
    }
}
