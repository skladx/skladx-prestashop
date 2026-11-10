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
use SkladX\PrestaShop\Queue\Backoff;
use SkladX\PrestaShop\Queue\Queue;
use SkladX\PrestaShop\Queue\RetryLater;
use SkladX\PrestaShop\Support\Logger;
use SkladX\PrestaShop\Support\Notices;
use SkladX\PrestaShop\Support\T;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Product save → `POST /products` (upsert by external_id, then by SKU = reference).
 *
 * A product without combinations is one SkladX product; a product with
 * combinations sends every combination that has its own reference (the
 * parent is not a stock item). Every unit carries its stable external_id
 * (ExternalId), so a changed reference renames the SKU in SkladX.
 *
 * Saves are collected during the request (schedule(), de-duplicated) and
 * pushed at its end with a short timeout; when SkladX is unreachable (network,
 * 429, 5xx) the product goes to the database queue with backoff. A 409
 * `sku_conflict` is stored and shown to the admin and the same payload is
 * not sent again. "Sync all products" walks the catalogue in batches of
 * BATCH_SIZE. Saves that do not change the payload are skipped.
 */
final class ProductSync
{
    public const BATCH_SIZE = 25;

    /** Most products pushed at the end of one request (more, e.g. a CSV import, go to the queue). */
    public const IMMEDIATE_LIMIT = 20;

    /** Request timeout (seconds) of a push at the end of a request. */
    public const IMMEDIATE_TIMEOUT = 8;

    /** Time budget (seconds) of all pushes at the end of one request. */
    public const IMMEDIATE_BUDGET = 20.0;

    /** True while SkladX data is written into PrestaShop (prevents echo syncs). */
    public static bool $applyingRemote = false;

    /** @var array<int, true> products saved during this request */
    private static array $pending = [];

    private static bool $flushRegistered = false;

    /**
     * @return int queue job id, 0 when nothing was queued
     */
    public static function queue(int $idProduct, int $delay = 0): int
    {
        if (!self::enabled($idProduct)) {
            return 0;
        }

        return Queue::push(Queue::PRODUCT_SYNC, (string) $idProduct, [], $delay);
    }

