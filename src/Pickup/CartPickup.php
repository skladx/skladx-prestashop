<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Pickup;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Pickup point chosen at checkout, per cart (`skladx_cart_pickup`).
 */
final class CartPickup
{
    public const TABLE = 'skladx_cart_pickup';

    /**
     * @return array<string, mixed>|null normalised point
     */
    public static function get(int $idCart): ?array
    {
        $point = \Db::getInstance()->getValue('SELECT `point` FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_cart` = ' . $idCart, false);
        $decoded = is_string($point) ? json_decode($point, true) : null;

        return is_array($decoded) && isset($decoded['id'], $decoded['carrier']) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $point normalised point
     */
    public static function save(int $idCart, array $point): void
    {
        \Db::getInstance()->execute(
            'REPLACE INTO `' . _DB_PREFIX_ . self::TABLE . '` (`id_cart`, `carrier`, `point_id`, `point`, `date_upd`) VALUES (' . $idCart . ', \''
            . pSQL((string) $point['carrier']) . '\', \'' . pSQL((string) $point['id']) . '\', \''
            . pSQL((string) json_encode($point, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true) . '\', \'' . date('Y-m-d H:i:s') . '\')'
        );
    }

    public static function clear(int $idCart): void
    {
        \Db::getInstance()->delete(self::TABLE, '`id_cart` = ' . $idCart);
    }

    /**
     * Removes choices of carts older than 60 days.
     */
    public static function prune(): void
    {
        \Db::getInstance()->delete(self::TABLE, '`date_upd` < \'' . date('Y-m-d H:i:s', time() - 60 * 86400) . '\'');
    }
}
