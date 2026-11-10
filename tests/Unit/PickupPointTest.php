<?php

namespace SkladX\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\PrestaShop\Pickup\PickupPoint;

final class PickupPointTest extends TestCase
{
    /**
     * Point as returned by GET /pickup-points.
     *
     * @return array<string, mixed>
     */
    public static function apiPoint(array $overrides = []): array
    {
        return array_replace(
            [
                'id' => 'packeta:18579',
                'carrier' => 'packeta',
                'network' => 'packeta_zbox',
                'external_id' => '18579',
                'type' => 'locker',
                'name' => 'Z-BOX Bratislava, Obchodná 12',
                'address' => ['street' => 'Obchodná 12', 'city' => 'Bratislava', 'postal_code' => '811 06', 'country' => 'SK'],
                'location' => ['lat' => 48.1486, 'lng' => 17.1077],
                'distance_km' => 0.8412,
                'opening_hours' => ['mon' => [['00:00', '24:00']]],
                'supports' => ['cod' => true, 'card' => true, 'drop_off' => true],
                'photo_url' => null,
                'is_active' => true,
                'closing_on' => null,
            ],
            $overrides
        );
    }

    public function testNormalizesApiPoint(): void
    {
        $point = PickupPoint::normalize(self::apiPoint());

        $this->assertSame('18579', $point['id']);
        $this->assertSame('packeta', $point['carrier']);
        $this->assertSame('packeta:18579', $point['ref']);
        $this->assertSame('locker', $point['type']);
        $this->assertSame('811 06', $point['postal_code']);
        $this->assertSame('SK', $point['country']);
        $this->assertSame(48.1486, $point['lat']);
        $this->assertSame(0.84, $point['distance_km']);
        $this->assertTrue($point['cod']);
        $this->assertTrue($point['active']);
        $this->assertSame('Obchodná 12, 811 06 Bratislava', PickupPoint::addressLine($point));
    }

    public function testNormalizesOtherShapesDefensively(): void
    {
        // Documented draft shape: address.zip, composite id only, no supports.
        $point = PickupPoint::normalize(
            [
                'id' => 'packeta:100',
                'name' => 'Výdajné miesto',
                'address' => ['street' => 'Hlavná 1', 'city' => 'Košice', 'zip' => '040 01', 'country' => 'sk'],
            ]
        );

        $this->assertSame('100', $point['id']);
        $this->assertSame('packeta', $point['carrier']);
        $this->assertSame('040 01', $point['postal_code']);
        $this->assertSame('SK', $point['country']);
        $this->assertNull($point['cod']);
        $this->assertNull($point['lat']);

        // Flat fields and the carrier taken from the query.
        $flat = PickupPoint::normalize(['id' => '7', 'name' => 'X', 'city' => 'Nitra', 'lat' => '48.3', 'lng' => '18.08', 'supports_cod' => false], 'packeta');

        $this->assertSame('packeta:7', $flat['ref']);
        $this->assertSame('Nitra', $flat['city']);
        $this->assertSame(48.3, $flat['lat']);
        $this->assertFalse($flat['cod']);

        $this->assertNull(PickupPoint::normalize(['name' => 'no id'], 'packeta'));
        $this->assertFalse(PickupPoint::normalize(self::apiPoint(['is_active' => false]))['active']);
    }