    /**
     * Remembers a saved product; it is pushed to SkladX once at the end of
     * the request (after the response when PHP-FPM allows it).
     */
    public static function schedule(int $idProduct): void
    {
        if (!self::enabled($idProduct)) {
            return;
        }

        self::$pending[$idProduct] = true;

        if (!self::$flushRegistered) {
            self::$flushRegistered = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    /**
     * Shutdown handler: pushes the products saved in this request. Products
     * over IMMEDIATE_LIMIT / the time budget, and all of them once SkladX
     * did not answer, go to the queue.
     */
    public static function flush(): void
    {
        $ids = array_keys(self::$pending);
        self::$pending = [];

        if ($ids === []) {
            return;
        }

        if (function_exists('fastcgi_finish_request') && connection_status() === CONNECTION_NORMAL) {
            @fastcgi_finish_request();
        }

        ignore_user_abort(true);
        @set_time_limit(60);

        $started = microtime(true);
        $client = Client::fromSettings(self::IMMEDIATE_TIMEOUT);
        $unreachable = false;

        foreach ($ids as $index => $idProduct) {
            if ($unreachable || $index >= self::IMMEDIATE_LIMIT || microtime(true) - $started > self::IMMEDIATE_BUDGET) {
                self::queue($idProduct);
                continue;
            }

            try {
                self::sync($idProduct, true, false, $client);
            } catch (RetryLater $exception) {
                $unreachable = true;
                self::queue($idProduct, (int) Backoff::delay(1, Backoff::PRODUCT_SYNC));
                Logger::info(sprintf('Product %d queued for a retry: %s', $idProduct, $exception->getMessage()));
            } catch (\Throwable $exception) {
                Logger::error(sprintf('Product %d sync failed: %s', $idProduct, $exception->getMessage()));
            }
        }
    }

    /**
     * Queue handler for one product: retries with Backoff::PRODUCT_SYNC.
     *
     * @param int $attempts attempts including this one
     *
     * @throws RetryLater while attempts are left
     * @throws \RuntimeException when the last attempt failed
     */
    public static function syncQueued(int $idProduct, int $attempts): void
    {
        try {
            self::sync($idProduct);
        } catch (RetryLater $exception) {
            $delay = Backoff::delay(max(1, $attempts), Backoff::PRODUCT_SYNC);

            if ($delay !== null) {
                throw new RetryLater($delay, $exception->getMessage());
            }

            Notices::add(T::f('Product %1$d could not be synced to SkladX after several attempts: %2$s', $idProduct, $exception->getMessage()), 'danger', self::adminLink($idProduct), T::__('Open product'));

            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }
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
     * Pushes a product (and its combinations). Stops at the first unit
     * SkladX does not answer for; the queue retries the whole product.
     *
     * @param bool $notify add an admin notice on failure
     * @param bool $force send even when nothing relevant changed (also after an SKU conflict)
     *
     * @return array{synced: int, failed: int, skipped: int}
     *
     * @throws RetryLater when SkladX is unreachable
     */
    public static function sync(int $idProduct, bool $notify = true, bool $force = false, ?Client $client = null): array
    {
        $result = ['synced' => 0, 'failed' => 0, 'skipped' => 0];
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $product = new \Product($idProduct, false, $idLang);

        if (!\Validate::isLoadedObject($product)) {
            return $result;
        }

        $client ??= Client::fromSettings();

        foreach (self::units($product) as $idProductAttribute) {
            ++$result[self::syncUnit($product, $idProductAttribute, $notify, $force, $client)];
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
    private static function syncUnit(\Product $product, int $idProductAttribute, bool $notify, bool $force, Client $client): string
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

        if (!ProductSyncPolicy::shouldSend($hash, $state, $force)) {
            return 'skipped';
        }

        try {
            $remote = $client->upsertProduct($payload);
            ProductRepository::save($idProduct, $idProductAttribute, ['synced_at' => time(), 'sync_hash' => $hash, 'sync_error' => '', 'sync_error_code' => '']);
            Logger::info(sprintf('Product %s (%s) synced (%s).', (string) $payload['sku'], (string) ($payload['external_id'] ?? ''), (string) ($remote['id'] ?? '?')));

            return 'synced';
        } catch (ApiException $exception) {
            $kind = ProductSyncPolicy::classify($exception);
            $conflict = $kind === ProductSyncPolicy::CONFLICT ? SkuConflict::fromException($exception) : null;
            $message = $conflict !== null ? $conflict->message() : $exception->summary();

            // An SKU conflict keeps the payload hash: the same payload is not sent again until the product changes.
            ProductRepository::save($idProduct, $idProductAttribute, [
                'sync_error' => $message,
                'sync_error_code' => mb_substr($exception->errorCode, 0, 32),
                'sync_hash' => $conflict !== null ? $hash : '',
            ]);
            Logger::error(sprintf('Product %s sync failed: %s', (string) $payload['sku'], $exception->summary()));

            if ($kind === ProductSyncPolicy::RETRY) {
                throw new RetryLater((int) Backoff::delay(1, Backoff::PRODUCT_SYNC), $exception->summary());
            }

            if ($notify) {
                Notices::add(T::f('Product %1$s could not be synced to SkladX: %2$s', (string) $payload['sku'], $message), 'danger', self::adminLink($idProduct), T::__('Open product'));
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
        $idCombinationImages = [];

        if ($idProductAttribute > 0) {
            $combination = new \Combination($idProductAttribute);
            $sku = (string) $combination->reference;
            $ean = (string) $combination->ean13 !== '' ? (string) $combination->ean13 : $ean;
            $weight += (float) $combination->weight;
            $name = (string) \Product::getProductName((int) $product->id, $idProductAttribute, $idLang);
            $idCombinationImages = array_map('intval', (array) \Product::_getAttributeImageAssociations($idProductAttribute));
        }

        $images = self::imageUrls($product, $idLang, array_values(array_filter($idCombinationImages)));

        return [
            'external_id' => self::externalId($product, $idProductAttribute),
            'sku' => $sku,
            'name' => strip_tags($name),
            'ean' => $ean,
            'weight' => $weight > 0 ? (string) $weight : '',
            'length' => (float) $product->depth > 0 ? (string) $product->depth : '',
            'width' => (float) $product->width > 0 ? (string) $product->width : '',
            'height' => (float) $product->height > 0 ? (string) $product->height : '',
            'price' => (string) \Product::getPriceStatic((int) $product->id, true, $idProductAttribute ?: null, 2, null, false, false),
            'image_url' => (string) array_shift($images),
            'images' => $images,
            'archived' => $archived,
            'storage_class' => ProductRepository::storageClass((int) $product->id),
        ];
    }

    /**
     * external_id of a stock unit. The shop part is the product's default
     * shop (stable — the module settings are global, so one SkladX
     * environment serves all shops).
     */
    public static function externalId(\Product $product, int $idProductAttribute = 0): string
    {
        $idShop = (int) $product->id_shop_default;

        return ExternalId::forUnit($idShop > 0 ? $idShop : (int) \Configuration::get('PS_SHOP_DEFAULT'), (int) $product->id, $idProductAttribute);
    }

    /**
     * Image URLs of a stock unit, main image first: the combination's images
     * when it has some, else all product images; the cover comes first when
     * it is among them.
     *
     * @param list<int> $idCombinationImages images associated with the combination
     *
     * @return list<string>
     */
    private static function imageUrls(\Product $product, int $idLang, array $idCombinationImages): array
    {
        $rows = \Image::getImages($idLang, (int) $product->id);
        $ids = [];
        $cover = 0;

        foreach (is_array($rows) ? $rows : [] as $row) {
            $idImage = (int) $row['id_image'];

            if ($idCombinationImages !== [] && !in_array($idImage, $idCombinationImages, true)) {
                continue;
            }

            $ids[] = $idImage;
            $cover = !empty($row['cover']) ? $idImage : $cover;
        }

        if ($ids === [] && $idCombinationImages !== []) {
            return self::imageUrls($product, $idLang, []);
        }

        if ($cover > 0) {
            $ids = array_merge([$cover], array_values(array_diff($ids, [$cover])));
        }

        $rewrite = is_array($product->link_rewrite) ? (string) ($product->link_rewrite[$idLang] ?? reset($product->link_rewrite)) : (string) $product->link_rewrite;
        $link = \Context::getContext()->link ?? new \Link();
        $type = \ImageType::getFormattedName('large');
        $urls = [];

        foreach (array_slice($ids, 0, ProductPayloadBuilder::MAX_IMAGES + 1) as $idImage) {
            $url = (string) $link->getImageLink($rewrite !== '' ? $rewrite : 'product', (string) $idImage, $type);
            $url = str_starts_with($url, '//') ? 'https:' . $url : $url;

            if (\Configuration::get('PS_SSL_ENABLED') && str_starts_with($url, 'http://')) {
                $url = 'https://' . substr($url, 7);
            }

            if (!preg_match('~^https?://~i', $url)) {
                $url = rtrim((string) \Tools::getShopDomainSsl(true), '/') . '/' . ltrim($url, '/');
            }

            $urls[] = $url;
        }

        return $urls;
    }

    /**
     * Back office link to the product page ('' outside the back office).
     */
    public static function adminLink(int $idProduct): string
    {
        if (!defined('_PS_ADMIN_DIR_')) {
            return '';
        }

        try {
            $link = \Context::getContext()->link;

            return $link !== null ? (string) $link->getAdminLink('AdminProducts', true, ['id_product' => $idProduct], ['id_product' => $idProduct, 'updateproduct' => 1]) : '';
        } catch (\Throwable $exception) {
            return '';
        }
    }

    /**
     * Product deleted in PrestaShop: archive it in SkladX (stock history stays there).
     */
    public static function archive(\Product $product): void
    {
        if (!Settings::productSyncEnabled() || !Settings::isConfigured()) {
            return;
        }

        unset(self::$pending[(int) $product->id]);

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

    private static function enabled(int $idProduct): bool
    {
        return $idProduct > 0 && !self::$applyingRemote && Settings::productSyncEnabled() && Settings::isConfigured();
    }
}
