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
                if ($this->queue->supersede($idQueue, $token, 'mapping no longer owns queued product')) { $superseded++; }
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
                if (!$this->mappingMatches($row)) { throw new StaleImageJobException('mapping changed while image was downloading'); }

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
                if (!$this->mappingMatches($row)) { throw new StaleImageJobException('mapping changed before image persistence'); }
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
            'processed'=>$done+$failed+$lost+$superseded,
        ];
    }

    private function mappingMatches(array $row): bool
    {
        return $this->mapping->ownsProduct((int) $row['id_shop'], (string) $row['source'], (string) $row['source_key'], (int) $row['id_product']);
    }

    private function assertLockedMappingOwnership(array $row): void
    {
        if (!$this->mapping->lockProductOwnership((int) $row['id_shop'], (string) $row['source'], (string) $row['source_key'], (int) $row['id_product'])) {
            throw new StaleImageJobException('mapping ownership changed before image state commit');
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
