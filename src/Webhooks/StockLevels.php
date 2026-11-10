<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Webhooks;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Extracts `sku => available` from stock event payloads. Pure PHP — unit tested.
 */
final class StockLevels
{
    /**
     * Supported shapes: `{product: {sku, …}, stock: {on_hand, reserved, available}}`
     * (what SkladX sends with `stock.changed`), `{product: {sku, stock: {available}}}`
     * (`product.*`), `{sku, available}`, `{stock: {sku, available}}` and
     * `{stock: [{sku, available}, …]}`.
     *
     * @param array<string, mixed> $data event data
     *
     * @return array<string, int>
     */
    public static function fromEvent(array $data): array
    {
        $candidates = [];

        if (is_array($data['product'] ?? null)) {
            $stock = is_array($data['stock'] ?? null) && !array_is_list($data['stock']) && !isset($data['stock']['sku']) ? $data['stock'] : [];

            $candidates[] = [
                'sku' => $data['product']['sku'] ?? null,
                'available' => $data['product']['stock']['available'] ?? ($stock['available'] ?? null),
            ];

            if ($stock !== []) {
                unset($data['stock']);
            }
        }

        if (isset($data['sku'])) {
            $candidates[] = [
                'sku' => $data['sku'],
                'available' => $data['available'] ?? ($data['stock']['available'] ?? null),
            ];
        } elseif (is_array($data['stock'] ?? null)) {
            $rows = array_is_list($data['stock']) ? $data['stock'] : [$data['stock']];

            foreach ($rows as $row) {
                if (is_array($row)) {
                    $candidates[] = [
                        'sku' => $row['sku'] ?? null,
                        'available' => $row['available'] ?? null,
                    ];
                }
            }
        }

        $levels = [];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate['sku']) && (string) $candidate['sku'] !== '' && is_numeric($candidate['available'])) {
                $levels[(string) $candidate['sku']] = (int) $candidate['available'];
            }
        }

        return $levels;
    }

    /**
     * Level to set in PrestaShop: SkladX `available` minus units of shop
     * orders SkladX does not have yet (they are not reserved there), never
     * below zero.
     */
    public static function shopQuantity(int $skladxAvailable, int $held): int
    {
        return max(0, $skladxAvailable - max(0, $held));
    }

    /**
     * Event ids are time-ordered (ULID): is $candidate older than the last
     * event applied?
     */
    public static function isStale(string $candidate, string $lastApplied): bool
    {
        return $candidate !== '' && $lastApplied !== '' && strcmp($candidate, $lastApplied) < 0;
    }
}
