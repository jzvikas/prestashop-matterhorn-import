<?php
namespace Lp\MatterhornImport\Form;

use PrestaShopBundle\Form\Admin\Type\SwitchType;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

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

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'form_theme' => '@PrestaShop/Admin/TwigTemplateForm/prestashop_ui_kit.html.twig',
        ]);
    }

    /** @return array<string,int> */
    private function categoryChoices(): array
    {
        $context = \Context::getContext();
        $shopId = (int) ($context->shop->id ?? 0);
        $langId = (int) ($context->language->id ?? 0);
        if ($shopId <= 0 || $langId <= 0) { return []; }

        $shop = \Shop::getShop($shopId);
        $rootId = is_array($shop) ? (int) ($shop['id_category'] ?? 0) : 0;
        if ($rootId <= 0) { $rootId = (int) \Configuration::get('PS_ROOT_CATEGORY', null, null, $shopId); }
        $homeId = (int) \Configuration::get('PS_HOME_CATEGORY', null, null, $shopId);
        if ($homeId <= 0) { $homeId = (int) \Configuration::get('PS_HOME_CATEGORY'); }

        $rows = \Db::getInstance()->executeS(sprintf(
            "SELECT leaf.id_category,GROUP_CONCAT(pl.name ORDER BY parent.nleft SEPARATOR ' > ') AS category_path FROM `%1\$scategory` leaf INNER JOIN `%1\$scategory_shop` cs ON cs.id_category=leaf.id_category AND cs.id_shop=%2\$d INNER JOIN `%1\$scategory` parent ON leaf.nleft BETWEEN parent.nleft AND parent.nright INNER JOIN `%1\$scategory_lang` pl ON pl.id_category=parent.id_category AND pl.id_lang=%3\$d AND pl.id_shop=%2\$d WHERE parent.id_category NOT IN (%4\$d,%5\$d) GROUP BY leaf.id_category ORDER BY category_path,leaf.id_category",
            _DB_PREFIX_, $shopId, $langId, max(0, $rootId), max(0, $homeId)
        ), true, false);
        if (!is_array($rows)) { return []; }

        $choices = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id_category'] ?? 0);
            $path = trim((string) ($row['category_path'] ?? ''));
            if ($id <= 0 || $path === '') { continue; }
            $choices[sprintf('%d - %s', $id, $path)] = $id;
        }
        return $choices;
    }
}
