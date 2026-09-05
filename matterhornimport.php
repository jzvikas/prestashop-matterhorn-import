<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/autoload.php';
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

class MatterhornImport extends Module
{
    public function __construct()
    {
        $this->name = 'matterhornimport';
        $this->tab = 'administration';
        $this->version = '0.1.0';
        $this->author = 'LP';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->trans('Matterhorn Wholesale Import', [], 'Modules.Matterhornimport.Admin');
        $this->description = $this->trans('High-throughput Matterhorn Wholesale supplier import for PrestaShop 9.1.x.', [], 'Modules.Matterhornimport.Admin');
        $this->ps_versions_compliancy = ['min' => '9.1.0', 'max' => '9.1.99'];
    }

    public function install(): bool
    {
        return parent::install();
    }

    public function getContent(): string
    {
        $shopId = (int) ($this->context->shop->id ?? 0);
        $shopGroupId = (int) ($this->context->shop->id_shop_group ?? 0);
        if ($shopId <= 0 || $shopGroupId <= 0) {
            return $this->displayError($this->trans(
                'Select one concrete shop before configuring Matterhorn Import.',
                [],
                'Modules.Matterhornimport.Admin'
            ));
        }

        $output = '';
        if (\Tools::isSubmit('submitMatterhornImport')) {
            $source = trim((string) \Tools::getValue('MATTERHORNIMPORT_SOURCE_FILE', ''));
            $sizeGroup = trim((string) \Tools::getValue('MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME', 'Size'));
            if ($source !== '' && (!is_file($source) || !is_readable($source))) {
                $output .= $this->displayError($this->trans('Source XML file is not readable.', [], 'Modules.Matterhornimport.Admin'));
            } elseif ($sizeGroup === '') {
                $output .= $this->displayError($this->trans('Size attribute group name cannot be empty.', [], 'Modules.Matterhornimport.Admin'));
            } else {
                $ok = \Configuration::updateValue('MATTERHORNIMPORT_SOURCE_FILE', $source, false, $shopGroupId, $shopId);
                $ok = \Configuration::updateValue('MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME', $sizeGroup, false, $shopGroupId, $shopId) && $ok;
                $output .= $ok
                    ? $this->displayConfirmation($this->trans('Matterhorn settings saved.', [], 'Modules.Matterhornimport.Admin'))
                    : $this->displayError($this->trans('Could not save Matterhorn settings.', [], 'Modules.Matterhornimport.Admin'));
            }
        }

        $source = (string) \Configuration::get('MATTERHORNIMPORT_SOURCE_FILE', null, $shopGroupId, $shopId);
        $sizeGroup = (string) \Configuration::get('MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME', null, $shopGroupId, $shopId);
        if ($sizeGroup === '') {
            $sizeGroup = 'Size';
        }

        $output .= '<div class="panel"><h3>' . $this->trans('Matterhorn Wholesale Import', [], 'Modules.Matterhornimport.Admin') . '</h3>';
        $output .= '<form method="post">';
        $output .= '<div class="form-group"><label>Source XML file</label><input class="form-control" type="text" name="MATTERHORNIMPORT_SOURCE_FILE" value="' . htmlspecialchars($source, ENT_QUOTES, 'UTF-8') . '"></div>';
        $output .= '<div class="form-group"><label>Size attribute group</label><input class="form-control" type="text" name="MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME" value="' . htmlspecialchars($sizeGroup, ENT_QUOTES, 'UTF-8') . '"></div>';
        $output .= '<button class="btn btn-primary" type="submit" name="submitMatterhornImport">' . $this->trans('Save', [], 'Modules.Matterhornimport.Admin') . '</button>';
        $output .= '</form></div>';
        return $output;
    }
}
