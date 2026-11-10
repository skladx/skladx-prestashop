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
use SkladX\PrestaShop\Api\Client;
use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Queue\Queue;
use SkladX\PrestaShop\Queue\RetryLater;
use SkladX\PrestaShop\Support\Logger;
use SkladX\PrestaShop\Support\Notices;
use SkladX\PrestaShop\Support\T;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Product save → queue → `POST /products` (upsert by SKU = reference).
 *
 * A product without combinations is one SkladX product; a product with
 * combinations sends every combination that has its own reference (the
 * parent is not a stock item). "Sync all products" walks the catalogue in
 * batches of BATCH_SIZE. Saves that do not change the payload are skipped.
 */
final class ProductSync
{
    public const BATCH_SIZE = 25;

    /** True while SkladX data is written into PrestaShop (prevents echo syncs). */
    public static bool $applyingRemote = false;

    /**
     * @return int queue job id, 0 when nothing was queued
     */
    public static function queue(int $idProduct): int
    {
        if ($idProduct <= 0 || self::$applyingRemote || !Settings::productSyncEnabled() || !Settings::isConfigured()) {
            return 0;
        }

        return Queue::push(Queue::PRODUCT_SYNC, (string) $idProduct);
    }

    public static function queueAll(): void
    {
        Queue::push(Queue::PRODUCTS_ALL, '1', ['after' => 0]);
    }

    /**
     * Queue handler for one page of the catalogue.
     *
     * @param array<string, mixed> $payload `{after: last id_product}`
     */
    public static function syncBatch(array $payload): void
    {
        $after = (int) ($payload['after'] ?? 0);
        $rows = \Db::getInstance()->executeS('SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product` WHERE `id_product` > ' . $after . ' ORDER BY `id_product` LIMIT ' . self::BATCH_SIZE, true, false);
        $ids = array_map(static fn (array $row): int => (int) $row['id_product'], is_array($rows) ? $rows : []);

        foreach ($ids as $idProduct) {
            try {
                self::sync($idProduct, false, true);
            } catch (RetryLater $exception) {
                Queue::push(Queue::PRODUCT_SYNC, (string) $idProduct, [], $exception->delay);
            }
        }

        if (count($ids) === self::BATCH_SIZE) {
            Queue::push(Queue::PRODUCTS_ALL, (string) end($ids), ['after' => end($ids)]);
        } else {
            Logger::info('Full product sync finished.');
        }
    }

    /**
     * Pushes a product (and its combinations).
     *
     * @param bool $notify add an admin notice on failure
     * @param bool $force send even when nothing relevant changed
     *
     * @return array{synced: int, failed: int, skipped: int}
     *
     * @throws RetryLater when SkladX is unreachable
     */
    public static function sync(int $idProduct, bool $notify = true, bool $force = false): array
    {
        $result = ['synced' => 0, 'failed' => 0, 'skipped' => 0];
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $product = new \Product($idProduct, false, $idLang);

        if (!\Validate::isLoadedObject($product)) {
            return $result;
        }

        $retry = null;

        foreach (self::units($product) as $idProductAttribute) {
            try {
                $outcome = self::syncUnit($product, $idProductAttribute, $notify, $force);
            } catch (RetryLater $exception) {
                $retry = $exception;
                $outcome = 'failed';
            }

            ++$result[$outcome];
        }

        if ($retry !== null) {
            throw $retry;
        }

        return $result;
    }

    /**
     * Stock units of a product: 0 for a product without combinations, else
     * the combination ids.
     *
     * @return list<int>
     */
    public static function units(\Product $product): array
    {
        $rows = \Db::getInstance()->executeS('SELECT `id_product_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product` = ' . (int) $product->id . ' ORDER BY `id_product_attribute`', true, false);
        $ids = array_map(static fn (array $row): int => (int) $row['id_product_attribute'], is_array($rows) ? $rows : []);

        return $ids !== [] ? $ids : [0];
    }

