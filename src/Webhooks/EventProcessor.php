<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Webhooks;

use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Inbound\InboundEvents;
use SkladX\PrestaShop\Inbound\Receipts;
use SkladX\PrestaShop\Queue\Queue;
use SkladX\PrestaShop\Support\Logger;
use SkladX\PrestaShop\Sync\OrderLocator;
use SkladX\PrestaShop\Sync\OrderRepository;
use SkladX\PrestaShop\Sync\OrderUpdater;
use SkladX\PrestaShop\Sync\StockSync;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Applies stored SkladX events to PrestaShop (queue job `event`).
 *
 * SkladX emits `order.status_changed` and a specific event (e.g.
 * `order.shipped`) for the same transition; both carry the full order, so
 * whichever arrives first applies it and the second is a no-op (same
 * status, same tracking). Events older than what the order already shows
 * are ignored.
 */
final class EventProcessor
{
    /**
     * Stores a received event once and queues it. Returns false for a duplicate.
     *
     * @param array<string, mixed> $envelope
     */
    public static function receive(string $eventId, string $type, array $envelope, string $source): bool
    {
        if (!EventStore::insert($eventId, $type, $envelope, $source)) {
            return false;
        }

        if ($type === 'ping') {
            EventStore::mark($eventId, 'processed');
        } else {
            Queue::push(Queue::EVENT, $eventId);
        }

        return true;
    }

    /**
     * Queue handler.
     */
    public static function process(string $eventId): void
    {
        $event = EventStore::find($eventId);

        if ($event === null || in_array($event['status'], ['processed', 'ignored'], true)) {
            return;
        }

        try {
            $data = is_array($event['envelope']['data'] ?? null) ? $event['envelope']['data'] : [];
            EventStore::mark($eventId, self::dispatch($event['type'], $data, $eventId));
        } catch (\Throwable $exception) {
            EventStore::mark($eventId, 'failed', $exception->getMessage());
            Logger::error(sprintf('Processing event %s (%s) failed: %s', $eventId, $event['type'], $exception->getMessage()));
        }
    }

    /**
     * @param array<string, mixed> $data event data
     *
     * @return string processed|ignored
     */
    public static function dispatch(string $type, array $data, string $eventId = ''): string
    {
        if (str_starts_with($type, 'order.') || $type === 'shipment.tracking_updated') {
            return is_array($data['order'] ?? null) ? self::applyOrder($data['order'], $type) : 'ignored';
        }

        if (in_array($type, ['stock.changed', 'product.created', 'product.updated'], true)) {
            return self::applyStock($data, $eventId);
        }

        if (InboundEvents::handles($type)) {
            return Receipts::handleEvent($type, $data);
        }

        return 'ignored';
    }

    /**
     * @param array<string, mixed> $remote SkladX order
     */
    private static function applyOrder(array $remote, string $type): string
    {
        $order = OrderLocator::find($remote, Settings::siteHost());

        if ($order === null) {
            Logger::debug(sprintf('Event %s for unknown order %s (%s) ignored.', $type, (string) ($remote['id'] ?? '?'), (string) ($remote['reference'] ?? '?')));

            return 'ignored';
        }

        $known = (string) (OrderRepository::get((int) $order->id)['status_changed_at'] ?? '');
        $incoming = (string) ($remote['status_changed_at'] ?? '');

        if ($known !== '' && $incoming !== '' && strtotime($incoming) < strtotime($known)) {
            Logger::debug(sprintf('Stale event %s for order #%d ignored.', $type, (int) $order->id));

            return 'ignored';
        }

        OrderUpdater::apply($order, $remote);
        Logger::info(sprintf('Order #%d updated from %s: %s.', (int) $order->id, $type, (string) ($remote['status'] ?? '')));

        return 'processed';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function applyStock(array $data, string $eventId): string
    {
        if (!Settings::stockSyncEnabled()) {
            return 'ignored';
        }

        $changed = false;

        foreach (StockLevels::fromEvent($data) as $sku => $available) {
            $changed = StockSync::apply((string) $sku, $available, $eventId) || $changed;
        }

        return $changed ? 'processed' : 'ignored';
    }
}
