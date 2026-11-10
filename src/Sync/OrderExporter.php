<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Sync;

use SkladX\PrestaShop\Api\ApiException;
use SkladX\PrestaShop\Api\Client;
use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Queue\Backoff;
use SkladX\PrestaShop\Queue\Queue;
use SkladX\PrestaShop\Queue\RetryLater;
use SkladX\PrestaShop\Support\Logger;
use SkladX\PrestaShop\Support\Notices;
use SkladX\PrestaShop\Support\T;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Order state trigger → queue → `POST /orders`.
 *
 * The Idempotency-Key is `ps:<host>:order:<id>` (plus `:r<n>` after a
 * rejected attempt), so a retry after a timeout never creates a second
 * SkladX order. Network/5xx/429 errors are retried with backoff (1, 5, 15,
 * 60, 180, 360 minutes); validation errors (422) stop and show an admin notice.
 */
final class OrderExporter
{
    /**
     * Queues the export unless the order is already exported or queued.
     *
     * @return int queue job id, 0 when nothing was queued
     */
    public static function queue(int $idOrder): int
    {
        if ($idOrder <= 0 || OrderRepository::isExported($idOrder) || Queue::isQueued(Queue::ORDER_EXPORT, (string) $idOrder)) {
            return 0;
        }

        OrderRepository::save($idOrder, ['export_state' => 'queued']);

        return Queue::push(Queue::ORDER_EXPORT, (string) $idOrder);
    }

    /**
     * Queue handler.
     *
     * @param int $attempt 1 for the first attempt
     *
     * @throws RetryLater when the export should be retried
     */
    public static function export(int $idOrder, int $attempt = 1): void
    {
        $order = new \Order($idOrder);

        if (!\Validate::isLoadedObject($order) || OrderRepository::isExported($idOrder)) {
            return;
        }

        try {
            $payload = self::payload($order);
        } catch (MappingException $exception) {
            self::fail($order, $exception->localizedMessage(), false);

            return;
        }

        try {
            $remote = Client::fromSettings()->createOrder($payload, self::idempotencyKey($order));
            self::linked($order, $remote, T::__('Order sent to SkladX as %1$s (%2$s), status: %3$s.'));
        } catch (ApiException $exception) {
            if (self::linkExisting($order, $exception)) {
                return;
            }

            $delay = $exception->isRetryable() ? Backoff::delay($attempt, Backoff::ORDER_EXPORT) : null;

            if ($delay !== null) {
                OrderRepository::save($idOrder, ['export_state' => 'retrying', 'export_error' => $exception->summary()]);
                Logger::warning(sprintf('Order #%d export failed (attempt %d), retrying in %d s: %s', $idOrder, $attempt, $delay, $exception->summary()));

                throw new RetryLater($delay, $exception->summary());
            }

            self::fail($order, $exception->summary(), true);
        }
    }

    /**
     * Payload for an order (also shown as a preview in the SkladX box).
     *
     * @return array<string, mixed>
     *
     * @throws MappingException
     */
    public static function payload(\Order $order): array
    {
        $payload = OrderPayloadBuilder::build(OrderSnapshot::fromOrder($order), [
            'carrier_map' => Settings::carrierMap(),
            'cod_modules' => Settings::codModules(),
            'site' => Settings::siteHost(),
            'default_country' => Settings::shopCountryIso(),
        ]);

        // Lets other modules adjust the payload: hook actionSkladxOrderPayload (params: payload by reference, order).
        \Hook::exec('actionSkladxOrderPayload', ['payload' => &$payload, 'order' => $order]);

        return $payload;
    }

    public static function idempotencyKey(\Order $order): string
    {
        $revision = (int) (OrderRepository::get((int) $order->id)['export_revision'] ?? 0);

        return sprintf('ps:%s:order:%d', Settings::siteHost(), (int) $order->id) . ($revision > 0 ? ':r' . $revision : '');
    }

    /**
     * Manual "Send / Resend to SkladX" from the order page.
     */
    public static function resend(int $idOrder): int
    {
        if (OrderRepository::isExported($idOrder)) {
            return 0;
        }

        OrderRepository::save($idOrder, ['export_state' => 'queued', 'export_error' => null]);

        return Queue::push(Queue::ORDER_EXPORT, (string) $idOrder);
    }

    /**
     * When SkladX already has an order with this reference that belongs to
     * this PrestaShop order (e.g. the first response was lost), link it
     * instead of failing.
     */
    private static function linkExisting(\Order $order, ApiException $exception): bool
    {
        if ($exception->status !== 422 || !isset($exception->fieldErrors()['reference'])) {
            return false;
        }

        try {
            $remote = Client::fromSettings()->getOrder(OrderPayloadBuilder::reference(OrderSnapshot::fromOrder($order)));
        } catch (ApiException $lookup) {
            return false;
        }

        $metadata = is_array($remote['metadata'] ?? null) ? $remote['metadata'] : [];

        if ((int) ($metadata['ps_order_id'] ?? 0) !== (int) $order->id || (string) ($metadata['site'] ?? '') !== Settings::siteHost()) {
            return false;
        }

        self::linked($order, $remote, T::__('Order already existed in SkladX as %1$s (%2$s), status: %3$s — linked.'));

        return true;
    }

    /**
     * @param array<string, mixed> $remote SkladX order
     * @param string $note note format with number, id and status label
     */
    private static function linked(\Order $order, array $remote, string $note): void
    {
        OrderNotes::add($order, sprintf($note, (string) ($remote['number'] ?? '?'), (string) ($remote['id'] ?? '?'), (string) ($remote['status_label'] ?? ($remote['status'] ?? ''))));
        OrderUpdater::apply($order, $remote, false);

        Logger::info(sprintf('Order #%d exported as %s.', (int) $order->id, (string) ($remote['id'] ?? '?')));
    }

    private static function fail(\Order $order, string $message, bool $fromApi): void
    {
        $row = OrderRepository::get((int) $order->id);
        $values = ['export_state' => 'failed', 'export_error' => $message];

        if ($fromApi) {
            // The rejected request is stored under its Idempotency-Key; the next attempt needs a new one.
            $values['export_revision'] = (int) ($row['export_revision'] ?? 0) + 1;
        }

        OrderRepository::save((int) $order->id, $values);
        OrderNotes::add($order, T::f('Sending the order to SkladX failed: %s', $message));
        Logger::error(sprintf('Order #%d export failed: %s', (int) $order->id, $message));

        Notices::add(
            T::f('Order %1$s could not be sent to SkladX: %2$s', $order->reference . ' (#' . (int) $order->id . ')', $message),
            'danger',
            self::adminOrderUrl((int) $order->id),
            T::__('Open order')
        );
    }

    public static function adminOrderUrl(int $idOrder): string
    {
        try {
            return \Context::getContext()->link->getAdminLink('AdminOrders', true, ['route' => 'admin_orders_view', 'orderId' => $idOrder], ['id_order' => $idOrder, 'vieworder' => 1]);
        } catch (\Throwable $exception) {
            return '';
        }
    }
}
