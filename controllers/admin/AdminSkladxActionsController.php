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

require_once _PS_MODULE_DIR_ . 'skladx/src/autoload.php';

use SkladX\PrestaShop\Api\ApiException;
use SkladX\PrestaShop\Api\Client;
use SkladX\PrestaShop\Queue\RetryLater;
use SkladX\PrestaShop\Queue\Runner;
use SkladX\PrestaShop\Support\Notices;
use SkladX\PrestaShop\Support\T;
use SkladX\PrestaShop\Sync\OrderExporter;
use SkladX\PrestaShop\Sync\OrderNotes;
use SkladX\PrestaShop\Sync\OrderRepository;
use SkladX\PrestaShop\Sync\OrderUpdater;
use SkladX\PrestaShop\Sync\ProductPayloadBuilder;
use SkladX\PrestaShop\Sync\ProductRepository;
use SkladX\PrestaShop\Sync\ProductSync;

/**
 * Hidden back office controller for the actions of the order box (POST,
 * redirect back to the order) and the AJAX calls of the product section and
 * the notices. The admin token in the URL protects every call (CSRF).
 */
class AdminSkladxActionsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;

        parent::__construct();
    }

    /**
     * @return mixed
     */
    public function postProcess()
    {
        if ($this->ajax) {
            return parent::postProcess();
        }

        $action = (string) Tools::getValue('skladx_action');
        $idOrder = (int) Tools::getValue('id_order');

        if ($action === '' || $idOrder <= 0) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminOrders'));
        }

        if (!$this->access('edit')) {
            Notices::add(T::__('You are not allowed to do this.'), 'danger');
            Tools::redirectAdmin(OrderExporter::adminOrderUrl($idOrder));
        }

        $order = new Order($idOrder);

        if (Validate::isLoadedObject($order)) {
            switch ($action) {
                case 'send':
                    $this->send($order);
                    break;
                case 'refresh':
                    $this->refresh($order);
                    break;
                case 'cancel':
                    $this->cancel($order);
                    break;
            }
        }

        Tools::redirectAdmin(OrderExporter::adminOrderUrl($idOrder));
    }

    private function send(Order $order): void
    {
        $job = OrderExporter::resend((int) $order->id);

        if ($job <= 0) {
            Notices::add(T::__('The order is already in SkladX.'), 'info');

            return;
        }

        Runner::runJob($job);
        $row = OrderRepository::get((int) $order->id) ?? [];

        if ((string) ($row['skladx_id'] ?? '') !== '') {
            Notices::add(T::f('Order %1$s sent to SkladX as %2$s.', (string) $order->reference, (string) $row['skladx_number']), 'success');
        } elseif ((string) ($row['export_state'] ?? '') === 'retrying') {
            Notices::add(T::f('SkladX is not reachable right now, the order will be sent again automatically: %s', (string) $row['export_error']), 'warning');
        }
    }

    private function refresh(Order $order): void
    {
        $row = OrderRepository::get((int) $order->id) ?? [];

        if ((string) ($row['skladx_id'] ?? '') === '') {
            return;
        }

        try {
            OrderUpdater::apply($order, Client::fromSettings()->getOrder((string) $row['skladx_id']));
            Notices::add(T::__('SkladX data of the order refreshed.'), 'success');
        } catch (ApiException $exception) {
            Notices::add(T::f('Refreshing from SkladX failed: %s', $exception->summary()), 'danger');
        }
    }

    private function cancel(Order $order): void
    {
        $row = OrderRepository::get((int) $order->id) ?? [];

        if ((string) ($row['skladx_id'] ?? '') === '') {
            return;
        }

        try {
            $employee = $this->context->employee;
            $remote = Client::fromSettings()->cancelOrder((string) $row['skladx_id'], T::f('Cancelled in PrestaShop by %s', trim($employee->firstname . ' ' . $employee->lastname)));
            OrderUpdater::apply($order, $remote);
            OrderNotes::add($order, T::__('order cancelled in SkladX.'));
            Notices::add(T::__('The order was cancelled in SkladX.'), 'success');
        } catch (ApiException $exception) {
            Notices::add(T::f('Cancelling in SkladX failed: %s', $exception->summary()), 'danger');
        }
    }

    /**
     * Product section: save the storage class.
     */
    public function ajaxProcessSaveProduct(): void
    {
        $this->guardAjax();
        $idProduct = (int) Tools::getValue('id_product');
        $class = (string) Tools::getValue('storage_class');
        $class = $class === 'custom' ? (string) Tools::getValue('storage_class_custom') : $class;
        $class = ProductPayloadBuilder::storageClass($class);

        if ($idProduct <= 0) {
            $this->json(['success' => false, 'message' => T::__('Product not found.')], 404);
        }

        ProductRepository::save($idProduct, 0, ['storage_class' => $class]);
        $this->syncAndRespond($idProduct, T::__('SkladX settings of the product saved.'));
    }

    /**
     * Product section: send the product to SkladX now.
     */
    public function ajaxProcessSyncProduct(): void
    {
        $this->guardAjax();
        $this->syncAndRespond((int) Tools::getValue('id_product'), T::__('Product sent to SkladX.'));
    }

    /**
     * Dismisses an admin notice ("all" for every notice).
     */
    public function ajaxProcessDismissNotice(): void
    {
        Notices::dismiss((string) Tools::getValue('id'));
        $this->json(['success' => true]);
    }

    private function syncAndRespond(int $idProduct, string $message): void
    {
        $product = new Product($idProduct, false, (int) Configuration::get('PS_LANG_DEFAULT'));

        if (!Validate::isLoadedObject($product)) {
            $this->json(['success' => false, 'message' => T::__('Product not found.')], 404);
        }

        try {
            $result = ProductSync::sync($idProduct, false, true);
        } catch (RetryLater $exception) {
            $this->json(['success' => false, 'message' => T::f('SkladX is not reachable right now: %s', $exception->getMessage())], 502);
        }

        $units = SkladX\PrestaShop\Admin\ProductTab::units($product);
        $errors = array_values(array_filter(array_column($units, 'error')));

        if ($result['synced'] === 0 && $errors === [] && $result['skipped'] > 0) {
            $message = T::__('Nothing was sent: add a reference (SKU) to the product or its combinations first.');
        }

        $this->json(['success' => $errors === [], 'message' => $errors === [] ? $message : implode(' ', $errors), 'units' => $units]);
    }

    private function guardAjax(): void
    {
        if (!$this->access('edit')) {
            $this->json(['success' => false, 'message' => T::__('You are not allowed to do this.')], 403);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
