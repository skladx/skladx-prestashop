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
 * SkladX inbound receipt: normalisation and status rules. Pure PHP — unit tested.
 *
 * Defensive about the API shape so the admin works with every SkladX
 * version: `is_blind` or `blind`, `warehouse` as an object or a code,
 * item `product_name` or `name`, missing `quantity_received`.
 */
final class Receipt
{
    public const DRAFT = 'draft';
    public const ANNOUNCED = 'announced';
    public const IN_TRANSIT = 'in_transit';
    public const ARRIVED = 'arrived';
    public const RECEIVING = 'receiving';
    public const RECEIVED = 'received';
    public const CANCELLED = 'cancelled';

    /** Statuses in their natural order (list filter). */
    public const STATUSES = [self::DRAFT, self::ANNOUNCED, self::IN_TRANSIT, self::ARRIVED, self::RECEIVING, self::RECEIVED, self::CANCELLED];

    /** Statuses after which nothing changes any more. */
    public const FINAL_STATUSES = [self::RECEIVED, self::CANCELLED];

    /**
     * @param array<string, mixed> $raw receipt from the API
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $status = self::text($raw['status'] ?? '');
        $warehouse = $raw['warehouse'] ?? null;
        $items = [];
        $expected = 0;
        $received = null;

        foreach (is_array($raw['items'] ?? null) ? $raw['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $quantityExpected = (int) ($item['quantity_expected'] ?? ($item['quantity'] ?? 0));
            $quantityReceived = is_numeric($item['quantity_received'] ?? null) ? (int) $item['quantity_received'] : null;
            $expected += $quantityExpected;

            if ($quantityReceived !== null) {
                $received = (int) $received + $quantityReceived;
            }

            $items[] = [
                'id' => self::text($item['id'] ?? ''),
                'sku' => self::text($item['sku'] ?? ''),
                'name' => self::text($item['product_name'] ?? ($item['name'] ?? '')),
                'quantity_expected' => $quantityExpected,
                'quantity_received' => $quantityReceived,
                'difference' => $quantityReceived === null ? null : $quantityReceived - $quantityExpected,
                'batch' => self::text($item['batch'] ?? ''),
                'expires_at' => substr(self::text($item['expires_at'] ?? ''), 0, 10),
            ];
        }

        $attachments = [];

        foreach (is_array($raw['attachments'] ?? null) ? $raw['attachments'] : [] as $attachment) {
            if (is_array($attachment)) {
                $attachments[] = [
                    'id' => self::text($attachment['id'] ?? ''),
                    'name' => self::text($attachment['name'] ?? ($attachment['filename'] ?? '')),
                    'size' => (int) ($attachment['size'] ?? 0),
                ];
            }
        }

        /*
         * Received quantities only mean something once the warehouse has
         * started counting: SkladX reports 0 for every line of an announced
         * receipt, which must not read as "received 0, difference -20".
         */
        $hasReceived = $status === self::RECEIVED || ($status === self::RECEIVING && $received !== null);

        if (!$hasReceived) {
            foreach ($items as $index => $item) {
                $items[$index]['quantity_received'] = null;
                $items[$index]['difference'] = null;
            }
        }

        return [
            'id' => self::text($raw['id'] ?? ''),
            'number' => self::text($raw['number'] ?? ''),
            'name' => self::text($raw['name'] ?? ''),
            'reference' => self::text($raw['reference'] ?? ''),
            'status' => $status,
            'status_label' => self::text($raw['status_label'] ?? ''),
            'expected_at' => substr(self::text($raw['expected_at'] ?? ''), 0, 10),
            'carrier' => self::text($raw['carrier'] ?? ''),
            'tracking_number' => self::text($raw['tracking_number'] ?? ''),
            'tracking_url' => self::url($raw['tracking_url'] ?? ''),
            'is_blind' => (bool) ($raw['is_blind'] ?? ($raw['blind'] ?? false)),
            'note' => is_scalar($raw['note'] ?? null) ? (string) $raw['note'] : '',
            'warehouse' => [
                'code' => is_array($warehouse) ? self::text($warehouse['code'] ?? '') : self::text($warehouse),
                'name' => is_array($warehouse) ? self::text($warehouse['name'] ?? '') : '',
            ],
            'items' => $items,
            'attachments' => $attachments,
            'totals' => [
                'lines' => count($items),
                'expected' => $expected,
                'received' => $hasReceived ? (int) $received : null,
                'difference' => $hasReceived ? (int) $received - $expected : null,
            ],
            'has_received' => $hasReceived,
            'has_differences' => $hasReceived && array_filter($items, static fn (array $item): bool => $item['quantity_received'] === null || (int) $item['difference'] !== 0) !== [],
            'editable' => self::isEditable($status),
            'cancellable' => self::isCancellable($status),
            'announced_at' => self::text($raw['announced_at'] ?? ''),
            'received_at' => self::text($raw['received_at'] ?? ''),
            'created_at' => self::text($raw['created_at'] ?? ''),
            'updated_at' => self::text($raw['updated_at'] ?? ''),
        ];
    }

    /**
     * Only a draft can be changed (PUT) or announced.
     */
    public static function isEditable(string $status): bool
    {
        return $status === self::DRAFT;
    }

    /**
     * Nothing has been received yet.
     */
    public static function isCancellable(string $status): bool
    {
        return in_array($status, [self::DRAFT, self::ANNOUNCED, self::IN_TRANSIT], true);
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, self::FINAL_STATUSES, true);
    }

    /**
     * Badge colour class suffix.
     */
    public static function tone(string $status): string
    {
        switch ($status) {
            case self::DRAFT:
                return 'draft';
            case self::ANNOUNCED:
            case self::IN_TRANSIT:
                return 'waiting';
            case self::ARRIVED:
            case self::RECEIVING:
                return 'receiving';
            case self::RECEIVED:
                return 'done';
            case self::CANCELLED:
                return 'cancelled';
            default:
                return 'unknown';
        }
    }

    /**
     * The receipt inside a webhook/event payload: `{receipt: …}`,
     * `{inbound_receipt: …}` or the receipt itself.
     *
     * @param array<string, mixed> $data event data
     *
     * @return array<string, mixed>|null
     */
    public static function fromEvent(array $data): ?array
    {
        foreach (['receipt', 'inbound_receipt'] as $key) {
            if (is_array($data[$key] ?? null) && self::text($data[$key]['id'] ?? '') !== '') {
                return $data[$key];
            }
        }

        return str_starts_with(self::text($data['id'] ?? ''), 'inr_') ? $data : null;
    }

    public static function isId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1;
    }

    /**
     * @param mixed $value
     */
    private static function text($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Only http(s) URLs are kept.
     *
     * @param mixed $value
     */
    private static function url($value): string
    {
        $url = self::text($value);

        return preg_match('~^https?://~i', $url) ? $url : '';
    }
}
