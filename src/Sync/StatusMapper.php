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
 * SkladX order statuses and their mapping to PrestaShop order states.
 * Pure PHP — unit tested.
 */
final class StatusMapper
{
    /** Every SkladX order status, happy path first. */
    public const STATUSES = [
        'draft',
        'received',
        'on_hold',
        'awaiting_stock',
        'ready',
        'allocated',
        'picking',
        'picked',
        'packing',
        'packed',
        'label_created',
        'handed_over',
        'in_transit',
        'out_for_delivery',
        'delivered',
        'delivery_failed',
        'returning',
        'returned',
        'cancelled',
    ];

    /** Statuses in which the client may still cancel the order through the API. */
    public const CANCELLABLE = ['draft', 'received', 'on_hold', 'awaiting_stock', 'ready'];

    /**
     * @param array<string, int|string> $map SkladX status => PrestaShop id_order_state (0/'' = keep)
     */
    public function __construct(private readonly array $map)
    {
    }

    /**
     * PrestaShop order state the order should move to, or null to keep it.
     */
    public function toPrestaShop(string $skladxStatus): ?int
    {
        $target = (int) ($this->map[$skladxStatus] ?? 0);

        return $target > 0 ? $target : null;
    }

    /**
     * Whether the PrestaShop order needs a state change.
     *
     * @param list<int> $historyStates states the order already went through
     *                                 (a mapped state is not applied twice, e.g. when
     *                                 an admin moved the order back manually)
     */
    public function needsChange(string $skladxStatus, int $currentState, array $historyStates = []): bool
    {
        $target = $this->toPrestaShop($skladxStatus);

        return $target !== null && $target !== $currentState && !in_array($target, $historyStates, true);
    }

    public static function isCancellable(string $skladxStatus): bool
    {
        return in_array($skladxStatus, self::CANCELLABLE, true);
    }

    public static function isKnown(string $skladxStatus): bool
    {
        return in_array($skladxStatus, self::STATUSES, true);
    }

    /**
     * Normalises a submitted status map: only known statuses, positive state ids.
     *
     * @param array<mixed> $input raw input (status => state id)
     * @param list<int> $validStates existing PrestaShop state ids
     *
     * @return array<string, int>
     */
    public static function sanitizeMap(array $input, array $validStates): array
    {
        $map = [];

        foreach (self::STATUSES as $status) {
            $state = (int) ($input[$status] ?? 0);

            if ($state > 0 && in_array($state, $validStates, true)) {
                $map[$status] = $state;
            }
        }

        return $map;
    }
}
