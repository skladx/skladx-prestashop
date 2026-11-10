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
 * Reads everything OrderPayloadBuilder needs from a PrestaShop order.
 */
final class OrderSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function fromOrder(\Order $order): array
    {
        $items = [];

        foreach ((array) $order->getOrderDetailList() as $detail) {
            $quantity = (int) $detail['product_quantity'] - (int) ($detail['product_quantity_refunded'] ?? 0) - (int) ($detail['product_quantity_return'] ?? 0);

            $items[] = [
                'sku' => (string) $detail['product_reference'],
                'external_id' => ExternalId::forUnit((int) $order->id_shop, (int) $detail['product_id'], (int) $detail['product_attribute_id']),
                'name' => (string) $detail['product_name'],
                'quantity' => max(0, $quantity),
                'total' => $quantity > 0 ? (float) $detail['unit_price_tax_incl'] * $quantity : 0.0,
            ];
        }

        $customer = new \Customer((int) $order->id_customer);
        $currency = new \Currency((int) $order->id_currency);
        $carrier = new \Carrier((int) $order->id_carrier);
        $row = OrderRepository::get((int) $order->id);
        $message = $order->getFirstMessage();

        $shared = (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders` WHERE `reference` = \'' . pSQL((string) $order->reference) . '\'',
            false
        ) > 1;

        return [
            'id' => (int) $order->id,
            'reference' => (string) $order->reference,
            'shared_reference' => $shared,
            'currency' => (string) $currency->iso_code,
            'total' => (float) $order->total_paid_tax_incl,
            'payment_module' => (string) $order->module,
            'customer_note' => is_string($message) ? trim(strip_tags($message)) : '',
            'email' => \Validate::isLoadedObject($customer) ? (string) $customer->email : '',
            'delivery' => self::address((int) $order->id_address_delivery),
            'invoice' => self::address((int) $order->id_address_invoice),
            'items' => $items,
            'carrier_reference' => \Validate::isLoadedObject($carrier) ? (int) $carrier->id_reference : 0,
            'pickup_point_id' => (string) ($row['pickup_point_id'] ?? ''),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function address(int $idAddress): array
    {
        $address = new \Address($idAddress);

        if (!\Validate::isLoadedObject($address)) {
            return [];
        }

        return [
            'firstname' => (string) $address->firstname,
            'lastname' => (string) $address->lastname,
            'company' => (string) $address->company,
            'address1' => (string) $address->address1,
            'address2' => (string) $address->address2,
            'city' => (string) $address->city,
            'postcode' => (string) $address->postcode,
            'country' => (string) \Country::getIsoById((int) $address->id_country),
            'phone' => (string) $address->phone,
            'phone_mobile' => (string) $address->phone_mobile,
        ];
    }
}
