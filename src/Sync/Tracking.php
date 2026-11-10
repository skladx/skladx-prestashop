<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Sync;

use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Tracking number and URL shown to customers. When SkladX does not send a
 * tracking URL, the public tracking page of the carrier is used.
 */
final class Tracking
{
    /** Public tracking pages; `%s` is the tracking number. */
    public const CARRIER_URLS = [
        'packeta' => 'https://tracking.packeta.com/sk/?id=%s',
        'dpd_sk' => 'https://tracking.dpd.de/status/sk_SK/parcel/%s',
        'gls_sk' => 'https://gls-group.com/SK/sk/sledovanie-zasielok?match=%s',
        'posta_sk' => 'https://tandt.posta.sk/zasielky/%s',
    ];

    /**
     * Public tracking page of a carrier ('' when the carrier has none).
     */
    public static function carrierUrl(string $carrier, string $number): string
    {
        return isset(self::CARRIER_URLS[$carrier]) && $number !== '' ? sprintf(self::CARRIER_URLS[$carrier], rawurlencode($number)) : '';
    }

    /**
     * PrestaShop carrier URL template (`@` = tracking number) of a SkladX carrier.
     */
    public static function carrierUrlTemplate(string $carrier): string
    {
        return isset(self::CARRIER_URLS[$carrier]) ? str_replace('%s', '@', self::CARRIER_URLS[$carrier]) : '';
    }

    /**
     * @param array<string, mixed>|null $row skladx_order row
     *
     * @return array{number: string, url: string}|null
     */
    public static function forRow(?array $row): ?array
    {
        $number = (string) ($row['tracking_number'] ?? '');

        if ($number === '') {
            return null;
        }

        $url = (string) ($row['tracking_url'] ?? '');

        return [
            'number' => $number,
            'url' => $url !== '' ? $url : self::carrierUrl((string) ($row['carrier'] ?? ''), $number),
        ];
    }

    /**
     * Writes a new tracking number to the order carrier (shown in the
     * customer's order history) and sends the PrestaShop "in transit" e-mail
     * (with the carrier's tracking link), like entering it in the back office.
     */
    public static function applyToOrder(\Order $order, string $number): void
    {
        $idOrderCarrier = (int) $order->getIdOrderCarrier();

        if ($idOrderCarrier <= 0) {
            return;
        }

        $orderCarrier = new \OrderCarrier($idOrderCarrier);

        if (!\Validate::isLoadedObject($orderCarrier) || (string) $orderCarrier->tracking_number === $number) {
            return;
        }

        $orderCarrier->tracking_number = $number;

        if (!$orderCarrier->update()) {
            return;
        }

        if (Settings::trackingEmailEnabled()) {
            try {
                $orderCarrier->sendInTransitEmail($order);
            } catch (\Throwable $exception) {
                Logger::warning(sprintf('In transit e-mail for order #%d failed: %s', (int) $order->id, $exception->getMessage()));
            }
        }

        \Hook::exec('actionAdminOrdersTrackingNumberUpdate', [
            'order' => $order,
            'customer' => new \Customer((int) $order->id_customer),
            'carrier' => new \Carrier((int) $order->id_carrier, (int) $order->id_lang),
        ], null, false, true, false, (int) $order->id_shop);
    }
}
