<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Admin;

use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Sync\ProductPayloadBuilder;
use SkladX\PrestaShop\Sync\ProductRepository;
use SkladX\PrestaShop\Sync\ProductSync;
use SkladX\PrestaShop\Sync\SkuConflict;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * SkladX section of the product page (hook displayAdminProductsExtra):
 * storage class, SKU/EAN check, external_id and sync state / last error per stock unit, "Save" and
 * "Sync to SkladX now" (AJAX to AdminSkladxActions, independent of the
 * product form).
 */
final class ProductTab
{
    public function __construct(private readonly \Module $module)
    {
    }

    public function render(int $idProduct): string
    {
        $product = new \Product($idProduct, false, (int) \Configuration::get('PS_LANG_DEFAULT'));

        if (!\Validate::isLoadedObject($product)) {
            return '';
        }

        $context = \Context::getContext();
        $storageClass = ProductRepository::storageClass($idProduct);
        $units = self::units($product);

        $context->smarty->assign([
            'skladx_id_product' => $idProduct,
            'skladx_configured' => Settings::isConfigured(),
            'skladx_sync_enabled' => Settings::productSyncEnabled(),
            'skladx_storage_class' => $storageClass,
            'skladx_storage_classes' => ProductPayloadBuilder::STORAGE_CLASSES,
            'skladx_custom_class' => $storageClass !== '' && !in_array($storageClass, ProductPayloadBuilder::STORAGE_CLASSES, true),
            'skladx_units' => $units,
            'skladx_errors' => array_values(array_filter($units, static fn (array $unit): bool => $unit['error'] !== '')),
            'skladx_ajax_url' => $context->link->getAdminLink('AdminSkladxActions', true, [], ['ajax' => 1]),
        ]);

        return $this->module->display(_PS_MODULE_DIR_ . 'skladx/skladx.php', 'views/templates/hook/product_tab.tpl');
    }

    /**
     * Sync state per stock unit.
     *
     * @return list<array<string, mixed>>
     */
    public static function units(\Product $product): array
    {
        $units = [];
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');

        foreach (ProductSync::units($product) as $idProductAttribute) {
            $snapshot = ProductSync::snapshot($product, $idProductAttribute);
            $state = ProductRepository::get((int) $product->id, $idProductAttribute);
            $units[] = [
                'id_product_attribute' => $idProductAttribute,
                'name' => $idProductAttribute > 0 ? (string) \Product::getProductName((int) $product->id, $idProductAttribute, $idLang) : (string) $snapshot['name'],
                'sku' => (string) $snapshot['sku'],
                'external_id' => (string) $snapshot['external_id'],
                'ean' => (string) $snapshot['ean'],
                'synced_at' => $state['synced_at'] > 0 ? date('Y-m-d H:i', $state['synced_at']) : '',
                'error' => $state['sync_error'],
                'conflict' => $state['sync_error_code'] === SkuConflict::ERROR_CODE,
            ];
        }

        return $units;
    }
}
