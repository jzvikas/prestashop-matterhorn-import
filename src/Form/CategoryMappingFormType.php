<?php
namespace Lp\MatterhornImport\Form;

use Lp\MatterhornImport\Category\CategoryPathReader;
use PrestaShopBundle\Form\Admin\Type\SwitchType;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class CategoryMappingFormType extends TranslatorAwareType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('supplier_path', TextType::class, [
                'label' => $this->trans('Supplier category path', 'Modules.Matterhornimport.Admin'),
                'disabled' => true,
            ])
            ->add('id_category', ChoiceType::class, [
                'label' => $this->trans('PrestaShop category', 'Modules.Matterhornimport.Admin'),
                'choices' => $this->categoryChoices(),
                'required' => false,
                'placeholder' => $this->trans('Not mapped', 'Modules.Matterhornimport.Admin'),
            ])
            ->add('active', SwitchType::class, [
                'label' => $this->trans('Use this supplier category', 'Modules.Matterhornimport.Admin'),
                'required' => true,
            ]);
    }

    /** @return array<string,int> */
    private function categoryChoices(): array
    {
        $context = \Context::getContext();
        $shopId = (int) ($context->shop->id ?? 0);
        $langId = (int) ($context->language->id ?? 0);
        if ($shopId <= 0 || $langId <= 0) { return []; }

        $choices = [];
        foreach ((new CategoryPathReader())->paths($shopId, $langId) as $id => $path) {
            if ($id <= 0 || $path === '') { continue; }
            $choices[sprintf('%d - %s', $id, $path)] = $id;
        }
        ksort($choices, SORT_NATURAL | SORT_FLAG_CASE);
        return $choices;
    }
}
