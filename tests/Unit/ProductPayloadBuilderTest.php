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
use SkladX\PrestaShop\Sync\ProductPayloadBuilder;

final class ProductPayloadBuilderTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function product(array $overrides = []): array
    {
        return array_replace([
            'sku' => 'PS-PRO-30',
            'name' => 'Probiotiká 30 kps',
            'ean' => '2000 0000 0108 1',
            'weight' => '0.06',
            'length' => '10',
            'width' => '6',
            'height' => '6,5',
            'price' => '19.90',
            'image_url' => 'https://shop.example/8-large_default/probiotika.jpg',
            'archived' => false,
            'storage_class' => 'chilled',
        ], $overrides);
    }

    public function testConvertsUnitsFromKgAndCm(): void
    {
        $this->assertSame([
            'sku' => 'PS-PRO-30',
            'name' => 'Probiotiká 30 kps',
            'ean' => '2000000001081',
            'weight_grams' => 60,
            'dimensions_mm' => ['length' => 100, 'width' => 60, 'height' => 65],
            'price_cents' => 1990,
            'currency' => 'EUR',
            'image_url' => 'https://shop.example/8-large_default/probiotika.jpg',
            'storage_class' => 'chilled',
            'archived' => false,
        ], ProductPayloadBuilder::build($this->product(), 'kg', 'cm', 'eur'));
    }

    public function testConvertsOtherUnits(): void
    {
        $payload = ProductPayloadBuilder::build($this->product(['weight' => '1', 'length' => '1', 'width' => '', 'height' => '']), 'lb', 'in', 'EUR');

        $this->assertSame(454, $payload['weight_grams']);
        $this->assertSame(['length' => 25], $payload['dimensions_mm']);

        $grams = ProductPayloadBuilder::build($this->product(['weight' => '120']), 'g', 'mm', 'EUR');
        $this->assertSame(120, $grams['weight_grams']);
    }

    public function testOmitsEmptyAndZeroValues(): void
    {
        $payload = ProductPayloadBuilder::build(
            $this->product(['ean' => '', 'weight' => '0', 'length' => '0', 'width' => '', 'height' => '', 'price' => '', 'image_url' => '', 'storage_class' => '']),
            'kg',
            'cm',
            'EUR'
        );

        $this->assertSame(['sku' => 'PS-PRO-30', 'name' => 'Probiotiká 30 kps', 'archived' => false], $payload);
    }

    public function testUnknownUnitsAndInvalidValuesAreDropped(): void
    {
        $payload = ProductPayloadBuilder::build($this->product(['weight' => 'abc', 'image_url' => '/relative.jpg', 'storage_class' => 'Bad Code!']), 'stone', 'furlong', 'EUR');

        $this->assertArrayNotHasKey('weight_grams', $payload);
        $this->assertArrayNotHasKey('dimensions_mm', $payload);
        $this->assertArrayNotHasKey('image_url', $payload);
        $this->assertArrayNotHasKey('storage_class', $payload);
    }

    public function testStorageClassIsNormalised(): void
    {
        $this->assertSame('hanging_garment', ProductPayloadBuilder::storageClass(' Hanging_Garment '));
        $this->assertSame('custom-cold-1', ProductPayloadBuilder::storageClass('custom-cold-1'));
        $this->assertSame('', ProductPayloadBuilder::storageClass('chlad 2-8'));
    }

    public function testArchivedProductsAreSentAsArchived(): void
    {
        $this->assertTrue(ProductPayloadBuilder::build($this->product(['archived' => true]), 'kg', 'cm', 'EUR')['archived']);
    }

    public function testRequiresASku(): void
    {
        $this->expectException(MappingException::class);

        ProductPayloadBuilder::build($this->product(['sku' => '  ']), 'kg', 'cm', 'EUR');
    }
}
