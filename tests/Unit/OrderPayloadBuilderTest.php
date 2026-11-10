<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\PrestaShop\Sync\MappingException;
use SkladX\PrestaShop\Sync\OrderPayloadBuilder;

final class OrderPayloadBuilderTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function snapshot(array $overrides = []): array
    {
        $address = [
            'firstname' => 'Jana',
            'lastname' => 'Testovacia',
            'company' => '',
            'address1' => 'Obchodná 12',
            'address2' => 'byt 4',
            'city' => 'Bratislava',
            'postcode' => '81106',
            'country' => 'SK',
            'phone' => '',
            'phone_mobile' => '+421900123456',
        ];

        return array_replace([
            'id' => 16,
            'reference' => 'XKBKNABJK',
            'shared_reference' => false,
            'currency' => 'eur',
            'total' => 62.2,
            'payment_module' => 'ps_cashondelivery',
            'customer_note' => 'Volať pred doručením.',
            'email' => 'jana@example.com',
            'delivery' => $address,
            'invoice' => $address,
            'items' => [
                ['sku' => 'KOL-300', 'name' => 'Kolagén', 'quantity' => 2, 'total' => 49.8],
                ['sku' => 'SHK-700', 'name' => 'Šejker', 'quantity' => 1, 'total' => 7.5],
            ],
            'carrier_reference' => 7,
            'pickup_point_id' => '12345',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'carrier_map' => ['7' => 'packeta', '8' => 'dpd_sk'],
            'cod_modules' => ['ps_cashondelivery'],
            'site' => 'shop.example',
            'default_country' => 'SK',
        ];
    }

    public function testBuildsTheFullPayload(): void
    {
        $payload = OrderPayloadBuilder::build($this->snapshot(), $this->config());

        $this->assertSame('XKBKNABJK', $payload['reference']);
        $this->assertSame([
            ['sku' => 'KOL-300', 'name' => 'Kolagén', 'quantity' => 2, 'unit_price_cents' => 2490],
            ['sku' => 'SHK-700', 'name' => 'Šejker', 'quantity' => 1, 'unit_price_cents' => 750],
        ], $payload['items']);
        $this->assertSame([
            'name' => 'Jana Testovacia',
            'street' => 'Obchodná 12 byt 4',
            'city' => 'Bratislava',
            'postal_code' => '81106',
            'country' => 'SK',
        ], $payload['shipping_address']);
        $this->assertSame('packeta', $payload['carrier']);
        $this->assertSame('12345', $payload['pickup_point_id']);
        $this->assertSame(6220, $payload['cod_cents']);
        $this->assertSame(6220, $payload['total_cents']);
        $this->assertSame('EUR', $payload['currency']);
        $this->assertSame('jana@example.com', $payload['customer_email']);
        $this->assertSame('+421900123456', $payload['customer_phone']);
        $this->assertSame('Volať pred doručením.', $payload['note']);
        $this->assertSame(['ps_order_id' => 16, 'ps_order_reference' => 'XKBKNABJK', 'site' => 'shop.example'], $payload['metadata']);
    }

    public function testNoCodForOtherPaymentModules(): void
    {
        $payload = OrderPayloadBuilder::build($this->snapshot(['payment_module' => 'ps_wirepayment']), $this->config());

        $this->assertArrayNotHasKey('cod_cents', $payload);
        $this->assertSame(6220, $payload['total_cents']);
    }

    public function testUnmappedCarrierAndNoPickupPointAreOmitted(): void
    {
        $payload = OrderPayloadBuilder::build($this->snapshot(['carrier_reference' => 99, 'pickup_point_id' => ' ']), $this->config());

        $this->assertArrayNotHasKey('carrier', $payload);
        $this->assertArrayNotHasKey('pickup_point_id', $payload);
    }

    public function testSplitCartOrdersGetTheOrderIdInTheReference(): void
    {
        $this->assertSame('XKBKNABJK-16', OrderPayloadBuilder::build($this->snapshot(['shared_reference' => true]), $this->config())['reference']);
        $this->assertSame('16', OrderPayloadBuilder::reference(['id' => 16, 'reference' => '']));
    }

    public function testItemsCarryTheExternalIdOfTheOrderLine(): void
    {
        $payload = OrderPayloadBuilder::build($this->snapshot(['items' => [
            ['sku' => 'KOL-300', 'external_id' => 'ps:1:12:0', 'name' => 'Kolagén', 'quantity' => 2, 'total' => 49.8],
            ['sku' => 'TRI-M', 'external_id' => 'ps:1:15:31', 'name' => 'Tričko M', 'quantity' => 1, 'total' => 15.0],
            ['sku' => 'TRI-M', 'external_id' => 'ps:1:15:31', 'name' => 'Tričko M', 'quantity' => 1, 'total' => 15.0],
        ]]), $this->config());

        $this->assertSame([
            ['sku' => 'KOL-300', 'external_id' => 'ps:1:12:0', 'name' => 'Kolagén', 'quantity' => 2, 'unit_price_cents' => 2490],
            ['sku' => 'TRI-M', 'external_id' => 'ps:1:15:31', 'name' => 'Tričko M', 'quantity' => 2, 'unit_price_cents' => 1500],
        ], $payload['items']);
    }

    public function testMergesDuplicateSkusAndSkipsZeroQuantities(): void
    {
        $payload = OrderPayloadBuilder::build($this->snapshot(['items' => [
            ['sku' => 'A', 'name' => 'A', 'quantity' => 1, 'total' => 10.0],
            ['sku' => 'A', 'name' => 'A', 'quantity' => 2, 'total' => 14.0],
            ['sku' => 'B', 'name' => 'B', 'quantity' => 0, 'total' => 0.0],
        ]]), $this->config());

        $this->assertSame([['sku' => 'A', 'name' => 'A', 'quantity' => 3, 'unit_price_cents' => 800]], $payload['items']);
    }

    public function testUsesTheInvoiceAddressWhenTheDeliveryAddressHasNoStreet(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['delivery']['address1'] = '';
        $snapshot['invoice']['address1'] = 'Fakturačná 1';
        $snapshot['invoice']['address2'] = '';
        $snapshot['invoice']['country'] = '';

        $payload = OrderPayloadBuilder::build($snapshot, $this->config());

        $this->assertSame('Fakturačná 1', $payload['shipping_address']['street']);
        $this->assertSame('SK', $payload['shipping_address']['country'], 'Shop country is the fallback.');
    }

    public function testMobilePhoneWinsAndFallsBackToTheInvoiceAddress(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['delivery']['phone'] = '+421911000111';
        $this->assertSame('+421900123456', OrderPayloadBuilder::build($snapshot, $this->config())['customer_phone']);

        $snapshot['delivery']['phone'] = '';
        $snapshot['delivery']['phone_mobile'] = '';
        $snapshot['invoice']['phone_mobile'] = '';
        $snapshot['invoice']['phone'] = '02 123 456';
        $this->assertSame('02 123 456', OrderPayloadBuilder::build($snapshot, $this->config())['customer_phone']);
    }

    public function testCompanyIsTheNameWhenThereIsNoPersonName(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['delivery']['firstname'] = '';
        $snapshot['delivery']['lastname'] = '';
        $snapshot['delivery']['company'] = 'RFPACK s.r.o.';

        $address = OrderPayloadBuilder::build($snapshot, $this->config())['shipping_address'];

        $this->assertSame('RFPACK s.r.o.', $address['name']);
        $this->assertSame('RFPACK s.r.o.', $address['company']);
    }

    public function testRefusesLinesWithoutSkuAndNamesTheLine(): void
    {
        try {
            OrderPayloadBuilder::build($this->snapshot(['items' => [['sku' => ' ', 'name' => 'Darčeková taška', 'quantity' => 1, 'total' => 1.5]]]), $this->config());
            $this->fail('MappingException expected.');
        } catch (MappingException $exception) {
            $this->assertSame(MappingException::NO_SKU, $exception->reason);
            $this->assertSame('Darčeková taška', $exception->subject);
            $this->assertStringContainsString('Darčeková taška', $exception->localizedMessage());
        }
    }

    public function testRefusesOrdersWithoutItemsOrAddress(): void
    {
        try {
            OrderPayloadBuilder::build($this->snapshot(['items' => []]), $this->config());
            $this->fail('MappingException expected.');
        } catch (MappingException $exception) {
            $this->assertSame(MappingException::NO_ITEMS, $exception->reason);
        }

        $snapshot = $this->snapshot();
        $snapshot['delivery']['address1'] = '';
        $snapshot['invoice'] = [];

        try {
            OrderPayloadBuilder::build($snapshot, $this->config());
            $this->fail('MappingException expected.');
        } catch (MappingException $exception) {
            $this->assertSame(MappingException::NO_ADDRESS, $exception->reason);
        }
    }

    public function testLongValuesAreTruncatedToTheApiLimits(): void
    {
        $payload = OrderPayloadBuilder::build($this->snapshot(['customer_note' => str_repeat('á', 2100)]), $this->config());

        $this->assertSame(2000, mb_strlen($payload['note']));
    }

    public function testAddressFingerprintIgnoresEmptyFieldsKeyOrderAndCountryCase(): void
    {
        $sent = ['name' => 'Jana Testová', 'street' => 'Obchodná 12', 'city' => 'Bratislava', 'postal_code' => '811 06', 'country' => 'SK'];
        $back = ['country' => 'sk', 'city' => 'Bratislava', 'company' => null, 'postal_code' => '811 06', 'street' => 'Obchodná 12 ', 'name' => 'Jana Testová'];

        $this->assertSame(OrderPayloadBuilder::addressFingerprint($sent), OrderPayloadBuilder::addressFingerprint($back));
        $this->assertNotSame(OrderPayloadBuilder::addressFingerprint($sent), OrderPayloadBuilder::addressFingerprint(['street' => 'Nová 99'] + $sent));
    }
}
