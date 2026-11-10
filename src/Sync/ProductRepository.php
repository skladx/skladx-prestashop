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
 * Storage class and sync state per product / combination (`skladx_product`).
 * The storage class is set on the product (id_product_attribute 0) and used
 * for all its combinations.
 */
final class ProductRepository
{
    public const TABLE = 'skladx_product';

    /**
     * @return array{storage_class: string, synced_at: int, sync_hash: string, sync_error: string, stock_event_id: string}
     */
    public static function get(int $idProduct, int $idProductAttribute = 0): array
    {
        $row = \Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_product` = ' . $idProduct . ' AND `id_product_attribute` = ' . $idProductAttribute, false);

        return [
            'storage_class' => (string) ($row['storage_class'] ?? ''),
            'synced_at' => (int) ($row['synced_at'] ?? 0),
            'sync_hash' => (string) ($row['sync_hash'] ?? ''),
            'sync_error' => (string) ($row['sync_error'] ?? ''),
            'stock_event_id' => (string) ($row['stock_event_id'] ?? ''),
        ];
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public static function save(int $idProduct, int $idProductAttribute, array $values): void
    {
        $row = array_merge(self::get($idProduct, $idProductAttribute), $values);

        \Db::getInstance()->execute(
            'REPLACE INTO `' . _DB_PREFIX_ . self::TABLE . '` (`id_product`, `id_product_attribute`, `storage_class`, `synced_at`, `sync_hash`, `sync_error`, `stock_event_id`) VALUES ('
            . $idProduct . ', ' . $idProductAttribute . ', \'' . pSQL((string) $row['storage_class']) . '\', ' . (int) $row['synced_at'] . ', \''
            . pSQL((string) $row['sync_hash']) . '\', \'' . pSQL((string) $row['sync_error'], true) . '\', \'' . pSQL((string) $row['stock_event_id']) . '\')'
        );
    }

    public static function storageClass(int $idProduct): string
    {
        return self::get($idProduct)['storage_class'];
    }

    public static function delete(int $idProduct): void
    {
        \Db::getInstance()->delete(self::TABLE, '`id_product` = ' . $idProduct);
    }

    /**
     * Product and combination of a SKU (reference): combinations first.
     *
     * @return array{0: int, 1: int}|null [id_product, id_product_attribute]
     */
    public static function findBySku(string $sku): ?array
    {
        $sku = trim($sku);

        if ($sku === '') {
            return null;
        }

        $db = \Db::getInstance();
        $row = $db->getRow('SELECT `id_product`, `id_product_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `reference` = \'' . pSQL($sku) . '\'', false);

        if (is_array($row) && (int) $row['id_product'] > 0) {
            return [(int) $row['id_product'], (int) $row['id_product_attribute']];
        }

        $idProduct = (int) $db->getValue('SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product` WHERE `reference` = \'' . pSQL($sku) . '\'', false);

        return $idProduct > 0 ? [$idProduct, 0] : null;
    }

    /**
     * Product and combination of an EAN-13 barcode.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function findByEan(string $ean): ?array
    {
        $ean = preg_replace('/\s+/', '', $ean);

        if ($ean === '' || !preg_match('/^\d{8,14}$/', (string) $ean)) {
            return null;
        }

        $db = \Db::getInstance();
        $row = $db->getRow('SELECT `id_product`, `id_product_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `ean13` = \'' . pSQL((string) $ean) . '\'', false);

        if (is_array($row) && (int) $row['id_product'] > 0) {
            return [(int) $row['id_product'], (int) $row['id_product_attribute']];
        }

        $idProduct = (int) $db->getValue('SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product` WHERE `ean13` = \'' . pSQL((string) $ean) . '\'', false);

        return $idProduct > 0 ? [$idProduct, 0] : null;
    }
}
