<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Inbound;

use SkladX\PrestaShop\Sync\ProductRepository;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Finds PrestaShop products for receipt lines (autocomplete, scan, paste).
 * Only products / combinations with a reference (SKU) can be received —
 * SkladX matches by SKU. Products with combinations are listed per combination.
 */
final class ProductLookup
{
    public const LIMIT = 20;

    /**
     * Autocomplete: name, reference or EAN.
     *
     * @return list<array<string, mixed>>
     */
    public static function search(string $term, int $limit = self::LIMIT): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $like = '\'%' . pSQL(addcslashes($term, '%_')) . '%\'';
        $db = \Db::getInstance();
        $units = [];

        $exact = ProductRepository::findBySku($term) ?? ProductRepository::findByEan($term);

        if ($exact !== null) {
            $units[] = $exact;
        }

        $combinations = $db->executeS(
            'SELECT pa.`id_product`, pa.`id_product_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute` pa'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = pa.`id_product` AND pl.`id_lang` = ' . $idLang . ' AND pl.`id_shop` = ' . (int) \Context::getContext()->shop->id
            . ' WHERE pa.`reference` <> \'\' AND (pa.`reference` LIKE ' . $like . ' OR pa.`ean13` LIKE ' . $like . ' OR pl.`name` LIKE ' . $like . ')'
            . ' ORDER BY pl.`name`, pa.`id_product_attribute` LIMIT ' . ($limit * 2),
            true,
            false
        );

        foreach (is_array($combinations) ? $combinations : [] as $row) {
            $units[] = [(int) $row['id_product'], (int) $row['id_product_attribute']];
        }

        $products = $db->executeS(
            'SELECT p.`id_product` FROM `' . _DB_PREFIX_ . 'product` p'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $idLang . ' AND pl.`id_shop` = ' . (int) \Context::getContext()->shop->id
            . ' WHERE p.`reference` <> \'\' AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` x WHERE x.`id_product` = p.`id_product`)'
            . ' AND (p.`reference` LIKE ' . $like . ' OR p.`ean13` LIKE ' . $like . ' OR pl.`name` LIKE ' . $like . ')'
            . ' ORDER BY pl.`name` LIMIT ' . ($limit * 2),
            true,
            false
        );

        foreach (is_array($products) ? $products : [] as $row) {
            $units[] = [(int) $row['id_product'], 0];
        }

        $results = [];
        $seen = [];

        foreach ($units as [$idProduct, $idProductAttribute]) {
            $key = $idProduct . ':' . $idProductAttribute;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $info = self::info($idProduct, $idProductAttribute);

            if ($info !== null) {
                $results[] = $info;
            }

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Exact match of a scanned or pasted code: reference first, then EAN.
     *
     * @return array<string, mixed>|null
     */
    public static function resolve(string $code): ?array
    {
        $found = ProductRepository::findBySku($code) ?? ProductRepository::findByEan($code);

        return $found === null ? null : self::info($found[0], $found[1]);
    }

    /**
     * Products of the given SKUs (for receipts loaded from SkladX).
     *
     * @param list<string> $skus
     *
     * @return array<string, array<string, mixed>> SKU => product info
     */
    public static function bySkus(array $skus): array
    {
        $found = [];

        foreach (array_unique(array_filter(array_map('strval', $skus))) as $sku) {
            $unit = ProductRepository::findBySku($sku);
            $info = $unit === null ? null : self::info($unit[0], $unit[1]);

            if ($info !== null) {
                $found[$sku] = $info;
            }
        }

        return $found;
    }

    /**
     * What the receipt editor needs to know about a product / combination,
     * or null when it cannot be received (no reference, a parent with combinations).
     *
     * @return array<string, mixed>|null
     */
    public static function info(int $idProduct, int $idProductAttribute = 0): ?array
    {
        $db = \Db::getInstance();
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');

        if ($idProductAttribute > 0) {
            $row = $db->getRow('SELECT `reference`, `ean13` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product_attribute` = ' . $idProductAttribute . ' AND `id_product` = ' . $idProduct, false);
        } else {
            $hasCombinations = (bool) $db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product` = ' . $idProduct, false);
            $row = $hasCombinations ? null : $db->getRow('SELECT `reference`, `ean13` FROM `' . _DB_PREFIX_ . 'product` WHERE `id_product` = ' . $idProduct, false);
        }

        if (!is_array($row) || trim((string) $row['reference']) === '') {
            return null;
        }

        $state = ProductRepository::get($idProduct, $idProductAttribute);

        return [
            'id' => $idProduct,
            'id_product_attribute' => $idProductAttribute,
            'sku' => (string) $row['reference'],
            'name' => strip_tags((string) \Product::getProductName($idProduct, $idProductAttribute ?: null, $idLang)),
            'ean' => (string) $row['ean13'],
            'in_skladx' => $state['synced_at'] > 0 && $state['sync_error'] === '',
            'sync_error' => $state['sync_error'],
            'edit_url' => self::editUrl($idProduct),
        ];
    }

    private static function editUrl(int $idProduct): string
    {
        try {
            return \Context::getContext()->link->getAdminLink('AdminProducts', true, ['id_product' => $idProduct, 'productId' => $idProduct], ['id_product' => $idProduct, 'updateproduct' => 1]);
        } catch (\Throwable $exception) {
            return '';
        }
    }
}
