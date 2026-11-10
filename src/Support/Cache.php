<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Support;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Small key/value cache with expiry (DbCache in the shop, an array in tests).
 */
interface Cache
{
    /**
     * @return mixed|null null when missing or expired
     */
    public function get(string $key);

    /**
     * @param mixed $value JSON-serialisable value
     */
    public function set(string $key, $value, int $ttl): void;
}
