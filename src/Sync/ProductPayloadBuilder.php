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
 * Turns a product snapshot (ProductSnapshot, a plain array) into the SkladX
 * `POST /products` payload and converts units: weight → grams, dimensions →
 * millimetres, price → cents. Pure PHP — unit tested.
 *
 * Snapshot: `{sku, name, ean, weight, length, width, height, price, image_url, archived, storage_class}`.
 */
final class ProductPayloadBuilder
{
    /** Built-in SkladX storage classes (a warehouse may define its own codes too). */
    public const STORAGE_CLASSES = ['ambient', 'chilled', 'frozen', 'hazardous', 'hanging_garment', 'oversize', 'high_value'];

    /** Grams per shop weight unit (PS_WEIGHT_UNIT). */
    private const WEIGHT_TO_GRAMS = [
        'g' => 1.0,
        'kg' => 1000.0,
        'lb' => 453.59237,
        'lbs' => 453.59237,
        'oz' => 28.349523125,
    ];

    /** Millimetres per shop dimension unit (PS_DIMENSION_UNIT). */
    private const LENGTH_TO_MM = [
        'mm' => 1.0,
        'cm' => 10.0,
        'm' => 1000.0,
        'in' => 25.4,
        'yd' => 914.4,
    ];

    /**
     * @param array<string, mixed> $product snapshot
     * @param string $weightUnit shop weight unit (PS_WEIGHT_UNIT)
     * @param string $lengthUnit shop dimension unit (PS_DIMENSION_UNIT)
     * @param string $currency shop default currency ISO code
     *
     * @return array<string, mixed>
     *
     * @throws MappingException when the product has no SKU
     */
    public static function build(array $product, string $weightUnit, string $lengthUnit, string $currency): array
    {
        $sku = trim((string) ($product['sku'] ?? ''));

        if ($sku === '') {
            throw new MappingException('Product has no SKU.', MappingException::PRODUCT_NO_SKU);
        }

        $lengthFactor = self::LENGTH_TO_MM[strtolower(trim($lengthUnit))] ?? null;

        $dimensions = array_filter(
            [
                'length' => self::convert($product['length'] ?? '', $lengthFactor),
                'width' => self::convert($product['width'] ?? '', $lengthFactor),
                'height' => self::convert($product['height'] ?? '', $lengthFactor),
            ],
            static fn (?int $value): bool => $value !== null && $value > 0
        );

        $ean = (string) preg_replace('/\s+/', '', (string) ($product['ean'] ?? ''));
        $price = self::convert($product['price'] ?? '', 100.0);
        $weight = self::convert($product['weight'] ?? '', self::WEIGHT_TO_GRAMS[strtolower(trim($weightUnit))] ?? null);
        $name = trim((string) ($product['name'] ?? ''));
        $storageClass = self::storageClass((string) ($product['storage_class'] ?? ''));
        $image = (string) ($product['image_url'] ?? '');

        $payload = [
            'sku' => mb_substr($sku, 0, 64),
            'name' => mb_substr($name !== '' ? $name : $sku, 0, 255),
            'ean' => $ean !== '' && $ean !== '0' ? mb_substr($ean, 0, 32) : null,
            'weight_grams' => $weight !== null && $weight > 0 ? $weight : null,
            'dimensions_mm' => $dimensions !== [] ? $dimensions : null,
            'price_cents' => $price,
            'currency' => $price !== null && $currency !== '' ? strtoupper($currency) : null,
            'image_url' => preg_match('~^https?://~i', $image) ? $image : null,
            'storage_class' => $storageClass !== '' ? $storageClass : null,
            'archived' => (bool) ($product['archived'] ?? false),
        ];

        return array_filter($payload, static fn ($value): bool => $value !== null);
    }

    /**
     * Normalises a storage class code: lower case letters, digits, `_` and `-`
     * ('' when empty or invalid).
     */
    public static function storageClass(string $code): string
    {
        $code = strtolower(trim($code));

        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $code) ? $code : '';
    }

    /**
     * Converts a decimal string (dot or comma) by a factor to a rounded integer.
     *
     * @param mixed $value raw value
     * @param float|null $factor multiplier, null when the unit is unknown
     */
    private static function convert($value, ?float $factor): ?int
    {
        $value = str_replace(',', '.', trim(is_scalar($value) ? (string) $value : ''));

        if ($factor === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return max(0, (int) round((float) $value * $factor));
    }
}
