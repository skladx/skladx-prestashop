<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Sync;

use SkladX\PrestaShop\Support\T;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A PrestaShop object cannot be mapped to a SkladX payload. Thrown before any
 * API call, e.g. when an order line has no reference (SKU).
 *
 * The message stays English (logs, unit tests); localizedMessage() is what
 * the shop admin sees in order messages and notices.
 */
class MappingException extends \RuntimeException
{
    public const NO_SKU = 'no_sku';
    public const NO_ITEMS = 'no_items';
    public const NO_ADDRESS = 'no_address';
    public const PRODUCT_NO_SKU = 'product_no_sku';

    /**
     * @param string $message English message
     * @param string $reason one of the constants ('' when unknown)
     * @param string $subject what the reason is about (e.g. the order line name)
     */
    public function __construct(string $message, public readonly string $reason = '', public readonly string $subject = '')
    {
        parent::__construct($message);
    }

    /**
     * Translated message with a hint how to fix the problem.
     */
    public function localizedMessage(): string
    {
        switch ($this->reason) {
            case self::NO_SKU:
                return T::f('Order line "%s" has no reference (SKU). Add the reference to the product (SkladX matches products by SKU), then resend the order.', $this->subject);
            case self::NO_ITEMS:
                return T::__('The order has no products to ship.');
            case self::NO_ADDRESS:
                return T::__('The order has no delivery or invoice street address. Fill in the address, then resend the order.');
            case self::PRODUCT_NO_SKU:
                return T::__('The product has no reference (SKU), it cannot be sent to SkladX.');
            default:
                return $this->getMessage();
        }
    }
}
