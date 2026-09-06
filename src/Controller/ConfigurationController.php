<?php
namespace Lp\MatterhornImport\Controller;

use Lp\MatterhornImport\Admin\StatusProvider;
use PrestaShop\PrestaShop\Core\Form\FormHandlerInterface;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ConfigurationController extends PrestaShopAdminController
{
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(
        Request $request,
        StatusProvider $statusProvider,
        #[Autowire(service: 'matterhornimport.form.configuration_handler')]
        FormHandlerInterface $formHandler
    ): Response {
        $shopId = (int) (\Context::getContext()->shop->id ?? 0);
        $shopGroupId = (int) (\Context::getContext()->shop->id_shop_group ?? 0);
        if ($shopId <= 0 || $shopGroupId <= 0) {
            $this->addFlash('error', $this->trans(
                'Select one concrete shop before configuring Matterhorn Import.',
                [],
                'Modules.Matterhornimport.Admin'
            ));

            return $this->redirectToRoute('admin_module_manage');
        }

        $form = $formHandler->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $errors = $formHandler->save($form->getData());
            if ($errors === []) {
                $this->addFlash('success', $this->trans(
                    'Matterhorn settings saved for this shop.',
                    [],
                    'Modules.Matterhornimport.Admin'
                ));

                return $this->redirectToRoute('matterhorn_import_configuration');
            }
            $this->addFlashErrors($errors);
        }

        return $this->render('@Modules/matterhornimport/views/templates/admin/configuration.html.twig', [
            'configurationForm' => $form->createView(),
            'matterhornStatus' => $statusProvider->forShop($shopId),
            'shopId' => $shopId,
        ]);
    }
}
