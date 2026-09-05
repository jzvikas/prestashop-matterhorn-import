<?php
namespace Lp\MatterhornImport\Image;

use Lp\MatterhornImport\Repository\ImageQueueRepository;
use Lp\MatterhornImport\Repository\ImageStateRepository;
use Lp\MatterhornImport\Util\DatabaseSafety;

final class ImageWorker
{
    private const CONTENT_LOCK_TIMEOUT_SECONDS = 30;

    public function __construct(
        private ImageQueueRepository $queue,
        private SafeImageDownloader $downloader,
        private PrestaImageProcessor $processor,
        private DatabaseSafety $safety,
        private ImageStateRepository $state,
        private ImageFailureClassifier $failureClassifier
    ) {
    }

    public function tick(string $worker, int $limit = 20, ?int $shopId = null): array
    {
        $this->safety->assertTransactionalCore();
        $done = $failed = $lost = $deduplicated = $notModified = 0;

        foreach ($this->queue->claim($worker, $limit, $shopId) as $row) {
            $idQueue = (int) $row['id_queue'];
            $token = (string) ($row['locked_by'] ?? '');
            if ($token === '' || !$this->queue->renew($idQueue, $token)) { $lost++; continue; }

            $download = null;
            $db = \Db::getInstance();
            $transaction = false;
            $contentLock = null;

            try {
                $prior = $this->state->findByUrlHash((int) $row['id_shop'], (string) $row['source'], (string) $row['source_key'], (int) $row['id_product'], (string) $row['url_hash']);
                $download = $this->downloader->download((string) $row['url'], is_array($prior) ? ($prior['etag'] ?? null) : null, is_array($prior) ? ($prior['last_modified'] ?? null) : null);
                if (!$this->queue->renew($idQueue, $token)) { $lost++; continue; }

                if ($download === null) {
                    if (!is_array($prior) || (int) ($prior['id_image'] ?? 0) <= 0) { throw new \RuntimeException('Image returned 304 without reusable state'); }
                    if (!$db->execute('START TRANSACTION')) { throw new \RuntimeException('Could not start image revalidation transaction'); }
                    $transaction = true;
                    $this->state->touchNotModified($row, (int) $prior['id_image']);
                    $this->queue->done($idQueue, $token);
                    if (!$db->execute('COMMIT')) { throw new \RuntimeException('Image revalidation commit failed'); }
                    $transaction = false;
                    $notModified++; $done++; continue;
                }

                $contentLock = $this->contentLockName((int) $row['id_shop'], (int) $row['id_product'], $download->contentHash);
                if (!$this->acquireContentLock($db, $contentLock)) { throw new \RuntimeException('Timed out waiting for image content dedup lock'); }
                if (!$this->queue->renew($idQueue, $token)) { $lost++; continue; }
                if (!$db->execute('START TRANSACTION')) { throw new \RuntimeException('Could not start image transaction'); }
                $transaction = true;

                $duplicate = $this->state->findByContentHash((int) $row['id_shop'], (string) $row['source'], (int) $row['id_product'], $download->contentHash);
                if ($duplicate !== null) {
                    $idImage = (int) $duplicate['id_image'];
                    if ($idImage <= 0) { throw new \RuntimeException('Invalid deduplicated image state'); }
                    $this->state->save($row, $idImage, $download);
                    $deduplicated++;
                } else {
                    $attached = $this->processor->attach((int) $row['id_product'], (int) $row['id_shop'], $download, (int) $row['position'], (bool) $row['is_cover']);
                    if (!$this->transactionIsActive($db) && !$db->execute('START TRANSACTION')) {
                        throw new \RuntimeException('Could not restore image transaction after PrestaShop hook commit');
                    }
                    $this->state->save($row, $attached->idImage, $download);
                }

                $this->queue->done($idQueue, $token);
                if (!$db->execute('COMMIT')) { throw new \RuntimeException('Image transaction commit failed'); }
                $transaction = false;
                $done++;
            } catch (\Throwable $e) {
                if ($transaction) { try { if ($this->transactionIsActive($db)) { $db->execute('ROLLBACK'); } } catch (\Throwable) {} }
                try { $this->queue->fail($idQueue, $token, $e->getMessage(), $this->failureClassifier->isRetryable($e)); } catch (\Throwable) {}
                $failed++;
            } finally {
                if ($contentLock !== null) { $this->releaseContentLock($db, $contentLock); }
                if ($download instanceof DownloadedImage && is_file($download->path)) { @unlink($download->path); }
            }
        }

        return ['done'=>$done,'failed'=>$failed,'lost'=>$lost,'deduplicated'=>$deduplicated,'not_modified'=>$notModified,'processed'=>$done+$failed+$lost];
    }

    private function contentLockName(int $shopId, int $productId, string $contentHash): string
    {
        return 'mhimg:' . substr(hash('sha256', $shopId . ':' . $productId . ':' . $contentHash), 0, 48);
    }

    private function acquireContentLock(\Db $db, string $name): bool
    {
        return (string) $db->getValue(sprintf("SELECT GET_LOCK('%s',%d)", pSQL($name), self::CONTENT_LOCK_TIMEOUT_SECONDS)) === '1';
    }

    private function releaseContentLock(\Db $db, string $name): void
    {
        try { $db->getValue("SELECT RELEASE_LOCK('" . pSQL($name) . "')"); } catch (\Throwable) {}
    }

    private function transactionIsActive(\Db $db): bool
    {
        $value = $db->getValue('SELECT @@session.in_transaction');
        if ($value === false) { throw new \RuntimeException('Could not inspect image transaction state: ' . $db->getMsgError()); }
        return (int) $value === 1;
    }
}
