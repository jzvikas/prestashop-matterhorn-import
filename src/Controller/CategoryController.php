<?php
namespace Lp\MatterhornImport\Controller;

use Lp\MatterhornImport\Category\CategoryCatalogSynchronizer;
use Lp\MatterhornImport\Category\CategoryMappingManager;
use Lp\MatterhornImport\Form\CategoryMappingFormType;
use Lp\MatterhornImport\Repository\CategoryMappingRepository;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CategoryController extends PrestaShopAdminController
{
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(CategoryMappingRepository $repository): Response
    {
        [$shopId, $langId] = $this->shopContext();
        $context = \Context::getContext();

        return $this->render('@Modules/matterhornimport/views/templates/admin/category/index.html.twig', [
            'categories' => $repository->findAll($shopId, $langId),
            'totalCategories' => $repository->countAll($shopId),
            'unmappedCategories' => $repository->countUnmapped($shopId),
            'shopId' => $shopId,
            'shopName' => (string) ($context->shop->name ?? ('#' . $shopId)),
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function synchronize(
        Request $request,
        CategoryCatalogSynchronizer $synchronizer
    ): RedirectResponse {
        $this->assertPostToken($request, 'matterhorn_category_sync');
        [$shopId] = $this->shopContext();
        try {
            $result = $synchronizer->synchronize($shopId);
            $this->addFlash('success', $this->trans(
                'Category catalog synchronized: %categories% unique categories from %rows% XML products.',
                ['%categories%' => $result['categories'], '%rows%' => $result['scanned']],
                'Modules.Matterhornimport.Admin'
            ));
        } catch (\Throwable $e) {
            $this->reportFailure('category-sync', $e);
        }
        return $this->redirectToRoute('matterhorn_import_categories');
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function autoMap(
        Request $request,
        CategoryMappingManager $manager
    ): RedirectResponse {
        $this->assertPostToken($request, 'matterhorn_category_auto_map');
        [$shopId] = $this->shopContext();
        try {
            $result = $manager->autoMapExisting($shopId);
            $this->addFlash('success', $this->trans(
                'Mapped %mapped% categories to existing exact PrestaShop paths. Disabled categories skipped: %skipped%.',
                ['%mapped%' => $result['mapped'], '%skipped%' => $result['skipped_disabled']],
                'Modules.Matterhornimport.Admin'
            ));
        } catch (\Throwable $e) {
            $this->reportFailure('category-auto-map', $e);
        }
        return $this->redirectToRoute('matterhorn_import_categories');
    }

    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))")]
    public function autoCreate(
        Request $request,
        CategoryMappingManager $manager
    ): RedirectResponse {
        $this->assertPostToken($request, 'matterhorn_category_auto_create');
        [$shopId] = $this->shopContext();
        try {
            $result = $manager->createAndMapMissing($shopId);
            $this->addFlash('success', $this->trans(
                'Created/mapped %mapped% missing category paths. Disabled categories skipped: %skipped%.',
                ['%mapped%' => $result['mapped'], '%skipped%' => $result['skipped_disabled']],
                'Modules.Matterhornimport.Admin'
            ));
        } catch (\Throwable $e) {
            $this->reportFailure('category-auto-create', $e);
        }
        return $this->redirectToRoute('matterhorn_import_categories');
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function edit(
        string $supplierKey,
        Request $request,
        CategoryMappingRepository $repository
    ): Response {
        [$shopId] = $this->shopContext();
        $row = $repository->findOne($shopId, $supplierKey);
        if ($row === null) {
            $this->addFlash('error', $this->trans('Supplier category was not found.', [], 'Admin.Notifications.Error'));
            return $this->redirectToRoute('matterhorn_import_categories');
        }

        $path = trim((string) ($row['supplier_path'] ?? ''));
        if ($path === '') { $path = trim((string) ($row['supplier_name'] ?? $supplierKey)); }
        $form = $this->createForm(CategoryMappingFormType::class, [
            'supplier_path' => $path,
            'id_category' => !empty($row['id_category']) ? (int) $row['id_category'] : null,
            'active' => (bool) ($row['active'] ?? false),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $categoryId = !empty($data['id_category']) ? (int) $data['id_category'] : null;
            try {
                $repository->updateMapping($shopId, $supplierKey, $categoryId, !empty($data['active']));
                $this->addFlash('success', $this->trans('Category mapping saved.', [], 'Admin.Notifications.Success'));
                return $this->redirectToRoute('matterhorn_import_categories');
            } catch (\Throwable $e) {
                $this->reportFailure('category-edit', $e);
            }
        }

        return $this->render('@Modules/matterhornimport/views/templates/admin/category/edit.html.twig', [
            'categoryForm' => $form->createView(),
            'supplierKey' => $supplierKey,
        ]);
    }

    /** @return array{0:int,1:int} */
    private function shopContext(): array
    {
        if (\Shop::getContext() !== \Shop::CONTEXT_SHOP) {
            throw new \RuntimeException('Select one concrete shop before managing Matterhorn category mappings.');
        }
        $context = \Context::getContext();
        $shopId = (int) ($context->shop->id ?? 0);
        $langId = (int) ($context->language->id ?? 0);
        if ($shopId <= 0 || $langId <= 0) {
            throw new \RuntimeException('Could not resolve active shop/language for Matterhorn category mapping.');
        }
        return [$shopId, $langId];
    }

    private function assertPostToken(Request $request, string $tokenId): void
    {
        if (!$request->isMethod('POST') || !$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw new \RuntimeException('Invalid security token.');
        }
    }

    private function reportFailure(string $operation, \Throwable $e): void
    {
        $reference = strtoupper(substr(hash('sha256', $operation . '|' . microtime(true) . '|' . $e->getMessage()), 0, 12));
        \PrestaShopLogger::addLog(
            sprintf('[MatterhornImport][%s][%s] %s', $operation, $reference, $e->getMessage()),
            3
        );
        $this->addFlash('error', $this->trans(
            'Operation failed. Reference: %reference%',
            ['%reference%' => $reference],
            'Modules.Matterhornimport.Admin'
        ));
    }
}
