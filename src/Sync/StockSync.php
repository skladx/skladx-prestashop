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
use SkladX\PrestaShop\Queue\Queue;
use SkladX\PrestaShop\Queue\RetryLater;
use SkladX\PrestaShop\Support\Logger;
use SkladX\PrestaShop\Webhooks\StockLevels;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * SkladX → PrestaShop stock (when stock sync is on): SkladX is the source of
 * truth, the available quantity becomes SkladX `available` (on hand −
 * reserved) minus units of shop orders SkladX does not have yet.
 */
final class StockSync
{
    /** @var array<string, int>|null "id_product:id_product_attribute" => held units (per request) */
    private static ?array $held = null;

    public static function queuePull(): bool
    {
        if (!Settings::isConfigured() || !Settings::stockSyncEnabled()) {
            return false;
        }

        Queue::push(Queue::STOCK_PULL, 'first', ['cursor' => '']);

        return true;
    }

    /**
     * Queue handler: one page of GET /stock.
     *
     * @param array<string, mixed> $payload `{cursor}`
     *
     * @throws RetryLater
     */
    public static function pull(array $payload): void
    {
        if (!Settings::stockSyncEnabled()) {
            return;
        }

        $cursor = (string) ($payload['cursor'] ?? '');

        try {
            $page = Client::fromSettings()->stock($cursor !== '' ? $cursor : null);
        } catch (ApiException $exception) {
            Logger::error('Stock pull from SkladX failed: ' . $exception->summary());

            if ($exception->isRetryable()) {
                throw new RetryLater(300, $exception->summary());
            }

            return;
        }

        $changed = 0;

        foreach ($page['data'] as $row) {
            if (is_scalar($row['sku'] ?? null) && (string) $row['sku'] !== '' && is_numeric($row['available'] ?? null)) {
                $changed += self::apply((string) $row['sku'], (int) $row['available']) ? 1 : 0;
            }
        }

        $next = (string) ($page['meta']['next_cursor'] ?? '');

        if (!empty($page['meta']['has_more']) && $next !== '') {
            Queue::push(Queue::STOCK_PULL, $next, ['cursor' => $next]);
        }

        Logger::info(sprintf('Stock pull from SkladX: %d rows, %d products changed.', count($page['data']), $changed));
    }

    /**
     * Sets PrestaShop stock of a SKU from SkladX.
     *
     * Event ids are time-ordered (ULID): an event older than the last one
     * applied to the product (webhook and polling may deliver out of order)
     * is skipped, so an old level never overwrites a newer one.
     *
     * @return bool whether the stock changed
     */
    public static function apply(string $sku, int $available, string $eventId = ''): bool
    {
        $found = ProductRepository::findBySku($sku);

        if ($found === null) {
            return false;
        }

        [$idProduct, $idProductAttribute] = $found;
        $state = ProductRepository::get($idProduct, $idProductAttribute);

        if (StockLevels::isStale($eventId, $state['stock_event_id'])) {
            Logger::debug(sprintf('Stale stock event %s for %s ignored (last applied %s).', $eventId, $sku, $state['stock_event_id']));

            return false;
        }

        if ($eventId !== '') {
            ProductRepository::save($idProduct, $idProductAttribute, ['stock_event_id' => $eventId]);
        }

        $held = self::heldQuantity($idProduct, $idProductAttribute);
        $quantity = StockLevels::shopQuantity($available, $held);

        if ((int) \StockAvailable::getQuantityAvailableByProduct($idProduct, $idProductAttribute) === $quantity) {
            return false;
        }

        ProductSync::$applyingRemote = true;

        try {
            \StockAvailable::setQuantity($idProduct, $idProductAttribute, $quantity);
        } finally {
            ProductSync::$applyingRemote = false;
        }

        Logger::info(sprintf('Stock of %s set to %d from SkladX%s.', $sku, $quantity, $held > 0 ? sprintf(' (%d held by orders not in SkladX yet)', $held) : ''));

        return true;
    }

    /**
     * Units PrestaShop already took off stock for orders SkladX does not
     * have yet (e.g. bank wire orders awaiting payment, or orders still in
     * the export queue). SkladX `available` does not include them, so they
     * are subtracted — otherwise taking over the SkladX level would put them
     * back on sale.
     */
    public static function heldQuantity(int $idProduct, int $idProductAttribute): int
    {
        if (self::$held === null) {
            self::$held = [];
            $excluded = array_filter(array_map(static fn (string $key): int => (int) \Configuration::get($key), ['PS_OS_CANCELED', 'PS_OS_ERROR', 'PS_OS_REFUND', 'PS_OS_SHIPPING', 'PS_OS_DELIVERED']));
            $rows = \Db::getInstance()->executeS(
                'SELECT od.`product_id`, od.`product_attribute_id`, SUM(od.`product_quantity` - od.`product_quantity_refunded`) AS `held`'
                . ' FROM `' . _DB_PREFIX_ . 'orders` o'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'order_detail` od ON od.`id_order` = o.`id_order`'
                . ' LEFT JOIN `' . _DB_PREFIX_ . OrderRepository::TABLE . '` so ON so.`id_order` = o.`id_order`'
                . ' WHERE (so.`skladx_id` IS NULL OR so.`skladx_id` = \'\')'
                . ' AND o.`date_add` > \'' . date('Y-m-d H:i:s', time() - 90 * 86400) . '\''
                . ($excluded !== [] ? ' AND o.`current_state` NOT IN (' . implode(',', $excluded) . ')' : '')
                . ' GROUP BY od.`product_id`, od.`product_attribute_id`',
                true,
                false
            );

            foreach (is_array($rows) ? $rows : [] as $row) {
                self::$held[(int) $row['product_id'] . ':' . (int) $row['product_attribute_id']] = max(0, (int) $row['held']);
            }
        }

        return self::$held[$idProduct . ':' . $idProductAttribute] ?? 0;
    }
}
