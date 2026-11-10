<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Sync;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Stable id of a shop stock unit in SkladX (`external_id`):
 * `ps:{id_shop}:{id_product}:{id_product_attribute}` — id_product_attribute
 * is 0 for a product without combinations, every combination is its own
 * SkladX product. SkladX matches products by external_id first and by SKU
 * second, so a changed reference renames the SKU instead of creating a new
 * product. Pure PHP — unit tested.
 */
final class ExternalId
{
    public const PREFIX = 'ps';

    public static function forUnit(int $idShop, int $idProduct, int $idProductAttribute = 0): string
    {
        return sprintf('%s:%d:%d:%d', self::PREFIX, max(1, $idShop), max(0, $idProduct), max(0, $idProductAttribute));
    }

    /**
     * @return array{id_shop: int, id_product: int, id_product_attribute: int}|null null for an id of another system
     */
    public static function parse(string $externalId): ?array
    {
        if (!preg_match('/^ps:(\d+):(\d+):(\d+)$/', trim($externalId), $matches)) {
            return null;
        }

        return [
            'id_shop' => (int) $matches[1],
            'id_product' => (int) $matches[2],
            'id_product_attribute' => (int) $matches[3],
        ];
    }
}
