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
 * SkladX data stored per order in `skladx_order`.
 */
final class OrderRepository
{
    public const TABLE = 'skladx_order';

    private const COLUMNS = [
        'skladx_id', 'skladx_number', 'status', 'status_label', 'status_changed_at', 'tracking_number', 'tracking_url', 'carrier',
        'is_test', 'export_state', 'export_error', 'export_revision', 'address_hash', 'pickup_carrier', 'pickup_point_id', 'pickup_point', 'last_sync',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public static function get(int $idOrder): ?array
    {
        $row = \Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_order` = ' . $idOrder, false);

        if (!is_array($row)) {
            return null;
        }

        $point = json_decode((string) ($row['pickup_point'] ?? ''), true);
        $row['pickup_point'] = is_array($point) ? $point : null;
        $row['id_order'] = (int) $row['id_order'];
        $row['export_revision'] = (int) $row['export_revision'];
        $row['last_sync'] = (int) $row['last_sync'];
        $row['is_test'] = (bool) $row['is_test'];

        return $row;
    }

    public static function isExported(int $idOrder): bool
    {
        return (string) (self::get($idOrder)['skladx_id'] ?? '') !== '';
    }

    public static function findBySkladxId(string $skladxId): int
    {
        if ($skladxId === '') {
            return 0;
        }

        return (int) \Db::getInstance()->getValue('SELECT `id_order` FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `skladx_id` = \'' . pSQL($skladxId) . '\'', false);
    }

    /**
     * Inserts or updates the row of an order (only the given columns).
     *
     * @param array<string, mixed> $values column => value (pickup_point as array)
     */
    public static function save(int $idOrder, array $values): void
    {
        $db = \Db::getInstance();
        $data = [];

        foreach ($values as $column => $value) {
            if (!in_array($column, self::COLUMNS, true)) {
                continue;
            }

            if ($column === 'pickup_point') {
                $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            } elseif (is_bool($value)) {
                $value = $value ? 1 : 0;
            }

            $data[$column] = $value === null ? null : pSQL((string) $value, true);
        }

        $now = date('Y-m-d H:i:s');
        $data['date_upd'] = $now;

        if (self::get($idOrder) === null) {
            $data['id_order'] = $idOrder;
            $data['date_add'] = $now;
            $db->insert(self::TABLE, $data, true);

            return;
        }

        $db->update(self::TABLE, $data, '`id_order` = ' . $idOrder, 0, true);
    }

    /**
     * Ids of orders created after $since that have no SkladX order yet.
     *
     * @return list<int>
     */
    public static function exportedOrderIds(array $orderIds): array
    {
        $orderIds = array_values(array_filter(array_map('intval', $orderIds)));

        if ($orderIds === []) {
            return [];
        }

        $rows = \Db::getInstance()->executeS('SELECT `id_order` FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `skladx_id` <> \'\' AND `id_order` IN (' . implode(',', $orderIds) . ')', true, false);

        return array_map(static fn (array $row): int => (int) $row['id_order'], is_array($rows) ? $rows : []);
    }
}
