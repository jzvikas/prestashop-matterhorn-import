<?php
if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_0_1_8($module): bool
{
    return \Configuration::deleteByName('MATTERHORNIMPORT_CATEGORY_AUTO_CREATE');
}
