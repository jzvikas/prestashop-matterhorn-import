<?php
namespace Lp\MatterhornImport\Image;

use Lp\MatterhornImport\Contract\SourceInterface;
use Lp\MatterhornImport\Exception\StaleImageJobException;
use Lp\MatterhornImport\Repository\ImageOrphanRepository;
use Lp\MatterhornImport\Repository\ImageQueueRepository;
use Lp\MatterhornImport\Repository\ImageStateRepository;
use Lp\MatterhornImport\Repository\MappingRepository;
use Lp\MatterhornImport\Util\DatabaseSafety;

final class ImageWorker
{
    private const CONTENT_LOCK_TIMEOUT_SECONDS = 30;
    private const QUEUE_TABLE = 'li_matterhornim_99dfbf_image_queue';
    private const MAPPING_TABLE = 'li_matterhornim_99dfbf_mapping';
    private const LEGACY_REDIRECT_RETRY_LIMIT = 1000;

    public function __construct(
        private ImageQueueRepository $queue,
        private SourceInterface $sourceAdapter,
        private SafeImageDownloader $downloader,
        private PrestaImageProcessor $processor,
        private DatabaseSafety $safety,
        private ImageStateRepository $state,
        private ImageFailureClassifier $failureClassifier,
        private MappingRepository $mapping,
        private ImageOrphanRepository $orphans
    ) {
    }

