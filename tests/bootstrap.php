<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

/*
 * Unit test bootstrap: the tested classes are pure PHP, so PrestaShop is not
 * loaded — only _PS_VERSION_ is defined so the files' direct-access guard
 * passes (T::__() then returns the English source strings).
 */
define('_PS_VERSION_', 'unit-test');

require dirname(__DIR__) . '/vendor/autoload.php';
