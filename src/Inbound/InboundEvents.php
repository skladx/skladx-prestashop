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
 * Applies `inbound.*` events (webhook, polling) and refreshed receipts to the
 * receipt cache. Pure PHP — unit tested.
 *
 * The cache keeps the last known summary per receipt; `notify` is called
 * once per status change, so a webhook and the poll delivering the same
 * change produce one admin notice. Stale data (older `updated_at` than
 * cached) is ignored. The periodic refresh does not announce receipts it
 * sees for the first time, and what the shop manager does or sees in the
 * admin (SOURCE_ADMIN) is only cached.
 */
final class InboundEvents
{
    public const TYPES = ['inbound.announced', 'inbound.arrived', 'inbound.received', 'inbound.cancelled'];

    public const SOURCE_EVENT = 'event';
    public const SOURCE_REFRESH = 'refresh';
    public const SOURCE_ADMIN = 'admin';

    /** @var callable */
    private $notify;

    /**
     * @param callable $notify called with (normalised receipt, previous summary|null) on a status change
     */
    public function __construct(private readonly ReceiptStore $store, callable $notify)
    {
        $this->notify = $notify;
    }

    public static function handles(string $type): bool
    {
        return str_starts_with($type, 'inbound.');
    }

    /**
     * @param array<string, mixed> $data event data
     *
     * @return string processed|ignored
     */
    public function handleEvent(string $type, array $data): string
    {
        $raw = self::handles($type) ? Receipt::fromEvent($data) : null;

        return $raw === null ? 'ignored' : $this->apply(Receipt::normalize($raw), self::SOURCE_EVENT);
    }

    /**
     * @param array<string, mixed> $receipt normalised receipt
     *
     * @return string processed|ignored
     */
    public function apply(array $receipt, string $source = self::SOURCE_REFRESH): string
    {
        $id = (string) ($receipt['id'] ?? '');

        if ($id === '' || (string) ($receipt['status'] ?? '') === '') {
            return 'ignored';
        }

        $previous = $this->store->get($id);

        if ($previous !== null && self::isOlder((string) ($receipt['updated_at'] ?? ''), (string) ($previous['updated_at'] ?? ''))) {
            return 'ignored';
        }

        $summary = self::summary($receipt);

        if ($previous !== null && self::same($summary, $previous)) {
            return 'ignored';
        }

        $this->store->put($id, $summary);

        $statusChanged = $previous === null || $previous['status'] !== $summary['status'];

        if ($statusChanged && $source !== self::SOURCE_ADMIN && ($previous !== null || $source === self::SOURCE_EVENT)) {
            ($this->notify)($receipt, $previous);
        }

        return 'processed';
    }

    /**
     * What the cache keeps about a receipt.
     *
     * @param array<string, mixed> $receipt normalised receipt
     *
     * @return array<string, mixed>
     */
    public static function summary(array $receipt): array
    {
        $totals = (array) ($receipt['totals'] ?? []);

        return [
            'id' => (string) ($receipt['id'] ?? ''),
            'number' => (string) ($receipt['number'] ?? ''),
            'name' => (string) ($receipt['name'] ?? ''),
            'status' => (string) ($receipt['status'] ?? ''),
            'status_label' => (string) ($receipt['status_label'] ?? ''),
            'expected_at' => (string) ($receipt['expected_at'] ?? ''),
            'carrier' => (string) ($receipt['carrier'] ?? ''),
            'tracking_number' => (string) ($receipt['tracking_number'] ?? ''),
            'expected' => (int) ($totals['expected'] ?? 0),
            'received' => isset($totals['received']) ? (int) $totals['received'] : null,
            'has_differences' => (bool) ($receipt['has_differences'] ?? false),
            'created_at' => (string) ($receipt['created_at'] ?? ''),
            'updated_at' => (string) ($receipt['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function same(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        return $a == $b;
    }

    private static function isOlder(string $candidate, string $known): bool
    {
        $candidateTime = $candidate !== '' ? strtotime($candidate) : false;
        $knownTime = $known !== '' ? strtotime($known) : false;

        return $candidateTime !== false && $knownTime !== false && $candidateTime < $knownTime;
    }
}
