<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Sync;

use SkladX\PrestaShop\Api\ApiException;
use SkladX\PrestaShop\Support\T;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * HTTP 409 `sku_conflict` of `POST /products`: the SKU (reference) the shop
 * sends already belongs to another SkladX product. Details:
 * `{sku, external_id, conflicting_product: {id, sku, name, external_id}, product: {id, sku, name}|null}`
 * (`product` = the SkladX product of this external_id that could not be
 * renamed). Not retried — the shop admin has to resolve it. Pure PHP — unit tested.
 */
final class SkuConflict
{
    public const ERROR_CODE = 'sku_conflict';

    /**
     * @param array{id: string, sku: string, name: string, external_id: string} $owner SkladX product that owns the SKU
     * @param array{id: string, sku: string, name: string}|null $product SkladX product of this external_id
     */
    public function __construct(
        public readonly string $sku,
        public readonly string $externalId,
        public readonly array $owner,
        public readonly ?array $product,
        public readonly string $serverMessage = '',
    ) {
    }

    public static function fromException(ApiException $exception): ?self
    {
        return $exception->isSkuConflict() ? self::fromDetails($exception->details, $exception->getMessage()) : null;
    }

    /**
     * @param array<string, mixed> $details `error.details` of the 409 response
     */
    public static function fromDetails(array $details, string $serverMessage = ''): self
    {
        $owner = is_array($details['conflicting_product'] ?? null) ? $details['conflicting_product'] : [];
        $product = is_array($details['product'] ?? null) ? $details['product'] : null;

        return new self(
            self::text($details['sku'] ?? ''),
            self::text($details['external_id'] ?? ''),
            [
                'id' => self::text($owner['id'] ?? ''),
                'sku' => self::text($owner['sku'] ?? ''),
                'name' => self::text($owner['name'] ?? ''),
                'external_id' => self::text($owner['external_id'] ?? ''),
            ],
            $product !== null ? [
                'id' => self::text($product['id'] ?? ''),
                'sku' => self::text($product['sku'] ?? ''),
                'name' => self::text($product['name'] ?? ''),
            ] : null,
            trim($serverMessage)
        );
    }

    /**
     * Readable message for the product section and the admin notice.
     */
    public function message(): string
    {
        $sku = $this->sku !== '' ? $this->sku : $this->owner['sku'];

        if ($this->owner['id'] === '' && $this->owner['name'] === '') {
            $text = $this->serverMessage !== '' ? $this->serverMessage : T::f('SKU %s already belongs to another SkladX product.', $sku);
        } else {
            $owner = $this->owner['name'] !== '' ? $this->owner['name'] : $this->owner['sku'];
            $ownerId = $this->owner['id'] !== '' ? $this->owner['id'] : '?';

            if ($this->product !== null && $this->product['sku'] !== '' && $this->product['sku'] !== $sku) {
                $text = T::f('Cannot change SKU %1$s to %2$s in SkladX: SKU %2$s already belongs to another SkladX product "%3$s" (%4$s).', $this->product['sku'], $sku, $owner, $ownerId);
            } else {
                $text = T::f('SKU %1$s already belongs to another SkladX product "%2$s" (%3$s).', $sku, $owner, $ownerId);
            }

            if ($this->owner['external_id'] !== '' && $this->owner['external_id'] !== $this->externalId) {
                $text .= ' ' . T::f('That product is linked to the shop product %s.', $this->owner['external_id']);
            }
        }

        return $text . ' ' . T::__('Change the reference in PrestaShop or resolve the duplicate in SkladX, then click Sync to SkladX now.');
    }

    /**
     * @param mixed $value
     */
    private static function text($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
