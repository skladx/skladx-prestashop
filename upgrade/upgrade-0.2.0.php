<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__DIR__) . '/src/autoload.php';

use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Sync\ProductRepository;
use SkladX\PrestaShop\Sync\ProductSync;

/**
 * 0.2.0: stable external_id, immediate product sync, SKU conflicts, images.
 *
 * - adds `skladx_product.sync_error_code` (409 sku_conflict is not retried),
 * - registers the new product hooks (actionProductAdd/Update/Save,
 *   actionProductAttributeUpdate, actionObjectImage*),
 * - queues a full product sync so SkladX learns the external_id (and the
 *   images) of every product already synced by SKU.
 *
 * @param Skladx $module
 */
function upgrade_module_0_2_0($module): bool
{
    if (!ProductRepository::addErrorCodeColumn()) {
        return false;
    }

    if (!$module->registerHook(Skladx::HOOKS)) {
        return false;
    }

    if (Settings::isConfigured() && Settings::productSyncEnabled()) {
        ProductSync::queueAll();
    }

    return true;
}
