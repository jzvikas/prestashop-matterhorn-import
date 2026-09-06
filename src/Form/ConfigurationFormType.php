<?php
namespace Lp\MatterhornImport\Form;

use Lp\MatterhornImport\Config\OperationalSettings;
use PrestaShopBundle\Form\Admin\Type\SwitchType;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

final class ConfigurationFormType extends TranslatorAwareType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(ConfigurationFormDataProvider::SOURCE_LOCATION, TextType::class, [
                'label' => $this->trans('Source XML location', 'Modules.Matterhornimport.Admin'),
                'help' => $this->trans('Use an absolute local file path or an HTTP/HTTPS supplier feed URL.', 'Modules.Matterhornimport.Admin'),
                'constraints' => [new NotBlank(), new Length(max: 4096)],
            ])
            ->add(ConfigurationFormDataProvider::SOURCE_LANGUAGE_ID, ChoiceType::class, [
                'label' => $this->trans('Supplier/source language', 'Modules.Matterhornimport.Admin'),
                'help' => $this->trans('CREATE fills required shop languages from this supplier value as fallback; UPDATE changes only this supplier-owned language.', 'Modules.Matterhornimport.Admin'),
                'choices' => $this->languageChoices(),
            ])
            ->add(ConfigurationFormDataProvider::FEATURE_AUTO_CREATE, SwitchType::class, [
                'label' => $this->trans('Auto-create Color/Type features', 'Modules.Matterhornimport.Admin'),
            ])
            ->add(ConfigurationFormDataProvider::SIZE_ATTRIBUTE_GROUP, TextType::class, [
                'label' => $this->trans('Size attribute group', 'Modules.Matterhornimport.Admin'),
                'constraints' => [new NotBlank(), new Length(max: 64)],
            ])
            ->add(ConfigurationFormDataProvider::MAX_REMOVE_PERCENT, IntegerType::class, [
                'label' => $this->trans('Maximum REMOVE percentage', 'Modules.Matterhornimport.Admin'),
                'help' => $this->trans('REMOVE is blocked when missing feed products exceed this percentage of currently in-feed mapped products.', 'Modules.Matterhornimport.Admin'),
                'constraints' => [new Range(min: 1, max: 100)],
                'attr' => ['min' => 1, 'max' => 100],
            ])
            ->add(OperationalSettings::BATCH_SIZE, IntegerType::class, $this->integerOptions('Stage batch size', 1, 10000))
            ->add(OperationalSettings::MAX_ITEMS, IntegerType::class, $this->integerOptions('Maximum items per invocation (0 = unlimited)', 0, 1000000000))
            ->add(OperationalSettings::TIME_LIMIT, IntegerType::class, $this->integerOptions('Soft runtime limit seconds (0 = unlimited)', 0, 86400))
            ->add(OperationalSettings::IMAGE_WORKER_LIMIT, IntegerType::class, $this->integerOptions('Image jobs per tick', 1, 500))
            ->add(OperationalSettings::IMAGE_WORKER_RUNTIME, IntegerType::class, $this->integerOptions('Image worker runtime seconds (0 = one tick)', 0, 86400))
            ->add(OperationalSettings::NEW_PRODUCT_WORKER_LIMIT, IntegerType::class, $this->integerOptions('New-product jobs per tick', 1, 200))
            ->add(OperationalSettings::NEW_PRODUCT_WORKER_RUNTIME, IntegerType::class, $this->integerOptions('New-product worker runtime seconds (0 = one tick)', 0, 86400))
            ->add(OperationalSettings::RETRY_LIMIT, IntegerType::class, $this->integerOptions('Retry reset limit per domain', 1, 100000));
    }

    /** @return array<string,int> */
    private function languageChoices(): array
    {
        $shopId = (int) (\Context::getContext()->shop->id ?? 0);
        if ($shopId <= 0) {
            return [];
        }

        $choices = [];
        foreach (\Language::getLanguages(false, $shopId) as $language) {
            $id = (int) ($language['id_lang'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $label = trim((string) ($language['name'] ?? $language['iso_code'] ?? ('Language #' . $id)));
            $choices[$label !== '' ? $label : ('Language #' . $id)] = $id;
        }

        return $choices;
    }

    /** @return array<string,mixed> */
    private function integerOptions(string $label, int $min, int $max): array
    {
        return [
            'label' => $this->trans($label, 'Modules.Matterhornimport.Admin'),
            'constraints' => [new Range(min: $min, max: $max)],
            'attr' => ['min' => $min, 'max' => $max],
        ];
    }
}
