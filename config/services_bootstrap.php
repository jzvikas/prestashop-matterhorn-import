<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// PrestaShop can load a module's service configuration while building the global
// Symfony container before the module's main PHP class has been required. Register
// the module autoloaders here so service discovery can reflect module classes.
require_once dirname(__DIR__) . '/autoload.php';

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

return static function (ContainerConfigurator $container): void {
    // Autoloader bootstrap only. Service definitions remain in services.yml.
};
