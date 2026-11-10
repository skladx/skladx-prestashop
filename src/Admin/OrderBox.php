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
use SkladX\PrestaShop\Pickup\PickupPoint;
use SkladX\PrestaShop\Queue\Queue;
use SkladX\PrestaShop\Sync\MappingException;
use SkladX\PrestaShop\Sync\OrderExporter;
use SkladX\PrestaShop\Sync\OrderPayloadBuilder;
use SkladX\PrestaShop\Sync\OrderRepository;
use SkladX\PrestaShop\Sync\StatusMapper;
use SkladX\PrestaShop\Sync\Tracking;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * SkladX box on the order page (hook displayAdminOrderSide): status, number,
 * tracking with a "Track shipment" button, pickup point, last sync and the
 * Send/Resend, Refresh and Cancel actions.
 */
final class OrderBox
{
    public function __construct(private readonly \Module $module)
    {
    }

    public function render(int $idOrder): string
    {
        $order = new \Order($idOrder);

        if (!\Validate::isLoadedObject($order)) {
            return '';
        }

        $context = \Context::getContext();
        $row = OrderRepository::get($idOrder) ?? [];
        $exported = (string) ($row['skladx_id'] ?? '') !== '';
        $point = is_array($row['pickup_point'] ?? null) ? $row['pickup_point'] : null;
        $payload = null;
        $mappingError = '';

        if (!$exported) {
            try {
                $payload = OrderExporter::payload($order);
            } catch (MappingException $exception) {
                $mappingError = $exception->localizedMessage();
            }
        }

        $addressChanged = false;

        if ($exported && (string) ($row['address_hash'] ?? '') !== '') {
            try {
                $current = OrderPayloadBuilder::addressFingerprint((array) (OrderExporter::payload($order)['shipping_address'] ?? []));
                $addressChanged = $current !== (string) $row['address_hash'];
            } catch (MappingException $exception) {
                $addressChanged = false;
            }
        }

        $context->smarty->assign([
            'skladx_configured' => Settings::isConfigured(),
            'skladx_settings_url' => $context->link->getAdminLink('AdminModules', true, [], ['configure' => 'skladx']),
            'skladx_action_url' => $context->link->getAdminLink('AdminSkladxActions'),
            'skladx_id_order' => $idOrder,
            'skladx_row' => $row,
            'skladx_exported' => $exported,
            'skladx_queued' => Queue::isQueued(Queue::ORDER_EXPORT, (string) $idOrder),
            'skladx_tracking' => Tracking::forRow($row),
            'skladx_point' => $point,
            'skladx_point_address' => $point !== null ? PickupPoint::addressLine($point) : '',
            'skladx_cancellable' => $exported && StatusMapper::isCancellable((string) ($row['status'] ?? '')),
            'skladx_address_changed' => $addressChanged,
            'skladx_mapping_error' => $mappingError,
            'skladx_payload' => $payload !== null ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            'skladx_last_sync' => (int) ($row['last_sync'] ?? 0) > 0 ? date('Y-m-d H:i', (int) $row['last_sync']) : '',
            'skladx_logo' => $this->module->getPathUri() . 'views/img/sx-mark.svg',
        ]);

        return $this->module->display(_PS_MODULE_DIR_ . 'skladx/skladx.php', 'views/templates/hook/order_box.tpl');
    }
}