    public function tick(string $worker, int $limit = 20, ?int $shopId = null): array
    {
        $this->safety->assertTransactionalCore();
        $sourceName = trim($this->sourceAdapter->name());
        if ($sourceName === '') { throw new \RuntimeException('Image worker source name is empty'); }

        // Older builds did not follow Matterhorn HTTP->HTTPS/CDN redirects. Those jobs eventually
        // exhausted all five retries as generic "Image HTTP failure 30x" rows. Requeue only that
        // legacy signature, and only while an exact active mapping still owns the product. New
        // redirect-specific permanent errors use different messages, so they cannot loop here.
        $legacyRedirectRetried = $this->retryLegacyRedirectFailures($sourceName, $shopId);

        $done = $failed = $lost = $superseded = $deduplicated = $notModified = $replacedDeleted = $replacementCleanupFailed = 0;
        $hookCommitRecoveries = $attachedRollbackDeletes = $attachedRollbackDeleteFailed = 0;
        $orphanRecorded = $orphanRecordFailed = 0;

        foreach ($this->queue->claim($worker, $sourceName, $limit, $shopId) as $row) {
            $idQueue = (int) $row['id_queue'];
            $token = (string) ($row['locked_by'] ?? '');
            if ($token === '' || !$this->queue->renew($idQueue, $token)) {
                $lost++;
                continue;
            }
            if (!$this->mappingMatches($row)) {
                if ($this->queue->supersede($idQueue, $token, 'active mapping no longer owns queued product')) { $superseded++; }
                else { $lost++; }
                continue;
            }

            $download = null;
            $attached = null;
            $transaction = false;
            $externalImageCommit = false;
            $commitAttempted = false;
            $contentLock = null;
            $db = \Db::getInstance();
            try {
                $prior = $this->state->findByUrlHash((int) $row['id_shop'], (string) $row['source'], (string) $row['source_key'], (int) $row['id_product'], (string) $row['url_hash']);
                $download = $this->downloader->download((string) $row['url'], is_array($prior) ? ($prior['etag'] ?? null) : null, is_array($prior) ? ($prior['last_modified'] ?? null) : null);
                if (!$this->queue->renew($idQueue, $token)) { $lost++; continue; }
                if (!$this->mappingMatches($row)) { throw new StaleImageJobException('active mapping changed while image was downloading'); }

                if ($download === null) {
                    if (!is_array($prior) || (int) ($prior['id_image'] ?? 0) <= 0) { throw new \RuntimeException('Image returned 304 without reusable state'); }
                    if (!$db->execute('START TRANSACTION')) { throw new \RuntimeException('Could not start image revalidation transaction'); }
                    $transaction = true;
                    // Re-read under a row lock because a newer import run may have superseded
                    // id_run/position/cover while this worker was downloading/revalidating.
                    $row = $this->queue->lockOwned($idQueue, $token);
                    $this->assertLockedMappingOwnership($row);
                    $this->state->touchNotModified($row, (int) $prior['id_image']);
                    $this->queue->done($idQueue, $token);
                    $commitAttempted = true;
                    if (!$db->execute('COMMIT')) { throw new \RuntimeException('Image revalidation commit failed'); }
                    $transaction = false;
                    $notModified++;
                    $done++;
                    continue;
                }

                $contentLock = $this->contentLockName((int) $row['id_shop'], (int) $row['id_product'], $download->contentHash);
                if (!$this->acquireContentLock($db, $contentLock)) { throw new \RuntimeException('Timed out waiting for image content dedup lock'); }
                if (!$this->queue->renew($idQueue, $token)) { $lost++; continue; }
                if (!$this->mappingMatches($row)) { throw new StaleImageJobException('active mapping changed before image persistence'); }
                if (!$db->execute('START TRANSACTION')) { throw new \RuntimeException('Could not start image transaction'); }
                $transaction = true;
                $row = $this->queue->lockOwned($idQueue, $token);
                $this->assertLockedMappingOwnership($row);

                $duplicate = $this->state->findByContentHash((int) $row['id_shop'], (string) $row['source'], (int) $row['id_product'], $download->contentHash);
                if ($duplicate !== null) {
                    $idImage = (int) $duplicate['id_image'];
                    if ($idImage <= 0) { throw new \RuntimeException('Invalid deduplicated image state'); }
                    $this->state->save($row, $idImage, $download);
                    $deduplicated++;
                } else {
                    $attached = $this->processor->attach((int) $row['id_product'], (int) $row['id_shop'], $download, (int) $row['position'], (bool) $row['is_cover']);
                    $idImage = $attached->idImage;
                    if (!$this->transactionIsActive($db)) {
                        $externalImageCommit = true;
                        $hookCommitRecoveries++;
                        if (!$db->execute('START TRANSACTION')) { throw new \RuntimeException('Could not restore image transaction after PrestaShop hook commit'); }
                        // The hook commit released our queue row lock. Acquire it again and
                        // reload the newest desired run/placement before writing image_state.
                        $row = $this->queue->lockOwned($idQueue, $token);
                        $this->assertLockedMappingOwnership($row);
                    }
                    $this->state->save($row, $idImage, $download);
                }

                $replacement = $this->replacementCandidate($prior, $download, $idImage);
                if ($replacement !== null && (bool) $row['is_cover']) {
                    $this->processor->transferCover($replacement['id_image'], $idImage, (int) $row['id_product'], (int) $row['id_shop']);
                }
                $this->queue->done($idQueue, $token);
                $commitAttempted = true;
                if (!$db->execute('COMMIT')) { throw new \RuntimeException('Image transaction commit failed'); }
                $transaction = false;
                $done++;
                if ($contentLock !== null) {
                    $this->releaseContentLock($db, $contentLock);
                    $contentLock = null;
                }
                if ($replacement !== null) {
                    try {
                        if ($this->cleanupReplacement($db, $row, $replacement)) { $replacedDeleted++; }
                    } catch (\Throwable) { $replacementCleanupFailed++; }
                }
            } catch (\Throwable $e) {
                if ($transaction) {
                    try { if ($this->transactionIsActive($db)) { $db->execute('ROLLBACK'); } } catch (\Throwable) {}
                }

                $stale = $e instanceof StaleImageJobException;
                try {
                    if ($stale) {
                        if ($this->queue->supersede($idQueue, $token, $e->getMessage())) { $superseded++; } else { $lost++; }
                    } else {
                        $this->queue->fail($idQueue, $token, $e->getMessage(), $this->failureClassifier->isRetryable($e));
                    }
                } catch (\Throwable) {
                }

                if ($attached instanceof AttachedImage && !$commitAttempted) {
                    if ($externalImageCommit) {
                        $cleaned = false;
                        $cleanupError = null;
                        try {
                            $cleaned = $this->processor->deleteImage($attached->idImage, (int) $row['id_product'], (int) $row['id_shop']);
                        } catch (\Throwable $deleteError) {
                            $cleanupError = $deleteError->getMessage();
                        }
                        if ($cleaned) {
                            $attachedRollbackDeletes++;
                        } else {
                            $attachedRollbackDeleteFailed++;
                            try {
                                $this->orphans->record($row, $attached->idImage, $stale ? 'stale_mapping_cleanup' : 'hook_commit_rollback_cleanup', $cleanupError ?? $e->getMessage());
                                $orphanRecorded++;
                            } catch (\Throwable $orphanError) {
                                $orphanRecordFailed++;
                                error_log(sprintf('[matterhornimport] failed to persist image orphan marker queue=%d image=%d: %s', $idQueue, $attached->idImage, $orphanError->getMessage()));
                            }
                        }
                    } else {
                        $this->processor->cleanupFilesystem($attached);
                    }
                }
                if (!$stale) { $failed++; }
            } finally {
                if ($contentLock !== null) { $this->releaseContentLock($db, $contentLock); }
                if ($download instanceof DownloadedImage && is_file($download->path)) { @unlink($download->path); }
            }
        }

        return [
            'done'=>$done,'failed'=>$failed,'lost'=>$lost,'superseded'=>$superseded,'deduplicated'=>$deduplicated,'not_modified'=>$notModified,
            'replaced_deleted'=>$replacedDeleted,'replacement_cleanup_failed'=>$replacementCleanupFailed,'hook_commit_recoveries'=>$hookCommitRecoveries,
            'attached_rollback_deleted'=>$attachedRollbackDeletes,'attached_rollback_delete_failed'=>$attachedRollbackDeleteFailed,
            'orphan_recorded'=>$orphanRecorded,'orphan_record_failed'=>$orphanRecordFailed,
            'legacy_redirect_retried'=>$legacyRedirectRetried,
            'processed'=>$done+$failed+$lost+$superseded,
        ];
    }

