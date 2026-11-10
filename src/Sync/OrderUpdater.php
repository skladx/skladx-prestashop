<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Sync;

use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Support\Logger;
use SkladX\PrestaShop\Support\T;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The single path for "SkladX says the order now looks like this" — used by
 * webhooks, polling, the export response and the admin Refresh/Cancel
 * buttons: stores the SkladX data, adds a message for a new status, applies
 * the status mapping and passes a new tracking number on to the customer.
 */
final class OrderUpdater
{
    /** Set while the module changes an order state (prevents re-export loops). */
    public static bool $applying = false;

    /**
     * @param array<string, mixed> $remote SkladX order
     * @param bool $statusNote add the "order status is now …" message
     *
     * @return bool whether the SkladX status changed
     */
    public static function apply(\Order $order, array $remote, bool $statusNote = true): bool
    {
        $idOrder = (int) $order->id;
        $previous = OrderRepository::get($idOrder) ?? [];
        $previousStatus = (string) ($previous['status'] ?? '');
        $previousTracking = (string) ($previous['tracking_number'] ?? '');
        $status = (string) ($remote['status'] ?? '');
        $label = (string) ($remote['status_label'] ?? $status);
        $changed = $status !== '' && $status !== $previousStatus;

        OrderRepository::save($idOrder, self::values($remote));

        if ($changed) {
            if ($statusNote) {
                OrderNotes::add($order, T::f('order status is now "%1$s" (%2$s).', $label, $status));
            }

            self::applyStatusMapping($order, $status);
        }

        $row = OrderRepository::get($idOrder);
        $tracking = Tracking::forRow($row);

        if ($tracking !== null && $tracking['number'] !== $previousTracking) {
            OrderNotes::add($order, T::f('tracking number: %1$s %2$s', $tracking['number'], $tracking['url']));
            Tracking::applyToOrder(new \Order($idOrder), $tracking['number']);
        }

        return $changed;
    }

    /**
     * Columns of skladx_order from a SkladX order (as returned by the API or
     * inside an event).
     *
     * @param array<string, mixed> $remote
     *
     * @return array<string, mixed>
     */
    public static function values(array $remote): array
    {
        $shipping = is_array($remote['shipping'] ?? null) ? $remote['shipping'] : [];
        $values = [];

        foreach ([
            'skladx_id' => $remote['id'] ?? null,
            'skladx_number' => $remote['number'] ?? null,
            'status' => $remote['status'] ?? null,
            'status_label' => $remote['status_label'] ?? null,
            'status_changed_at' => $remote['status_changed_at'] ?? null,
            'tracking_number' => $shipping['tracking_number'] ?? null,
            'tracking_url' => $shipping['tracking_url'] ?? null,
            'carrier' => $shipping['carrier'] ?? ($remote['carrier'] ?? null),
        ] as $column => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $values[$column] = (string) $value;
            }
        }

        if (is_array($remote['shipping_address'] ?? null)) {
            $values['address_hash'] = OrderPayloadBuilder::addressFingerprint($remote['shipping_address']);
        }

        if (array_key_exists('test', $remote)) {
            $values['is_test'] = (bool) $remote['test'];
        }

        $values['last_sync'] = time();
        $values['export_state'] = 'exported';
        $values['export_error'] = null;

        return $values;
    }

    private static function applyStatusMapping(\Order $order, string $status): void
    {
        $mapper = new StatusMapper(Settings::statusMap());
        $history = array_map(static fn (array $row): int => (int) $row['id_order_state'], (array) $order->getHistory((int) $order->id_lang));

        if (!$mapper->needsChange($status, (int) $order->getCurrentState(), $history)) {
            return;
        }

        $target = (int) $mapper->toPrestaShop($status);
        $state = new \OrderState($target);

        if (!\Validate::isLoadedObject($state)) {
            return;
        }

        self::$applying = true;

        try {
            $orderHistory = new \OrderHistory();
            $orderHistory->id_order = (int) $order->id;
            $orderHistory->id_employee = 0;
            $orderHistory->changeIdOrderState($target, $order, true);
            $orderHistory->addWithemail(true);
        } catch (\Throwable $exception) {
            Logger::error(sprintf('Changing the state of order #%d to %d failed: %s (%s:%d)', (int) $order->id, $target, $exception->getMessage(), $exception->getFile(), $exception->getLine()), ['trace' => array_slice(explode("\n", $exception->getTraceAsString()), 0, 12)]);
        } finally {
            self::$applying = false;
        }
    }
}
