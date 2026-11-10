<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/*
 * PSR-4 autoloader for SkladX\PrestaShop\ => src/. The module has no runtime
 * Composer dependencies, so no vendor/ directory is needed in production.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'SkladX\\PrestaShop\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
