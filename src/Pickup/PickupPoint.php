<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Pickup;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Pickup point data: normalisation, query sanitising and validation rules.
 * Pure PHP — unit tested.
 *
 * The normalised point is what the module keeps on the cart, sends to the
 * browser and stores on the order:
 * `{id, carrier, ref, name, type, street, city, postal_code, country, lat, lng,
 *   distance_km, cod, card, opening_hours, photo_url, active, closing_on}`.
 */
final class PickupPoint
{
    /** Results per page of the picker. */
    public const PAGE_SIZE = 20;

    /** Search radius (km) around the customer's location. */
    public const NEAR_RADIUS_KM = 10;

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * Normalises one point of `GET /pickup-points` (or `/pickup-points/{carrier}/{id}`).
     *
     * Defensive about the shape: `address.postal_code` or `address.zip`, flat
     * address fields, `location.{lat,lng}` or flat `lat`/`lng`, composite
     * `carrier:id` ids.
     *
     * @param array<string, mixed> $raw point from the API
     * @param string $fallbackCarrier carrier the point was asked for
     *
     * @return array<string, mixed>|null point, or null when it has no id
     */
    public static function normalize(array $raw, string $fallbackCarrier = ''): ?array
    {
        $carrier = self::text($raw['carrier'] ?? '');
        $carrier = $carrier !== '' ? $carrier : $fallbackCarrier;
        $id = self::text($raw['external_id'] ?? '');

        if ($id === '') {
            $id = self::text($raw['id'] ?? '');
        }

        if ($carrier !== '' && str_starts_with($id, $carrier . ':')) {
            $id = substr($id, strlen($carrier) + 1);
        } elseif ($carrier === '' && str_contains($id, ':')) {
            [$carrier, $id] = explode(':', $id, 2);
        }

        if ($id === '' || $carrier === '') {
            return null;
        }

        $address = is_array($raw['address'] ?? null) ? $raw['address'] : $raw;
        $location = is_array($raw['location'] ?? null) ? $raw['location'] : $raw;
        $supports = is_array($raw['supports'] ?? null) ? $raw['supports'] : [];

        return [
            'id' => $id,
            'carrier' => $carrier,
            'ref' => $carrier . ':' . $id,
            'name' => self::text($raw['name'] ?? ''),
            'type' => self::text($raw['type'] ?? 'point'),
            'street' => self::text($address['street'] ?? ''),
            'city' => self::text($address['city'] ?? ''),
            'postal_code' => self::text($address['postal_code'] ?? ($address['zip'] ?? '')),
            'country' => strtoupper(self::text($address['country'] ?? ($address['country_code'] ?? ''))),
            'lat' => is_numeric($location['lat'] ?? null) ? (float) $location['lat'] : null,
            'lng' => is_numeric($location['lng'] ?? null) ? (float) $location['lng'] : null,
            'distance_km' => is_numeric($raw['distance_km'] ?? null) ? round((float) $raw['distance_km'], 2) : null,
            'cod' => self::flag($supports['cod'] ?? ($raw['supports_cod'] ?? null)),
            'card' => self::flag($supports['card'] ?? ($raw['supports_card'] ?? null)),
            'opening_hours' => is_array($raw['opening_hours'] ?? null) ? $raw['opening_hours'] : null,
            'photo_url' => self::text($raw['photo_url'] ?? ''),
            'active' => array_key_exists('is_active', $raw) ? (bool) $raw['is_active'] : true,
            'closing_on' => self::text($raw['closing_on'] ?? ''),
        ];
    }

    /**
     * Point built from what the official Packeta widget returned. Used only
     * when SkladX cannot be reached to verify the point; SkladX validates it
     * again when the order is sent.
     *
     * @param array<string, mixed> $data widget data (name, street, city, postal_code/zip, country)
     *
     * @return array<string, mixed>|null
     */
    public static function fromWidget(string $carrier, string $id, array $data): ?array
    {
        $point = self::normalize([
            'carrier' => $carrier,
            'external_id' => $id,
            'name' => $data['name'] ?? '',
            'address' => [
                'street' => $data['street'] ?? '',
                'city' => $data['city'] ?? '',
                'postal_code' => $data['postal_code'] ?? ($data['zip'] ?? ''),
                'country' => $data['country'] ?? '',
            ],
        ]);

        if ($point === null || $point['name'] === '') {
            return null;
        }

        $point['unverified'] = true;

        return $point;
    }

    /**
     * Sanitises the query of the module's pickup point proxy into SkladX query args.
     *
     * @param array<string, mixed> $params request params (carrier, q, near, cod, cursor, country)
     * @param list<string> $allowedCarriers carriers with a pickup point carrier in the shop
     * @param string $defaultCountry shop country
     *
     * @return array<string, scalar> query for `GET /pickup-points`
     *
     * @throws \InvalidArgumentException with message `invalid_carrier`, `invalid_near` or `invalid_country`
     */
    public static function sanitizeQuery(array $params, array $allowedCarriers, string $defaultCountry): array
    {
        $carrier = strtolower(self::text($params['carrier'] ?? ''));

        if ($carrier === '' || !in_array($carrier, $allowedCarriers, true)) {
            throw new \InvalidArgumentException('invalid_carrier');
        }

        $country = strtoupper(self::text($params['country'] ?? ''));
        $country = $country !== '' ? $country : strtoupper($defaultCountry);

        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new \InvalidArgumentException('invalid_country');
        }

        $query = [
            'carrier' => $carrier,
            'country' => $country,
            'limit' => self::PAGE_SIZE,
        ];

        $search = trim((string) preg_replace('/\s+/u', ' ', self::text($params['q'] ?? '')));

        if ($search !== '') {
            $query['q'] = mb_substr($search, 0, 100);
        }

