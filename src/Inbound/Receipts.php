<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Inbound;

use SkladX\PrestaShop\Api\ApiException;
use SkladX\PrestaShop\Api\Client;
use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Support\Logger;
use SkladX\PrestaShop\Support\Notices;
use SkladX\PrestaShop\Support\T;
use SkladX\PrestaShop\Sync\Tracking;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Inbound receipts ("Príjemky"): labels, cache updates and the refresh job.
 * SkladX is the source of truth: nothing is stored in PrestaShop except a
 * small status cache (DbReceiptStore) used for admin notices.
 */
final class Receipts
{
    /** Seconds between refreshes of open receipts (fallback for missed events). */
    public const REFRESH_INTERVAL = 1800;

    public const CONTROLLER = 'AdminSkladxReceipts';

    public static function events(): InboundEvents
    {
        return new InboundEvents(new DbReceiptStore(), [self::class, 'notify']);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return string processed|ignored
     */
    public static function handleEvent(string $type, array $data): string
    {
        return self::events()->handleEvent($type, $data);
    }

    /**
     * Keeps the cache in sync with receipts loaded or changed in the admin.
     *
     * @param array<string, mixed> $receipt normalised receipt
     */
    public static function remember(array $receipt): void
    {
        self::events()->apply($receipt, InboundEvents::SOURCE_ADMIN);
    }

    /**
     * Reloads the first page of receipts while some are still open, so a
     * status change is noticed even when both the webhook and the event poll
     * missed it. Stops quietly while SkladX does not support receipts (404).
     */
    public static function refresh(): void
    {
        $open = array_filter((new DbReceiptStore())->all(), static fn (array $summary): bool => !Receipt::isFinal((string) ($summary['status'] ?? '')));

        if ($open === []) {
            return;
        }

        try {
            $page = Client::fromSettings()->inboundReceipts(['limit' => 50]);
        } catch (ApiException $exception) {
            if ($exception->status !== 404) {
                Logger::warning('Refreshing SkladX receipts failed: ' . $exception->summary());
            }

            return;
        }

        $events = self::events();

        foreach ($page['data'] as $raw) {
            $events->apply(Receipt::normalize($raw), InboundEvents::SOURCE_REFRESH);
        }
    }

    /**
     * Admin notice about a status change.
     *
     * @param array<string, mixed> $receipt normalised receipt
     * @param array<string, mixed>|null $previous previous summary
     */
    public static function notify(array $receipt, ?array $previous): void
    {
        $label = (string) $receipt['number'] !== '' ? (string) $receipt['number'] : (string) $receipt['name'];
        $link = self::url(['receipt' => (string) $receipt['id']]);

        if ($receipt['status'] === Receipt::RECEIVED && !empty($receipt['has_differences'])) {
            Notices::add(T::f('Receipt %1$s was received with differences: %2$d of %3$d pieces.', $label, (int) $receipt['totals']['received'], (int) $receipt['totals']['expected']), 'warning', $link, T::__('Open receipt'));
        } else {
            Notices::add(T::f('Receipt %1$s: %2$s.', $label, self::statusLabel((string) $receipt['status'], (string) $receipt['status_label'])), $receipt['status'] === Receipt::CANCELLED ? 'warning' : 'success', $link, T::__('Open receipt'));
        }

        Logger::info(sprintf('Receipt %s: %s → %s.', (string) $receipt['id'], (string) ($previous['status'] ?? '-'), (string) $receipt['status']));
    }

    /**
     * Back office URL of the receipts screen.
     *
     * @param array<string, scalar> $params
     */
    public static function url(array $params = []): string
    {
        try {
            return \Context::getContext()->link->getAdminLink(self::CONTROLLER, true, [], $params);
        } catch (\Throwable $exception) {
            return '';
        }
    }

    public static function statusLabel(string $status, string $fallback = ''): string
    {
        return self::statusLabels()[$status] ?? ($fallback !== '' ? $fallback : $status);
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            Receipt::DRAFT => T::__('Draft'),
            Receipt::ANNOUNCED => T::__('Awaiting receipt'),
            Receipt::IN_TRANSIT => T::__('In transit'),
            Receipt::ARRIVED => T::__('Arrived at warehouse'),
            Receipt::RECEIVING => T::__('Receiving'),
            Receipt::RECEIVED => T::__('Received'),
            Receipt::CANCELLED => T::__('Cancelled'),
        ];
    }

    /**
     * Carriers of the "Via carrier" select.
     *
     * @return array<string, string> value => label
     */
    public static function carrierOptions(): array
    {
        $options = [];

        foreach (Settings::CARRIERS as $carrier) {
            $options[$carrier] = self::carrierName($carrier);
        }

        $options[ReceiptPayload::CARRIER_PERSONAL] = T::__('In person to the warehouse');
        $options[ReceiptPayload::CARRIER_OTHER] = T::__('Other');

        return $options;
    }

    public static function carrierName(string $carrier): string
    {
        $names = [
            'packeta' => 'Packeta',
            'dpd_sk' => 'DPD',
            'gls_sk' => 'GLS',
            'sps' => 'SPS – Slovak Parcel Service',
            'posta_sk' => T::__('Slovak Post'),
            'balikovo' => 'Balíkovo',
        ];

        return $names[$carrier] ?? $carrier;
    }

    public static function carrierLabel(string $carrier): string
    {
        $options = self::carrierOptions();

        return $carrier === '' ? '' : ($options[$carrier] ?? $carrier);
    }

    /**
     * Tracking link: SkladX `tracking_url`, else the carrier's public page.
     *
     * @param array<string, mixed> $receipt
     */
    public static function trackingUrl(array $receipt): string
    {
        $url = (string) ($receipt['tracking_url'] ?? '');

        return $url !== '' ? $url : Tracking::carrierUrl((string) ($receipt['carrier'] ?? ''), (string) ($receipt['tracking_number'] ?? ''));
    }

    /**
     * Translated explanation of an API error for the receipts screens.
     */
    public static function errorMessage(ApiException $exception): string
    {
        if ($exception->status === 403) {
            return T::__('The SkladX API key is missing the scopes inbound:read and inbound:write. Issue a new key with these scopes.');
        }

        if ($exception->errorCode === 'not_configured') {
            return T::__('SkladX is not configured. Fill in the API URL and key in Modules → SkladX → Configure.');
        }

        return $exception->summary();
    }

    public static function unsupportedMessage(): string
    {
        return T::__('SkladX does not support this module yet.');
    }

    /**
     * Whether a failed `GET /inbound-receipts` means that this SkladX
     * instance has no receipts API yet.
     */
    public static function isUnsupported(ApiException $exception): bool
    {
        return $exception->status === 404 || $exception->status === 405;
    }
}
