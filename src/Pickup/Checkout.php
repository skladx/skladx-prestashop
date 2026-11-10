<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Pickup;

use SkladX\PrestaShop\Api\ApiException;
use SkladX\PrestaShop\Api\Client;
use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Support\DbCache;
use SkladX\PrestaShop\Support\Logger;
use SkladX\PrestaShop\Support\T;
use SkladX\PrestaShop\Sync\OrderRepository;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Pickup point picker at checkout: services for the front controller
 * (search proxy, save choice) and the checkout hooks (validation, copying
 * the choice to the order).
 *
 * The picker only works for carriers attached to the module (PrestaShop
 * calls displayCarrierExtraContent / actionValidateStepComplete only for
 * module carriers); ticking "Pickup point" for a carrier in the settings
 * attaches it.
 */
final class Checkout
{
    /** SkladX calls (cache misses) per visitor and minute. */
    public const RATE_LIMIT = 30;

    public static function service(): PickupPointService
    {
        return new PickupPointService(
            static fn (array $query): array => Client::fromSettings()->pickupPoints($query),
            static fn (string $carrier, string $id): array => Client::fromSettings()->pickupPoint($carrier, $id),
            new DbCache()
        );
    }

    /**
     * SkladX carrier code of a pickup point carrier ('' for other carriers).
     */
    public static function carrierCode(int $idCarrier): string
    {
        if ($idCarrier <= 0 || !Settings::pickupPickerEnabled() || !Settings::isConfigured()) {
            return '';
        }

        $carrier = new \Carrier($idCarrier);

        return \Validate::isLoadedObject($carrier) ? Settings::pickupCarrierCode((int) $carrier->id_reference) : '';
    }

    /**
     * Selected carrier id of a cart (first delivery option).
     */
    public static function selectedCarrierId(\Cart $cart): int
    {
        $option = $cart->getDeliveryOption(null, false);

        if (is_array($option) && $option !== []) {
            $first = (string) reset($option);

            return (int) explode(',', trim($first, ','))[0];
        }

        return (int) $cart->id_carrier;
    }

    /**
     * Whether the customer still has to choose a pickup point (or the
     * chosen one does not fit the carrier); the reason, '' when all is fine.
     */
    public static function problemForCart(\Cart $cart): string
    {
        $code = self::carrierCode(self::selectedCarrierId($cart));

        if ($code === '') {
            return '';
        }

        return PickupPoint::problem(CartPickup::get((int) $cart->id), $code, false);
    }

    public static function problemMessage(string $problem): string
    {
        switch ($problem) {
            case 'missing':
                return T::__('Please choose a pickup point.');
            case 'wrong_carrier':
                return T::__('The chosen pickup point belongs to another carrier. Please choose a pickup point again.');
            case 'inactive':
                return T::__('The chosen pickup point is not in operation. Please choose another one.');
            case 'no_cod':
                return T::__('The chosen pickup point does not accept cash on delivery. Choose another pickup point or another payment method.');
            default:
                return T::__('Please choose a pickup point.');
        }
    }

    /**
     * Verifies and stores a choice for the cart.
     *
     * @param array<string, mixed>|null $widget point data from the Packeta widget
     *
     * @return array<string, mixed> normalised point
     *
     * @throws \InvalidArgumentException with a translated message
     */
    public static function choose(\Cart $cart, string $carrier, string $pointId, ?array $widget = null): array
    {
        $expected = self::carrierCode(self::selectedCarrierId($cart));

        if ($expected === '' || $expected !== $carrier) {
            throw new \InvalidArgumentException(T::__('The chosen shipping method does not deliver to a pickup point.'));
        }

        try {
            $point = self::service()->find($carrier, $pointId, true);
        } catch (ApiException $exception) {
            Logger::error('Pickup point lookup failed: ' . $exception->summary());
            $point = $widget !== null ? PickupPoint::fromWidget($carrier, $pointId, $widget) : null;

            if ($point === null) {
                throw new \InvalidArgumentException(T::__('Pickup points cannot be loaded right now. Please try again in a moment.'));
            }
        }

        if ($point === null) {
            throw new \InvalidArgumentException(T::__('This pickup point does not exist any more. Please choose another one.'));
        }

        $problem = PickupPoint::problem($point, $carrier, false);

        if ($problem !== '') {
            throw new \InvalidArgumentException(self::problemMessage($problem));
        }

        CartPickup::save((int) $cart->id, $point);

        return $point;
    }

    /**
     * Order created: copy the cart's pickup point to the order.
     */
    public static function copyToOrder(\Order $order): void
    {
        $code = Settings::pickupCarrierCode((int) (new \Carrier((int) $order->id_carrier))->id_reference);

        if ($code === '') {
            return;
        }

        $point = CartPickup::get((int) $order->id_cart);

        if ($point === null || (string) $point['carrier'] !== $code) {
            return;
        }

        OrderRepository::save((int) $order->id, [
            'pickup_carrier' => (string) $point['carrier'],
            'pickup_point_id' => (string) $point['id'],
            'pickup_point' => $point,
        ]);
    }

    /**
     * Fixed one-minute window per visitor (IP hash).
     */
    public static function withinRateLimit(): bool
    {
        $key = 'pprl_' . md5(\Tools::getRemoteAddr() . _COOKIE_KEY_) . '_' . (int) floor(time() / 60);

        return (new DbCache())->increment($key, 120) <= self::RATE_LIMIT;
    }

    /**
     * Day labels for opening hours.
     *
     * @return array<string, string>
     */
    public static function dayLabels(): array
    {
        return [
            'mon' => T::__('Mon'),
            'tue' => T::__('Tue'),
            'wed' => T::__('Wed'),
            'thu' => T::__('Thu'),
            'fri' => T::__('Fri'),
            'sat' => T::__('Sat'),
            'sun' => T::__('Sun'),
            'closed' => T::__('closed'),
            'nonstop' => T::__('non-stop'),
        ];
    }

    /**
     * Texts of the picker script.
     *
     * @return array<string, string>
     */
    public static function i18n(): array
    {
        return [
            'choose' => T::__('Choose pickup point'),
            'change' => T::__('Change'),
            'chosen' => T::__('Pickup point:'),
            'title' => T::__('Choose a pickup point'),
            'search' => T::__('City, postcode or name'),
            'searchButton' => T::__('Search'),
            'nearMe' => T::__('Near me'),
            'select' => T::__('Select'),
            'close' => T::__('Close'),
            'loading' => T::__('Loading…'),
            'more' => T::__('Show more'),
            'empty' => T::__('No pickup points found. Try another city or postcode.'),
            'error' => T::__('Pickup points cannot be loaded right now. Please try again in a moment.'),
            'geoError' => T::__('Your location is not available. Search by city or postcode instead.'),
            'cod' => T::__('Cash on delivery'),
            'noCod' => T::__('No cash on delivery'),
            'card' => T::__('Card payment'),
            'locker' => T::__('Parcel locker'),
            'point' => T::__('Pickup point'),
            'postOffice' => T::__('Post office'),
            'map' => T::__('Map'),
            'hours' => T::__('Opening hours'),
            'closing' => T::__('Closes on %s'),
            'missing' => T::__('Please choose a pickup point for the selected shipping method.'),
            'onlyCodPoints' => T::__('Showing only points that accept cash on delivery.'),
        ];
    }
}