    public function testSanitizesProxyQuery(): void
    {
        $query = PickupPoint::sanitizeQuery(
            ['carrier' => 'Packeta', 'q' => '  Banská   Bystrica ', 'cod' => '1', 'cursor' => 'eyJpZCI6MjB9', 'foo' => 'bar'],
            ['packeta'],
            'SK'
        );

        $this->assertSame(
            ['carrier' => 'packeta', 'country' => 'SK', 'limit' => PickupPoint::PAGE_SIZE, 'q' => 'Banská Bystrica', 'cod' => 1, 'cursor' => 'eyJpZCI6MjB9'],
            $query
        );

        $near = PickupPoint::sanitizeQuery(['carrier' => 'packeta', 'near' => '48.148612, 17.107712', 'country' => 'cz', 'cod' => 'false', 'cursor' => 'bad cursor!'], ['packeta'], 'SK');

        $this->assertSame('48.149,17.108', $near['near']);
        $this->assertSame(PickupPoint::NEAR_RADIUS_KM, $near['radius_km']);
        $this->assertSame('CZ', $near['country']);
        $this->assertArrayNotHasKey('cod', $near);
        $this->assertArrayNotHasKey('cursor', $near);
        $this->assertSame(100, mb_strlen(PickupPoint::sanitizeQuery(['carrier' => 'packeta', 'q' => str_repeat('á', 300)], ['packeta'], 'SK')['q']));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalid_queries(): iterable
    {
        yield 'carrier not allowed' => [['carrier' => 'dpd_sk'], 'invalid_carrier'];
        yield 'no carrier' => [[], 'invalid_carrier'];
        yield 'bad near' => [['carrier' => 'packeta', 'near' => 'Bratislava'], 'invalid_near'];
        yield 'near out of range' => [['carrier' => 'packeta', 'near' => '95,17'], 'invalid_near'];
        yield 'bad country' => [['carrier' => 'packeta', 'country' => 'Slovakia'], 'invalid_country'];
    }

    /**
     * @dataProvider invalid_queries
     *
     * @param array<string, mixed> $params params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_queries')]
    public function testRejectsInvalidQueries(array $params, string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($code);

        PickupPoint::sanitizeQuery($params, ['packeta'], 'SK');
    }

    public function testCacheKeyIgnoresArgumentOrder(): void
    {
        $this->assertSame(
            PickupPoint::cacheKey(['carrier' => 'packeta', 'q' => 'Nitra']),
            PickupPoint::cacheKey(['q' => 'Nitra', 'carrier' => 'packeta'])
        );
        $this->assertNotSame(PickupPoint::cacheKey(['q' => 'Nitra']), PickupPoint::cacheKey(['q' => 'Trnava']));
    }

    public function testValidatesChosenPoint(): void
    {
        $point = PickupPoint::normalize(self::apiPoint());

        $this->assertSame('', PickupPoint::problem($point, 'packeta', true));
        $this->assertSame('missing', PickupPoint::problem(null, 'packeta', false));
        $this->assertSame('wrong_carrier', PickupPoint::problem($point, 'gls_sk', false));
        $this->assertSame('inactive', PickupPoint::problem(array_merge($point, ['active' => false]), 'packeta', false));
        $this->assertSame('no_cod', PickupPoint::problem(array_merge($point, ['cod' => false]), 'packeta', true));
        $this->assertSame('', PickupPoint::problem(array_merge($point, ['cod' => false]), 'packeta', false));
        // Unknown COD support is left to SkladX.
        $this->assertSame('', PickupPoint::problem(array_merge($point, ['cod' => null]), 'packeta', true));
    }

    public function testFormatsOpeningHours(): void
    {
        $labels = ['mon' => 'Po', 'tue' => 'Ut', 'wed' => 'St', 'thu' => 'Št', 'fri' => 'Pi', 'sat' => 'So', 'sun' => 'Ne', 'closed' => 'zatvorené', 'nonstop' => 'nonstop'];
        $week = [['09:00', '12:00'], ['13:00', '17:00']];

        $this->assertSame(
            ['Po–Pi 09:00–12:00, 13:00–17:00', 'So 09:00–12:00', 'Ne zatvorené'],
            PickupPoint::openingHoursLines(
                ['mon' => $week, 'tue' => $week, 'wed' => $week, 'thu' => $week, 'fri' => $week, 'sat' => [['09:00', '12:00']], 'sun' => [], 'exceptions' => []],
                $labels
            )
        );

        $nonstop = array_fill_keys(PickupPoint::DAYS, [['00:00', '24:00']]);
        $this->assertSame(['Po–Ne nonstop'], PickupPoint::openingHoursLines($nonstop, $labels));
        $this->assertSame(['Po nonstop'], PickupPoint::openingHoursLines(['mon' => [['00:00', '24:00']]], $labels));
        $this->assertSame([], PickupPoint::openingHoursLines(null, $labels));
        $this->assertSame([], PickupPoint::openingHoursLines(['exceptions' => []], $labels));
    }

    public function testWidgetFallbackIsMarkedUnverified(): void
    {
        $point = PickupPoint::fromWidget('packeta', '123', ['name' => 'Packeta Nitra', 'street' => 'Štefánikova 1', 'city' => 'Nitra', 'zip' => '949 01', 'country' => 'sk']);

        $this->assertSame('123', $point['id']);
        $this->assertSame('949 01', $point['postal_code']);
        $this->assertTrue($point['unverified']);
        $this->assertNull(PickupPoint::fromWidget('packeta', '123', []));
    }
}