    private function retryLegacyRedirectFailures(string $source, ?int $shopId): int
    {
        $shopWhere = $shopId === null ? '' : ' AND q.id_shop=' . (int) $shopId;
        // Build the LIKE list explicitly rather than broad-matching all HTTP errors: only failures
        // produced by the old no-redirect downloader are safe to resurrect automatically.
        $redirectWhere = "q.last_error LIKE 'Image HTTP failure 301%'"
            . " OR q.last_error LIKE 'Image HTTP failure 302%'"
            . " OR q.last_error LIKE 'Image HTTP failure 303%'"
            . " OR q.last_error LIKE 'Image HTTP failure 307%'"
            . " OR q.last_error LIKE 'Image HTTP failure 308%'";

        $db = \Db::getInstance();
        if (!$db->execute(sprintf(
            "UPDATE `%s%s` q SET status='pending',attempts=0,available_at=NULL,locked_by=NULL,locked_until=NULL,last_error=NULL,updated_at=NOW() " .
            "WHERE q.status='failed' AND q.attempts>=5 AND q.source='%s'%s AND (%s) " .
            "AND EXISTS (SELECT 1 FROM `%s%s` m WHERE m.id_shop=q.id_shop AND m.source=q.source AND m.source_key=q.source_key AND m.id_product=q.id_product AND m.out_of_feed=0) " .
            "ORDER BY q.id_queue LIMIT %d",
            _DB_PREFIX_,
            self::QUEUE_TABLE,
            pSQL($source),
            $shopWhere,
            $redirectWhere,
            _DB_PREFIX_,
            self::MAPPING_TABLE,
            self::LEGACY_REDIRECT_RETRY_LIMIT
        ))) {
            throw new \RuntimeException('Matterhorn legacy redirect image retry reset failed');
        }

        return (int) $db->Affected_Rows();
    }

    private function mappingMatches(array $row): bool
    {
        return $this->mapping->ownsActiveProduct((int) $row['id_shop'], (string) $row['source'], (string) $row['source_key'], (int) $row['id_product']);
    }

    private function assertLockedMappingOwnership(array $row): void
    {
        if (!$this->mapping->lockActiveProductOwnership((int) $row['id_shop'], (string) $row['source'], (string) $row['source_key'], (int) $row['id_product'])) {
            throw new StaleImageJobException('active mapping ownership changed before image state commit');
        }
    }

    private function replacementCandidate(?array $prior, DownloadedImage $download, int $newImageId): ?array
    {
        if (!is_array($prior)) { return null; }
        $oldImageId = (int) ($prior['id_image'] ?? 0);
        $oldHash = (string) ($prior['content_hash'] ?? '');
        if ($oldImageId <= 0 || $oldHash === '' || $oldImageId === $newImageId || hash_equals($oldHash, $download->contentHash)) { return null; }
        return ['id_image'=>$oldImageId,'content_hash'=>$oldHash];
    }

    private function cleanupReplacement(\Db $db, array $row, array $replacement): bool
    {
        $lock=$this->contentLockName((int)$row['id_shop'],(int)$row['id_product'],(string)$replacement['content_hash']);
        if(!$this->acquireContentLock($db,$lock)){throw new \RuntimeException('Timed out waiting for replaced-image cleanup lock');}
        try {
            if(!$this->state->canDeleteReplacedImage((int)$row['id_shop'],(int)$row['id_product'],(int)$replacement['id_image'])){return false;}
            return $this->processor->deleteImage((int)$replacement['id_image'],(int)$row['id_product'],(int)$row['id_shop']);
        } finally { $this->releaseContentLock($db,$lock); }
    }

    private function contentLockName(int $shopId,int $productId,string $contentHash): string { return 'matterhorn-img:'.substr(hash('sha256',$shopId.':'.$productId.':'.$contentHash),0,44); }
    private function acquireContentLock(\Db $db,string $name): bool
    {
        return (string)$db->getValue(
            sprintf("SELECT GET_LOCK('%s',%d)",pSQL($name),self::CONTENT_LOCK_TIMEOUT_SECONDS),
            false
        )==='1';
    }
    private function releaseContentLock(\Db $db,string $name): void { try{$db->getValue("SELECT RELEASE_LOCK('".pSQL($name)."')", false);}catch(\Throwable){} }
    private function transactionIsActive(\Db $db): bool
    {
        $value=$db->getValue('SELECT @@session.in_transaction', false);
        if($value===false){throw new \RuntimeException('Could not inspect image transaction state: '.$db->getMsgError());}
        return (int)$value===1;
    }
}
