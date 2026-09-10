<?php
namespace Lp\MatterhornImport\Controller;

use Lp\MatterhornImport\Admin\AdminErrorReporter;
use Lp\MatterhornImport\Admin\ImageAjaxStatusProvider;
use Lp\MatterhornImport\Admin\ImportStatusProvider;
use Lp\MatterhornImport\Config\OperationalSettings;
use Lp\MatterhornImport\Database\AjaxDatabaseSessionGuard;
use Lp\MatterhornImport\Image\ImageReconciler;
use Lp\MatterhornImport\Image\ImageWorker;
use Lp\MatterhornImport\Import\ImportRunner;
use Lp\MatterhornImport\Lock\ImportLock;
use Lp\MatterhornImport\Repository\ImageQueueRepository;
use Lp\MatterhornImport\Repository\RunRepository;
use Lp\MatterhornImport\Source\RunSourceSnapshotManager;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ImportController extends PrestaShopAdminController
{
    private const SOURCE = 'matterhorn';
    private const AJAX_TIME_LIMIT_SECONDS = 10;
    private const MAX_AJAX_BATCH = 1000;
    private const MAX_IMAGE_WORKER_SLOT = 2;
    private const AJAX_IMAGE_RECONCILE_BATCH = 100;
    private const AJAX_IMAGE_RECONCILE_TIME_LIMIT_SECONDS = 8;

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(
        RunRepository $runs,
        ImportStatusProvider $status,
        ImageAjaxStatusProvider $images,
        OperationalSettings $settings
    ): Response {
        if (\Shop::getContext() !== \Shop::CONTEXT_SHOP) {
            return $this->render('@Modules/matterhornimport/views/templates/admin/import/index.html.twig', [
                'shopContextError' => $this->trans(
                    'Select one concrete shop in the multistore selector before running the Matterhorn import.',
                    [],
                    'Modules.Matterhornimport.Admin'
                ),
                'currentShopId' => 0,
                'currentShopName' => null,
                'jobs' => [],
                'activeRun' => null,
                'batchSize' => 250,
                'adminImportAssetVersion' => $this->assetVersion(),
            ]);
        }

        [$shopId, $shopName] = $this->shopContext();
        $active = $runs->findActive($shopId, self::SOURCE);
        $activePublic = $active === null ? null : $this->presentRun($active, $status, $images);

        // A catalogue run is terminal before its durable image queue/reconciliation is necessarily
        // finished. Restore the latest completed run as browser work while its image lane is active.
        if ($activePublic === null) {
            $latest = $runs->latest($shopId, self::SOURCE);
            if ($latest !== null && (string) ($latest['status'] ?? '') === 'completed') {
                $candidate = $this->presentRun($latest, $status, $images);
                if (
                    (bool) ($candidate['images']['active'] ?? false)
                    || (string) ($candidate['images']['status'] ?? '') === 'failed'
                ) {
                    $active = $latest;
                    $activePublic = $candidate;
                }
            }
        }

        $activeId = (int) ($active['id_run'] ?? 0);
        $recent = [];
        foreach ($runs->recent($shopId, self::SOURCE, 20) as $row) {
            $runId = (int) ($row['id_run'] ?? 0);
            $recent[] = $activePublic !== null && $runId === $activeId
                ? $activePublic
                : $status->present($row);
        }

        return $this->render('@Modules/matterhornimport/views/templates/admin/import/index.html.twig', [
            'shopContextError' => null,
            'currentShopId' => $shopId,
            'currentShopName' => $shopName,
            'jobs' => $recent,
            'activeRun' => $activePublic,
            'batchSize' => max(1, min(self::MAX_AJAX_BATCH, min(250, $settings->batchSize($shopId)))),
            'adminImportAssetVersion' => $this->assetVersion(),
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function start(
        Request $request,
        RunRepository $runs,
        ImportStatusProvider $status,
        ImageAjaxStatusProvider $images,
        ImportLock $lock,
        AdminErrorReporter $errors
    ): JsonResponse {
        if (!$this->isValidAjaxPost($request)) {
            return $this->jsonError('Invalid security token.', Response::HTTP_BAD_REQUEST);
        }

        try {
            [$shopId] = $this->shopContext();
            if (!$lock->acquire($shopId, self::SOURCE, 0)) {
                return $this->jsonError(
                    'A Matterhorn import batch is currently running for this shop. Try again when it finishes.',
                    Response::HTTP_CONFLICT
                );
            }

            try {
                $active = $runs->findActive($shopId, self::SOURCE);
                $run = $active ?? $runs->get($runs->create($shopId, self::SOURCE));
                if ($run === null) {
                    throw new \RuntimeException('Could not reload the newly created Matterhorn import run');
                }
            } finally {
                $lock->release();
            }

            return new JsonResponse(
                ['success' => true, 'job' => $this->presentRun($run, $status, $images)],
                $active === null ? Response::HTTP_CREATED : Response::HTTP_OK
            );
        } catch (\Throwable $exception) {
            return $this->exceptionError('ajax-import-start', $exception, $errors, Response::HTTP_CONFLICT);
        }
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function batch(
        Request $request,
        RunRepository $runs,
        ImportRunner $runner,
        ImportStatusProvider $status,
        ImageAjaxStatusProvider $images,
        AdminErrorReporter $errors,
        AjaxDatabaseSessionGuard $databaseSession
    ): JsonResponse {
        if (!$this->isValidAjaxPost($request)) {
            return $this->jsonError('Invalid security token.', Response::HTTP_BAD_REQUEST);
        }

        try {
            [$shopId] = $this->shopContext();
            $runId = $this->positiveRunId($request);
            $batch = $this->batchSize($request);
            $run = $runs->assertContext($runId, $shopId, self::SOURCE);
            if (!in_array((string) ($run['status'] ?? ''), ['running', 'paused'], true)) {
                return $this->jsonError(
                    'This Matterhorn import run is no longer active. Start a new import.',
                    Response::HTTP_CONFLICT
                );
            }

            $databaseSession->prepareLegacy();
            $runner->runBounded(
                $shopId,
                $batch,
                $batch,
                self::AJAX_TIME_LIMIT_SECONDS,
                $runId
            );

            $run = $runs->get($runId);
            if ($run === null) {
                throw new \RuntimeException('Matterhorn import run disappeared after AJAX batch');
            }

            return new JsonResponse(['success' => true, 'job' => $this->presentRun($run, $status, $images)]);
        } catch (\InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $exception) {
            return $this->exceptionError('ajax-import-batch', $exception, $errors, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function imagesBatch(
        Request $request,
        RunRepository $runs,
        ImportStatusProvider $status,
        ImageAjaxStatusProvider $images,
        ImageWorker $worker,
        ImageReconciler $reconciler,
        AdminErrorReporter $errors,
        AjaxDatabaseSessionGuard $databaseSession
    ): JsonResponse {
        if (!$this->isValidAjaxPost($request)) {
            return $this->jsonError('Invalid security token.', Response::HTTP_BAD_REQUEST);
        }

        try {
            [$shopId] = $this->shopContext();
            $runId = $this->positiveRunId($request);
            $slot = $this->imageWorkerSlot($request);
            $run = $runs->assertContext($runId, $shopId, self::SOURCE);
            if (!in_array((string) ($run['status'] ?? ''), ['running', 'paused', 'completed'], true)) {
                return $this->jsonError(
                    'This Matterhorn run cannot process images in its current state.',
                    Response::HTTP_CONFLICT
                );
            }

            $latest = $runs->latest($shopId, self::SOURCE);
            if ($latest === null || (int) ($latest['id_run'] ?? 0) !== $runId) {
                return $this->jsonError(
                    'A newer Matterhorn run exists. Reload the page before continuing image processing.',
                    Response::HTTP_CONFLICT
                );
            }

            $databaseSession->prepareLegacy();
            $imageState = $images->present($run);
            $workerResult = null;
            if ((bool) ($imageState['worker_active'] ?? false)) {
                // One potentially slow image per HTTP request. Two browser slots provide bounded
                // concurrency while the queue lease/token fencing prevents duplicate ownership.
                $workerResult = $worker->tick($this->imageWorkerLabel($shopId, $runId, $slot), 1, $shopId);
            }

            $run = $runs->get($runId);
            if ($run === null) {
                throw new \RuntimeException('Matterhorn import run disappeared after AJAX image batch');
            }
            $imageState = $images->present($run);
            $reconcileResult = null;

            // Only slot 1 performs bounded final reconciliation. Slot 2 remains a download worker,
            // which avoids two browser requests racing the exclusive import/reconcile lock.
            if ($slot === 1 && (bool) ($imageState['needs_reconcile'] ?? false)) {
                try {
                    $reconcileResult = $reconciler->run(
                        $runId,
                        $shopId,
                        self::AJAX_IMAGE_RECONCILE_BATCH,
                        self::AJAX_IMAGE_RECONCILE_BATCH,
                        self::AJAX_IMAGE_RECONCILE_TIME_LIMIT_SECONDS
                    );
                } catch (\RuntimeException $exception) {
                    if ($exception->getMessage() !== 'Import/reconciliation lock is busy') {
                        throw $exception;
                    }
                }

                $run = $runs->get($runId);
                if ($run === null) {
                    throw new \RuntimeException('Matterhorn import run disappeared after AJAX image reconciliation');
                }
            }

            return new JsonResponse([
                'success' => true,
                'job' => $this->presentRun($run, $status, $images),
                'image_batch' => $workerResult,
                'image_reconcile' => $reconcileResult,
            ]);
        } catch (\InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $exception) {
            return $this->exceptionError('ajax-images-batch', $exception, $errors, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function imagesRetry(
        Request $request,
        RunRepository $runs,
        ImportStatusProvider $status,
        ImageAjaxStatusProvider $images,
        ImageQueueRepository $queue,
        OperationalSettings $settings,
        AdminErrorReporter $errors,
        AjaxDatabaseSessionGuard $databaseSession
    ): JsonResponse {
        if (!$this->isValidAjaxPost($request)) {
            return $this->jsonError('Invalid security token.', Response::HTTP_BAD_REQUEST);
        }

        try {
            [$shopId] = $this->shopContext();
            $runId = $this->positiveRunId($request);
            $run = $runs->assertContext($runId, $shopId, self::SOURCE);
            if ((string) ($run['status'] ?? '') !== 'completed') {
                return $this->jsonError(
                    'Failed images can be retried after the catalogue run completes.',
                    Response::HTTP_CONFLICT
                );
            }

            $latest = $runs->latest($shopId, self::SOURCE);
            if ($latest === null || (int) ($latest['id_run'] ?? 0) !== $runId) {
                return $this->jsonError(
                    'A newer Matterhorn run exists. Reload the page before retrying images.',
                    Response::HTTP_CONFLICT
                );
            }

            $imageState = $images->present($run);
            if ((bool) ($imageState['worker_active'] ?? false)) {
                return $this->jsonError(
                    'Pending image downloads must finish before failed images are retried.',
                    Response::HTTP_CONFLICT
                );
            }

            $databaseSession->prepareLegacy();
            $limit = $settings->retryLimit($shopId);
            $retried = $queue->retryFailed(self::SOURCE, $shopId, $limit);
            $run = $runs->get($runId);
            if ($run === null) {
                throw new \RuntimeException('Matterhorn import run disappeared after image retry');
            }

            return new JsonResponse([
                'success' => true,
                'job' => $this->presentRun($run, $status, $images),
                'image_retry' => ['retried' => $retried, 'limit' => $limit],
            ]);
        } catch (\InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $exception) {
            return $this->exceptionError('ajax-images-retry', $exception, $errors, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function status(
        Request $request,
        RunRepository $runs,
        ImportStatusProvider $status,
        ImageAjaxStatusProvider $images,
        AdminErrorReporter $errors
    ): JsonResponse {
        if (!$this->isValidAjaxPost($request)) {
            return $this->jsonError('Invalid security token.', Response::HTTP_BAD_REQUEST);
        }

        try {
            [$shopId] = $this->shopContext();
            $run = $runs->assertContext($this->positiveRunId($request), $shopId, self::SOURCE);

            return new JsonResponse(['success' => true, 'job' => $this->presentRun($run, $status, $images)]);
        } catch (\InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $exception) {
            return $this->exceptionError('ajax-import-status', $exception, $errors, Response::HTTP_NOT_FOUND);
        }
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function cancel(
        Request $request,
        RunRepository $runs,
        ImportStatusProvider $status,
        ImageAjaxStatusProvider $images,
        ImportLock $lock,
        AdminErrorReporter $errors,
        RunSourceSnapshotManager $runSources
    ): JsonResponse {
        if (!$this->isValidAjaxPost($request)) {
            return $this->jsonError('Invalid security token.', Response::HTTP_BAD_REQUEST);
        }

        try {
            [$shopId] = $this->shopContext();
            $runId = $this->positiveRunId($request);
            $runs->assertContext($runId, $shopId, self::SOURCE);

            if (!$lock->acquire($shopId, self::SOURCE, 0)) {
                return $this->jsonError(
                    'The current Matterhorn AJAX batch is still finishing. Try Cancel again in a moment.',
                    Response::HTTP_CONFLICT
                );
            }

            try {
                $run = $runs->cancel($runId);
                try {
                    $runSources->release($runId, $shopId);
                } catch (\Throwable $cleanupError) {
                    error_log(sprintf(
                        '[matterhornimport] could not release cancelled run source %d: %s',
                        $runId,
                        $cleanupError->getMessage()
                    ));
                }
            } finally {
                $lock->release();
            }

            return new JsonResponse(['success' => true, 'job' => $this->presentRun($run, $status, $images)]);
        } catch (\InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $exception) {
            return $this->exceptionError('ajax-import-cancel', $exception, $errors, Response::HTTP_BAD_REQUEST);
        }
    }

    /** @return array{0:int,1:string} */
    private function shopContext(): array
    {
        if (\Shop::getContext() !== \Shop::CONTEXT_SHOP) {
            throw $this->createAccessDeniedException(
                'Select one concrete shop before running Matterhorn AJAX import.'
            );
        }

        $shop = \Context::getContext()->shop;
        $shopId = (int) ($shop->id ?? 0);
        if ($shopId <= 0) {
            throw $this->createAccessDeniedException('Could not resolve active shop for Matterhorn AJAX import.');
        }

        return [$shopId, (string) ($shop->name ?? ('#' . $shopId))];
    }

    private function isValidAjaxPost(Request $request): bool
    {
        return $request->isMethod('POST')
            && $this->isCsrfTokenValid(
                'matterhorn_ajax_import',
                (string) $request->request->get('_token')
            );
    }

    private function positiveRunId(Request $request): int
    {
        $runId = filter_var(
            $request->request->get('job_id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($runId === false) {
            throw new \InvalidArgumentException('A positive Matterhorn run ID is required.');
        }

        return (int) $runId;
    }

    private function batchSize(Request $request): int
    {
        $batch = filter_var(
            $request->request->get('batch_size'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => self::MAX_AJAX_BATCH]]
        );
        if ($batch === false) {
            throw new \InvalidArgumentException(
                'AJAX batch size must be an integer from 1 to ' . self::MAX_AJAX_BATCH . '.'
            );
        }

        return (int) $batch;
    }

    private function imageWorkerSlot(Request $request): int
    {
        $slot = filter_var(
            $request->request->get('worker_slot'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => self::MAX_IMAGE_WORKER_SLOT]]
        );
        if ($slot === false) {
            throw new \InvalidArgumentException(
                'AJAX image worker slot must be an integer from 1 to ' . self::MAX_IMAGE_WORKER_SLOT . '.'
            );
        }

        return (int) $slot;
    }

    private function imageWorkerLabel(int $shopId, int $runId, int $slot): string
    {
        return sprintf('ajax-image-s%d-r%d-w%d', $shopId, $runId, $slot);
    }

    /** @param array<string,mixed> $run @return array<string,mixed> */
    private function presentRun(
        array $run,
        ImportStatusProvider $status,
        ImageAjaxStatusProvider $images
    ): array {
        $presented = $status->present($run);
        $presented['images'] = $images->present($run);

        return $presented;
    }

    private function assetVersion(): string
    {
        $path = _PS_MODULE_DIR_ . 'matterhornimport/views/js/admin-import.js';
        $modified = @filemtime($path);

        return $modified === false ? '1' : (string) $modified;
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message], $status);
    }

    private function exceptionError(
        string $operation,
        \Throwable $exception,
        AdminErrorReporter $errors,
        int $status
    ): JsonResponse {
        $reference = $errors->report($operation, $exception);
        $safeMessage = $errors->safeMessage($exception);
        $message = $safeMessage === ''
            ? 'Operation failed. Reference: ' . $reference
            : 'Operation failed: ' . $safeMessage . ' Reference: ' . $reference;

        return $this->jsonError($message, $status);
    }
}
