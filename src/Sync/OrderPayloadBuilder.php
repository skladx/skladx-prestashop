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
 * Turns an order snapshot (OrderSnapshot::fromOrder(), a plain array) into
 * the SkladX `POST /orders` payload. Pure PHP — unit tested.
 *
 * Snapshot:
 * `{id, reference, shared_reference, currency, total, payment_module, customer_note, email,
 *   delivery: Address, invoice: Address, items: [{sku, external_id, name, quantity, total}],
 *   carrier_reference, pickup_point_id}`
 * Address: `{firstname, lastname, company, address1, address2, city, postcode, country, phone, phone_mobile}`
 *
 * Config: `{carrier_map: {id_reference: code}, cod_modules: [module], site, default_country}`
 */
final class OrderPayloadBuilder
{
    /**
     * @param array<string, mixed> $order snapshot
     * @param array<string, mixed> $config carrier map, COD modules, site host, default country
     *
     * @return array<string, mixed>
     *
     * @throws MappingException when the order cannot be exported (no items, missing SKU, no address)
     */
    public static function build(array $order, array $config): array
    {
        $items = [];

        foreach ((array) ($order['items'] ?? []) as $item) {
            $sku = trim((string) ($item['sku'] ?? ''));
            $name = (string) ($item['name'] ?? '');

            if ($sku === '') {
                throw new MappingException(sprintf('Order line "%s" has no SKU.', $name), MappingException::NO_SKU, $name);
            }

            $quantity = (int) ($item['quantity'] ?? 0);

            if ($quantity < 1) {
                continue;
            }

            if (isset($items[$sku])) {
                $items[$sku]['quantity'] += $quantity;
                $items[$sku]['line_total'] += (float) ($item['total'] ?? 0);
                continue;
            }

            $externalId = trim((string) ($item['external_id'] ?? ''));
            $items[$sku] = [
                'sku' => $sku,
                'external_id' => $externalId !== '' ? mb_substr($externalId, 0, 191) : null,
                'name' => mb_substr($name, 0, 255),
                'quantity' => $quantity,
                'line_total' => (float) ($item['total'] ?? 0),
            ];
        }

        if ($items === []) {
            throw new MappingException('Order has no products to ship.', MappingException::NO_ITEMS);
        }

        $items = array_values(array_map(
            static fn (array $item): array => array_filter([
                'sku' => $item['sku'],
                'external_id' => $item['external_id'],
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price_cents' => self::cents($item['line_total'] / $item['quantity']),
            ], static fn ($value): bool => $value !== null),
            $items
        ));

        $delivery = self::address($order['delivery'] ?? []);
        $invoice = self::address($order['invoice'] ?? []);
        $address = trim($delivery['address1']) !== '' ? $delivery : $invoice;

        if (trim($address['address1']) === '') {
            throw new MappingException('Order has no shipping address.', MappingException::NO_ADDRESS);
        }

        $name = trim($address['firstname'] . ' ' . $address['lastname']);

        $shippingAddress = array_filter(
            [
                'name' => self::limit($name !== '' ? $name : $address['company'], 255),
                'company' => self::limit($address['company'], 255),
                'street' => self::limit(trim($address['address1'] . ' ' . $address['address2']), 255),
                'city' => self::limit($address['city'], 128),
                'postal_code' => self::limit($address['postcode'], 16),
                'country' => strtoupper($address['country'] !== '' ? $address['country'] : (string) ($config['default_country'] ?? '')),
            ],
            static fn (string $value): bool => $value !== ''
        );

        $carrierMap = (array) ($config['carrier_map'] ?? []);
        $carrierReference = (string) (int) ($order['carrier_reference'] ?? 0);
        $carrier = trim((string) ($carrierMap[$carrierReference] ?? ''));
        $pickupPoint = trim((string) ($order['pickup_point_id'] ?? ''));

        $phone = self::firstNonEmpty([
            $address['phone_mobile'],
            $address['phone'],
            $delivery['phone_mobile'],
            $delivery['phone'],
            $invoice['phone_mobile'],
            $invoice['phone'],
        ]);

        $total = (float) ($order['total'] ?? 0);
        $isCod = in_array((string) ($order['payment_module'] ?? ''), (array) ($config['cod_modules'] ?? []), true);
        $email = trim((string) ($order['email'] ?? ''));
        $note = trim((string) ($order['customer_note'] ?? ''));

        $payload = [
            'reference' => self::limit(self::reference($order), 64),
            'items' => $items,
            'shipping_address' => $shippingAddress,
            'carrier' => $carrier !== '' ? $carrier : null,
            'pickup_point_id' => $pickupPoint !== '' ? self::limit($pickupPoint, 64) : null,
            'cod_cents' => $isCod ? self::cents($total) : null,
            'total_cents' => self::cents($total),
            'currency' => strtoupper((string) ($order['currency'] ?? '')) ?: null,
            'customer_email' => $email !== '' ? $email : null,
            'customer_phone' => $phone !== '' ? self::limit($phone, 32) : null,
            'note' => $note !== '' ? self::limit($note, 2000) : null,
            'metadata' => [
                'ps_order_id' => (int) ($order['id'] ?? 0),
                'ps_order_reference' => (string) ($order['reference'] ?? ''),
                'site' => (string) ($config['site'] ?? ''),
            ],
        ];

        return array_filter($payload, static fn ($value): bool => $value !== null);
    }

    /**
     * SkladX reference of an order: the PrestaShop order reference. When one
     * cart was split into several orders (they share the reference), the order
     * id is appended so every SkladX order stays unique.
     *
     * @param array<string, mixed> $order snapshot
     */
    public static function reference(array $order): string
    {
        $reference = trim((string) ($order['reference'] ?? ''));

        if ($reference === '') {
            return (string) (int) ($order['id'] ?? 0);
        }

        return !empty($order['shared_reference']) ? $reference . '-' . (int) ($order['id'] ?? 0) : $reference;
    }

    /**
     * Fingerprint of a SkladX shipping address, to notice that the address in
     * PrestaShop changed after the export. Empty fields and key order do not matter.
     *
     * @param array<string, mixed> $address `shipping_address` of the payload or of a SkladX order
     */
    public static function addressFingerprint(array $address): string
    {
        $fields = [];

        foreach (['name', 'company', 'street', 'city', 'postal_code', 'country'] as $key) {
            $value = is_scalar($address[$key] ?? null) ? trim((string) $address[$key]) : '';

            if ($value !== '') {
                $fields[$key] = $key === 'country' ? strtoupper($value) : $value;
            }
        }

        return md5((string) json_encode($fields));
    }

    /**
     * @param mixed $raw address array
     *
     * @return array{firstname: string, lastname: string, company: string, address1: string, address2: string, city: string, postcode: string, country: string, phone: string, phone_mobile: string}
     */
    private static function address($raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $address = [];

        foreach (['firstname', 'lastname', 'company', 'address1', 'address2', 'city', 'postcode', 'country', 'phone', 'phone_mobile'] as $key) {
            $address[$key] = is_scalar($raw[$key] ?? null) ? trim((string) $raw[$key]) : '';
        }

        return $address;
    }

    /**
     * @param list<string> $values
     */
    private static function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private static function cents(float $amount): int
    {
        return max(0, (int) round($amount * 100));
    }

    private static function limit(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