    /**
     * @return string synced|failed|skipped
     *
     * @throws RetryLater
     */
    private static function syncUnit(\Product $product, int $idProductAttribute, bool $notify, bool $force): string
    {
        $idProduct = (int) $product->id;

        try {
            $payload = self::payload($product, $idProductAttribute);
        } catch (MappingException $exception) {
            Logger::debug(sprintf('Product %d/%d skipped: no reference.', $idProduct, $idProductAttribute));

            return 'skipped';
        }

        $hash = md5((string) json_encode($payload));
        $state = ProductRepository::get($idProduct, $idProductAttribute);

        if (!$force && $hash === $state['sync_hash'] && $state['sync_error'] === '') {
            return 'skipped';
        }

        try {
            $remote = Client::fromSettings()->upsertProduct($payload);
            ProductRepository::save($idProduct, $idProductAttribute, ['synced_at' => time(), 'sync_hash' => $hash, 'sync_error' => '']);
            Logger::info(sprintf('Product %s synced (%s).', (string) $payload['sku'], (string) ($remote['id'] ?? '?')));

            return 'synced';
        } catch (ApiException $exception) {
            ProductRepository::save($idProduct, $idProductAttribute, ['sync_error' => $exception->summary(), 'sync_hash' => '']);
            Logger::error(sprintf('Product %s sync failed: %s', (string) $payload['sku'], $exception->summary()));

            if ($exception->isRetryable()) {
                throw new RetryLater(300, $exception->summary());
            }

            if ($notify) {
                Notices::add(T::f('Product %1$s could not be synced to SkladX: %2$s', (string) $payload['sku'], $exception->summary()), 'danger');
            }

            return 'failed';
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MappingException
     */
    public static function payload(\Product $product, int $idProductAttribute = 0): array
    {
        $payload = ProductPayloadBuilder::build(
            self::snapshot($product, $idProductAttribute),
            (string) \Configuration::get('PS_WEIGHT_UNIT'),
            (string) \Configuration::get('PS_DIMENSION_UNIT'),
            Settings::defaultCurrencyIso()
        );

        \Hook::exec('actionSkladxProductPayload', ['payload' => &$payload, 'product' => $product, 'id_product_attribute' => $idProductAttribute]);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(\Product $product, int $idProductAttribute = 0, bool $archived = false): array
    {
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $name = is_array($product->name) ? (string) ($product->name[$idLang] ?? reset($product->name)) : (string) $product->name;
        $sku = (string) $product->reference;
        $ean = (string) $product->ean13;
        $weight = (float) $product->weight;
        $imageId = 0;

        if ($idProductAttribute > 0) {
            $combination = new \Combination($idProductAttribute);
            $sku = (string) $combination->reference;
            $ean = (string) $combination->ean13 !== '' ? (string) $combination->ean13 : $ean;
            $weight += (float) $combination->weight;
            $name = (string) \Product::getProductName((int) $product->id, $idProductAttribute, $idLang);
            $images = \Product::_getAttributeImageAssociations($idProductAttribute);
            $imageId = (int) ($images[0] ?? 0);
        }

        if ($imageId <= 0) {
            $cover = \Product::getCover((int) $product->id);
            $imageId = (int) ($cover['id_image'] ?? 0);
        }

        $imageUrl = '';

        if ($imageId > 0) {
            $rewrite = is_array($product->link_rewrite) ? (string) ($product->link_rewrite[$idLang] ?? reset($product->link_rewrite)) : (string) $product->link_rewrite;
            $imageUrl = (string) \Context::getContext()->link->getImageLink($rewrite !== '' ? $rewrite : 'product', (string) $imageId, \ImageType::getFormattedName('large'));
            $imageUrl = str_starts_with($imageUrl, '//') ? 'https:' . $imageUrl : $imageUrl;

            if (\Configuration::get('PS_SSL_ENABLED') && str_starts_with($imageUrl, 'http://')) {
                $imageUrl = 'https://' . substr($imageUrl, 7);
            }
        }

        return [
            'sku' => $sku,
            'name' => strip_tags($name),
            'ean' => $ean,
            'weight' => $weight > 0 ? (string) $weight : '',
            'length' => (float) $product->depth > 0 ? (string) $product->depth : '',
            'width' => (float) $product->width > 0 ? (string) $product->width : '',
            'height' => (float) $product->height > 0 ? (string) $product->height : '',
            'price' => (string) \Product::getPriceStatic((int) $product->id, true, $idProductAttribute ?: null, 2, null, false, false),
            'image_url' => $imageUrl,
            'archived' => $archived,
            'storage_class' => ProductRepository::storageClass((int) $product->id),
        ];
    }

    /**
     * Product deleted in PrestaShop: archive it in SkladX (stock history stays there).
     */
    public static function archive(\Product $product): void
    {
        if (!Settings::productSyncEnabled() || !Settings::isConfigured()) {
            return;
        }

        foreach (self::units($product) as $idProductAttribute) {
            try {
                $payload = ProductPayloadBuilder::build(self::snapshot($product, $idProductAttribute, true), (string) \Configuration::get('PS_WEIGHT_UNIT'), (string) \Configuration::get('PS_DIMENSION_UNIT'), Settings::defaultCurrencyIso());
                Client::fromSettings()->upsertProduct($payload);
            } catch (MappingException $exception) {
                continue;
            } catch (ApiException $exception) {
                Logger::error(sprintf('Archiving product %d in SkladX failed: %s', (int) $product->id, $exception->summary()));
            }
        }

        ProductRepository::delete((int) $product->id);
    }
}
