<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Queue;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Retry delays of the job queue. Pure PHP — unit tested.
 */
final class Backoff
{
    /** Seconds before retry n+1 of an order export: 1, 5, 15, 60, 180, 360 minutes. */
    public const ORDER_EXPORT = [60, 300, 900, 3600, 10800, 21600];

    /** Seconds before retry n+1 of a product sync that failed with a network error, 429 or 5xx: 1, 5, 15, 60, 180, 360 minutes. */
    public const PRODUCT_SYNC = [60, 300, 900, 3600, 10800, 21600];

    /** Seconds before retry n+1 of other jobs (stock pull, events). */
    public const DEFAULT = [300, 900, 3600];

    /**
     * Delay before the next attempt, or null when no attempt is left.
     *
     * @param int $attempt number of failed attempts so far (1 after the first failure)
     * @param list<int> $schedule delays
     */
    public static function delay(int $attempt, array $schedule = self::DEFAULT): ?int
    {
        if ($attempt < 1) {
            return 0;
        }

        return $schedule[$attempt - 1] ?? null;
    }

    public static function maxAttempts(array $schedule = self::DEFAULT): int
    {
        return count($schedule) + 1;
    }
}
