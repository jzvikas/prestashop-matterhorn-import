<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($composerAutoload)) {
    throw new RuntimeException('Matterhorn Import requires vendor/autoload.php. Build the production module with Composer before deployment.');
}
require_once $composerAutoload;

use Lp\MatterhornImport\Installer;

class MatterhornImport extends Module
{
    public function __construct()
    {
        $this->name = 'matterhornimport';
        $this->tab = 'administration';
        $this->version = '0.1.8';
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
        return parent::install() && (new Installer())->install();
    }

    public function uninstall(): bool
    {
        return (new Installer())->uninstall() && parent::uninstall();
    }

    public function getContent(): string
    {
        $route = $this->get('router')->generate('matterhorn_import_configuration');
        Tools::redirectAdmin($route);

        return '';
    }
}
