<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Inbound;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Cache of the last known receipt summaries (DbReceiptStore in the shop).
 */
interface ReceiptStore
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array;

    /**
     * @param array<string, mixed> $summary
     */
    public function put(string $id, array $summary): void;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array;
}
