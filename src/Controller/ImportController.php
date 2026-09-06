<?php
namespace Lp\MatterhornImport\Controller;

use Lp\MatterhornImport\Admin\AdminErrorReporter;
use Lp\MatterhornImport\Admin\ImportStatusProvider;
use Lp\MatterhornImport\Config\OperationalSettings;
use Lp\MatterhornImport\Import\ImportRunner;
use Lp\MatterhornImport\Lock\ImportLock;
use Lp\MatterhornImport\Repository\RunRepository;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ImportController extends PrestaShopAdminController
{
    private const SOURCE = 'matterhorn';
    private const AJAX_TIME_LIMIT_SECONDS = 20;
    private const MAX_AJAX_BATCH = 1000;

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(
        RunRepository $runs,
        ImportStatusProvider $status,
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
        $activePublic = $active === null ? null : $status->present($active);
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
                ['success' => true, 'job' => $status->present($run)],
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
        AdminErrorReporter $errors
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

            return new JsonResponse(['success' => true, 'job' => $status->present($run)]);
        } catch (\InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $exception) {
            return $this->exceptionError('ajax-import-batch', $exception, $errors, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function status(
        Request $request,
        RunRepository $runs,
        ImportStatusProvider $status,
        AdminErrorReporter $errors
    ): JsonResponse {
        if (!$this->isValidAjaxPost($request)) {
            return $this->jsonError('Invalid security token.', Response::HTTP_BAD_REQUEST);
        }

        try {
            [$shopId] = $this->shopContext();
            $run = $runs->assertContext($this->positiveRunId($request), $shopId, self::SOURCE);

            return new JsonResponse(['success' => true, 'job' => $status->present($run)]);
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
        ImportLock $lock,
        AdminErrorReporter $errors
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
            } finally {
                $lock->release();
            }

            return new JsonResponse(['success' => true, 'job' => $status->present($run)]);
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
