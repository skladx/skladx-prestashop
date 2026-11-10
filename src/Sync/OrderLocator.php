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
 * Finds the PrestaShop order an incoming SkladX order belongs to: by our
 * metadata (ps_order_id + site), then by the stored `ord_…` id, then by the
 * reference (order reference, or `reference-id` for split carts).
 */
final class OrderLocator
{
    /**
     * @param array<string, mixed> $remote SkladX order
     */
    public static function find(array $remote, string $site): ?\Order
    {
        $metadata = is_array($remote['metadata'] ?? null) ? $remote['metadata'] : [];
        $remoteId = (string) ($remote['id'] ?? '');

        if (!empty($metadata['ps_order_id']) && (empty($metadata['site']) || (string) $metadata['site'] === $site)) {
            $order = new \Order((int) $metadata['ps_order_id']);

            if (\Validate::isLoadedObject($order) && self::matches($order, $remoteId)) {
                return $order;
            }
        }

        $idOrder = OrderRepository::findBySkladxId($remoteId);

        if ($idOrder > 0) {
            $order = new \Order($idOrder);

            if (\Validate::isLoadedObject($order)) {
                return $order;
            }
        }

        $reference = trim((string) ($remote['reference'] ?? ''));

        if ($reference === '') {
            return null;
        }

        if (preg_match('/^([A-Z0-9]+)-(\d+)$/', $reference, $match)) {
            $order = new \Order((int) $match[2]);

            if (\Validate::isLoadedObject($order) && (string) $order->reference === $match[1] && self::matches($order, $remoteId)) {
                return $order;
            }
        }

        $ids = \Db::getInstance()->executeS('SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `reference` = \'' . pSQL($reference) . '\' LIMIT 2', true, false);

        if (is_array($ids) && count($ids) === 1) {
            $order = new \Order((int) $ids[0]['id_order']);

            if (\Validate::isLoadedObject($order) && self::matches($order, $remoteId)) {
                return $order;
            }
        }

        return null;
    }

    /**
     * An order already linked to another SkladX order is not a match.
     */
    private static function matches(\Order $order, string $remoteId): bool
    {
        $linked = (string) (OrderRepository::get((int) $order->id)['skladx_id'] ?? '');

        return $linked === '' || $remoteId === '' || $linked === $remoteId;
    }
}
