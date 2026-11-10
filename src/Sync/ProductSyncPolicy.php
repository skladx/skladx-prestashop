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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Decisions of the product sync: what to do with an API error and whether a
 * payload has to be sent at all. Pure PHP — unit tested.
 */
final class ProductSyncPolicy
{
    /** Network error, 429, 5xx: queue and retry with backoff. */
    public const RETRY = 'retry';

    /** 409 sku_conflict: show to the admin, never retry the same payload. */
    public const CONFLICT = 'conflict';

    /** Other 4xx (validation…): show to the admin, resend on the next save. */
    public const FAILED = 'failed';

    public static function classify(ApiException $exception): string
    {
        if ($exception->isSkuConflict()) {
            return self::CONFLICT;
        }

        return $exception->isRetryable() ? self::RETRY : self::FAILED;
    }

    /**
     * Whether a payload has to be sent. An unchanged payload that was synced
     * — or that failed with an SKU conflict — is not sent again unless forced
     * (Sync to SkladX now, Sync all products).
     *
     * @param string $hash md5 of the payload
     * @param array{sync_hash: string, sync_error: string, sync_error_code?: string} $state stored sync state
     */
    public static function shouldSend(string $hash, array $state, bool $force): bool
    {
        if ($force || $hash !== $state['sync_hash']) {
            return true;
        }

        return $state['sync_error'] !== '' && ($state['sync_error_code'] ?? '') !== SkuConflict::ERROR_CODE;
    }
}