        $near = self::text($params['near'] ?? '');

        if ($near !== '') {
            if (preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $near, $match) !== 1 || abs((float) $match[1]) > 90 || abs((float) $match[2]) > 180) {
                throw new \InvalidArgumentException('invalid_near');
            }

            // ~100 m precision: enough for "near me" and makes cached answers reusable.
            $query['near'] = sprintf('%.3F,%.3F', (float) $match[1], (float) $match[2]);
            $query['radius_km'] = self::NEAR_RADIUS_KM;
        }

        $cod = $params['cod'] ?? null;

        if (!empty($cod) && $cod !== 'false' && (string) $cod !== '0') {
            $query['cod'] = 1;
        }

        $cursor = self::text($params['cursor'] ?? '');

        if ($cursor !== '' && preg_match('/^[A-Za-z0-9_\-=]{1,200}$/', $cursor) === 1) {
            $query['cursor'] = $cursor;
        }

        return $query;
    }

    /**
     * Cache key of a search (independent of the argument order).
     *
     * @param array<string, scalar> $query sanitised query
     */
    public static function cacheKey(array $query): string
    {
        ksort($query);

        return 'pp_' . md5((string) json_encode($query));
    }

    public static function pointCacheKey(string $carrier, string $id): string
    {
        return 'ppt_' . md5($carrier . ':' . $id);
    }

    /**
     * Why a chosen point cannot be used for the order, '' when it can.
     *
     * @param array<string, mixed>|null $point normalised point (null = none chosen)
     * @param string $carrier carrier of the chosen shipping method
     * @param bool $cod cash on delivery is the payment method
     *
     * @return string ''|missing|wrong_carrier|inactive|no_cod
     */
    public static function problem(?array $point, string $carrier, bool $cod): string
    {
        if ($point === null || (string) ($point['id'] ?? '') === '') {
            return 'missing';
        }

        if ((string) ($point['carrier'] ?? '') !== $carrier) {
            return 'wrong_carrier';
        }

        if (isset($point['active']) && $point['active'] === false) {
            return 'inactive';
        }

        if ($cod && ($point['cod'] ?? null) === false) {
            return 'no_cod';
        }

        return '';
    }

    /**
     * One-line address: "Street, 811 06 City".
     *
     * @param array<string, mixed> $point normalised point
     */
    public static function addressLine(array $point): string
    {
        $city = trim(self::text($point['postal_code'] ?? '') . ' ' . self::text($point['city'] ?? ''));

        return implode(', ', array_filter([self::text($point['street'] ?? ''), $city], static fn (string $part): bool => $part !== ''));
    }

    /**
     * Opening hours as short lines, consecutive days with equal hours grouped:
     * `["Po–Pi 09:00–17:00", "So 09:00–12:00", "Ne zatvorené"]`.
     *
     * @param array<string, mixed>|null $hours `{"mon": [["09:00","17:00"]], …}`
     * @param array<string, string> $labels day labels (mon…sun) plus `closed` and `nonstop`
     *
     * @return list<string>
     */
    public static function openingHoursLines(?array $hours, array $labels): array
    {
        if ($hours === null) {
            return [];
        }

        $texts = [];

        foreach (self::DAYS as $day) {
            $intervals = is_array($hours[$day] ?? null) ? $hours[$day] : null;

            if ($intervals === null) {
                $texts[$day] = null;
                continue;
            }

            $parts = [];

            foreach ($intervals as $interval) {
                if (is_array($interval) && isset($interval[0], $interval[1])) {
                    $parts[] = ($interval[0] === '00:00' && in_array($interval[1], ['24:00', '23:59'], true)) ? ($labels['nonstop'] ?? '0–24') : $interval[0] . '–' . $interval[1];
                }
            }

            $texts[$day] = $parts === [] ? ($labels['closed'] ?? '–') : implode(', ', $parts);
        }

        if (array_filter($texts, static fn ($text): bool => $text !== null) === []) {
            return [];
        }

        $lines = [];
        $start = null;
        $prev = null;

        foreach (array_merge(self::DAYS, ['']) as $day) {
            $text = $day === '' ? false : $texts[$day];

            if ($start !== null && $text !== $texts[$prev]) {
                if ($texts[$prev] !== null) {
                    $range = $start === $prev ? ($labels[$start] ?? $start) : ($labels[$start] ?? $start) . '–' . ($labels[$prev] ?? $prev);
                    $lines[] = $range . ' ' . $texts[$prev];
                }

                $start = null;
            }

            if ($day !== '' && $start === null) {
                $start = $day;
            }

            $prev = $day;
        }

        return $lines;
    }

    /**
     * What the browser needs of a point (no raw data, no internal flags).
     *
     * @param array<string, mixed> $point normalised point
     * @param array<string, string> $labels day labels for openingHoursLines()
     *
     * @return array<string, mixed>
     */
    public static function forBrowser(array $point, array $labels): array
    {
        return [
            'id' => (string) $point['id'],
            'carrier' => (string) $point['carrier'],
            'name' => (string) $point['name'],
            'type' => (string) $point['type'],
            'address' => self::addressLine($point),
            'street' => (string) $point['street'],
            'city' => (string) $point['city'],
            'postal_code' => (string) $point['postal_code'],
            'lat' => $point['lat'],
            'lng' => $point['lng'],
            'distance_km' => $point['distance_km'],
            'cod' => $point['cod'],
            'card' => $point['card'],
            'hours' => self::openingHoursLines($point['opening_hours'] ?? null, $labels),
            'closing_on' => (string) $point['closing_on'],
        ];
    }

    /**
     * @param mixed $value
     */
    private static function text($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param mixed $value
     */
    private static function flag($value): ?bool
    {
        return $value === null ? null : (bool) $value;
    }
}
