<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Inbound;

use SkladX\PrestaShop\Support\T;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Invalid receipt input (header fields, items or pasted lines). Thrown
 * before any API call. Every error has a machine `reason`, the form `field`
 * it belongs to (`name`, `items.2.quantity`…) and, for items and pasted
 * lines, the 1-based `line` number. localized() turns them into translated
 * messages; the exception message stays English (logs, unit tests).
 */
class ValidationException extends \RuntimeException
{
    public const REQUIRED = 'required';
    public const TOO_LONG = 'too_long';
    public const INVALID_DATE = 'invalid_date';
    public const INVALID_CARRIER = 'invalid_carrier';
    public const INVALID_TRACKING = 'invalid_tracking';
    public const INVALID_QUANTITY = 'invalid_quantity';
    public const QUANTITY_POSITIVE = 'quantity_positive';
    public const QUANTITY_TOO_LARGE = 'quantity_too_large';
    public const TOO_MANY_COLUMNS = 'too_many_columns';
    public const TOO_MANY_LINES = 'too_many_lines';
    public const NO_ITEMS = 'no_items';
    public const UNKNOWN_PRODUCT = 'unknown_product';

    /**
     * @param list<array{field: string, reason: string, line: int|null, value: string, max: int|null}> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode('; ', array_map(
            static fn (array $error): string => ($error['line'] !== null ? 'line ' . $error['line'] . ': ' : '') . $error['field'] . ' ' . $error['reason'],
            $errors
        )));
    }

    /**
     * One error entry.
     *
     * @return array{field: string, reason: string, line: int|null, value: string, max: int|null}
     */
    public static function error(string $field, string $reason, ?int $line = null, string $value = '', ?int $max = null): array
    {
        return [
            'field' => $field,
            'reason' => $reason,
            'line' => $line,
            'value' => $value,
            'max' => $max,
        ];
    }

    /**
     * Translated messages, one per error.
     *
     * @return list<array{field: string, line: int|null, message: string}>
     */
    public function localized(): array
    {
        return array_map(
            static fn (array $error): array => [
                'field' => $error['field'],
                'line' => $error['line'],
                'message' => self::message($error),
            ],
            $this->errors
        );
    }

    /**
     * @param array{field: string, reason: string, line: int|null, value: string, max: int|null} $error
     */
    public static function message(array $error): string
    {
        $text = self::reasonText($error);

        if ($error['line'] === null) {
            return $text;
        }

        return T::f('Line %1$d: %2$s', $error['line'], $text);
    }

    /**
     * @param array{field: string, reason: string, line: int|null, value: string, max: int|null} $error
     */
    private static function reasonText(array $error): string
    {
        $field = self::fieldLabel($error['field']);

        switch ($error['reason']) {
            case self::REQUIRED:
                return T::f('%s is required.', $field);
            case self::TOO_LONG:
                return T::f('%1$s can have at most %2$d characters.', $field, (int) $error['max']);
            case self::INVALID_DATE:
                return T::f('%1$s "%2$s" is not a valid date (use YYYY-MM-DD or DD.MM.YYYY).', $field, $error['value']);
            case self::INVALID_CARRIER:
                return T::__('Choose a carrier from the list.');
            case self::INVALID_TRACKING:
                return T::__('The tracking number may only contain letters, digits, spaces, dashes and slashes.');
            case self::INVALID_QUANTITY:
                return T::f('Quantity "%s" is not a whole number.', $error['value']);
            case self::QUANTITY_POSITIVE:
                return T::__('Quantity must be greater than 0.');
            case self::QUANTITY_TOO_LARGE:
                return T::f('Quantity can be at most %d.', (int) $error['max']);
            case self::TOO_MANY_COLUMNS:
                return T::__('Too many columns. Use: code;quantity[;batch;expiry].');
            case self::TOO_MANY_LINES:
                return T::f('At most %d lines can be added at once.', (int) $error['max']);
            case self::NO_ITEMS:
                return T::__('Add at least one item.');
            case self::UNKNOWN_PRODUCT:
                return T::f('No product with reference or EAN "%s" (products without a reference cannot be received).', $error['value']);
            default:
                return $error['field'] . ' ' . $error['reason'];
        }
    }

    private static function fieldLabel(string $field): string
    {
        $key = (string) preg_replace('/^items\.\d+\./', '', $field);

        switch ($key) {
            case 'name':
                return T::__('Name');
            case 'expected_at':
                return T::__('Expected delivery date');
            case 'carrier':
                return T::__('Carrier');
            case 'carrier_other':
                return T::__('Carrier name');
            case 'tracking_number':
                return T::__('Tracking number');
            case 'reference':
                return T::__('Reference');
            case 'note':
                return T::__('Note');
            case 'warehouse_code':
                return T::__('Warehouse');
            case 'sku':
                return T::__('Product');
            case 'code':
                return T::__('Reference / EAN');
            case 'quantity':
                return T::__('Quantity');
            case 'batch':
                return T::__('Batch');
            case 'expires_at':
                return T::__('Expiry');
            default:
                return $key;
        }
    }
}
