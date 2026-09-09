<?php
if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_1_0_11($module): bool
{
    $installer = new \Lp\MatterhornImport\Installer();
    if (!$installer->repairSchema()) {
        return false;
    }

    if (\Configuration::hasKey('MATTERHORNIMPORT_CATEGORY_AUTO_CREATE')) {
        return \Configuration::deleteByName('MATTERHORNIMPORT_CATEGORY_AUTO_CREATE');
    }

    return true;
}
