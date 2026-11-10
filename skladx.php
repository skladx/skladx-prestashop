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

require_once __DIR__ . '/src/autoload.php';

use SkladX\PrestaShop\Admin\ConfigPage;
use SkladX\PrestaShop\Admin\OrderBox;
use SkladX\PrestaShop\Admin\ProductTab;
use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Install\Installer;
use SkladX\PrestaShop\Pickup\CartPickup;
use SkladX\PrestaShop\Pickup\Checkout;
use SkladX\PrestaShop\Pickup\PickupPoint;
use SkladX\PrestaShop\Queue\Runner;
use SkladX\PrestaShop\Support\Notices;
use SkladX\PrestaShop\Sync\OrderExporter;
use SkladX\PrestaShop\Sync\OrderRepository;
use SkladX\PrestaShop\Sync\OrderUpdater;
use SkladX\PrestaShop\Sync\ProductSync;
use SkladX\PrestaShop\Sync\Tracking;

/**
 * SkladX fulfilment integration: order export, product and stock sync,
 * signed webhooks with polling fallback, tracking, pickup point picker and
 * inbound receipts.
 */
class Skladx extends Module
{
    public const VERSION = Settings::MODULE_VERSION;

    public const HOOKS = [
        'actionValidateOrder',
        'actionOrderStatusPostUpdate',
        'actionObjectProductAddAfter',
        'actionObjectProductUpdateAfter',
        'actionObjectProductDeleteBefore',
        'actionObjectCombinationAddAfter',
        'actionObjectCombinationUpdateAfter',
        // 0.2.0: product page, CQRS commands, webservice and CSV import fire different ones; ids are de-duplicated per request.
        'actionProductAdd',
        'actionProductUpdate',
        'actionProductSave',
        'actionProductAttributeUpdate',
        'actionObjectImageAddAfter',
        'actionObjectImageUpdateAfter',
        'actionObjectImageDeleteAfter',
        'displayAdminOrderSide',
        'displayAdminProductsExtra',
        'displayCarrierExtraContent',
        'actionValidateStepComplete',
        'actionCarrierProcess',
        'displayPaymentTop',
        'actionFrontControllerSetMedia',
        'actionAdminControllerSetMedia',
        'displayDashboardTop',
        'displayOrderDetail',
        'displayOrderConfirmation',
        'sendMailAlterTemplateVars',
    ];

    public function __construct()
    {
        $this->name = 'skladx';
        $this->tab = 'shipping_logistics';
        $this->version = self::VERSION;
        $this->author = 'SkladX';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->l('SkladX');
        $this->description = $this->l('Fulfilment by the SkladX warehouse: orders, products, stock, tracking, pickup points and inbound receipts.');
        $this->confirmUninstall = $this->l('Uninstalling removes the SkladX settings, the queue and the link between orders and SkladX. Continue?');

        $this->tabs = [
            [
                'name' => ['en' => 'SkladX receipts', 'sk' => 'Príjemky SkladX', 'cs' => 'Příjemky SkladX'],
                'class_name' => 'AdminSkladxReceipts',
                'parent_class_name' => 'AdminCatalog',
                'visible' => true,
            ],
            [
                'name' => ['en' => 'SkladX actions', 'sk' => 'SkladX akcie'],
                'class_name' => 'AdminSkladxActions',
                'parent_class_name' => 'AdminCatalog',
                'visible' => false,
            ],
        ];
    }

    public function install(): bool
    {
        if (!function_exists('curl_init')) {
            $this->_errors[] = $this->l('The PHP cURL extension is required.');

            return false;
        }

        return parent::install()
            && Installer::install()
            && Settings::install()
            && $this->registerHook(self::HOOKS);
    }

    public function uninstall(): bool
    {
        ConfigPage::detachCarriers([]);

        return Settings::uninstall()
            && Installer::uninstall()
            && parent::uninstall();
    }

    /**
     * Module configuration page (Modules → SkladX → Configure).
     */
    public function getContent(): string
    {
        return (new ConfigPage($this))->render();
    }

    /* ------------------------------------------------------------------
     * Orders
     * ---------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $params order, orderStatus, cart, customer
     */
    public function hookActionValidateOrder(array $params): void
    {
        $order = $params['order'] ?? null;

        if (!$order instanceof Order) {
            return;
        }

        Checkout::copyToOrder($order);
        $state = $params['orderStatus'] ?? null;

        if ($state instanceof OrderState && Settings::isConfigured() && in_array((int) $state->id, Settings::exportStates(), true)) {
            Runner::runAfterResponse(OrderExporter::queue((int) $order->id));
        }
    }

    /**
     * @param array<string, mixed> $params newOrderStatus, id_order
     */
    public function hookActionOrderStatusPostUpdate(array $params): void
    {
        $state = $params['newOrderStatus'] ?? null;
        $idOrder = (int) ($params['id_order'] ?? 0);

        if (OrderUpdater::$applying || !$state instanceof OrderState || $idOrder <= 0 || !Settings::isConfigured()) {
            return;
        }

        if (in_array((int) $state->id, Settings::exportStates(), true)) {
            Runner::runAfterResponse(OrderExporter::queue($idOrder));
        }
    }

    /**
     * SkladX box on the order page.
     *
     * @param array<string, mixed> $params id_order
     */
    public function hookDisplayAdminOrderSide(array $params): string
    {
        return (new OrderBox($this))->render((int) ($params['id_order'] ?? 0));
    }

    /* ------------------------------------------------------------------
     * Products
     * ---------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $params object
     */
    public function hookActionObjectProductAddAfter(array $params): void
    {
        $this->scheduleProduct($params['object'] ?? null);
    }

    /**
     * @param array<string, mixed> $params object
     */
    public function hookActionObjectProductUpdateAfter(array $params): void
    {
        $this->scheduleProduct($params['object'] ?? null);
    }

    /**
     * @param array<string, mixed> $params id_product, product
     */
    public function hookActionProductAdd(array $params): void
    {
        ProductSync::schedule((int) ($params['id_product'] ?? 0));
    }

    /**
     * @param array<string, mixed> $params id_product, product
     */
    public function hookActionProductUpdate(array $params): void
    {
        ProductSync::schedule((int) ($params['id_product'] ?? 0));
    }

    /**
     * @param array<string, mixed> $params id_product, product
     */
    public function hookActionProductSave(array $params): void
    {
        ProductSync::schedule((int) ($params['id_product'] ?? 0));
    }

    /**
     * @param array<string, mixed> $params object
     */
    public function hookActionObjectProductDeleteBefore(array $params): void
    {
        $product = $params['object'] ?? null;

        if ($product instanceof Product && Validate::isLoadedObject($product)) {
            ProductSync::archive($product);
        }
    }

    /**
     * @param array<string, mixed> $params object
     */
    public function hookActionObjectCombinationAddAfter(array $params): void
    {
        $combination = $params['object'] ?? null;

        if ($combination instanceof Combination) {
            ProductSync::schedule((int) $combination->id_product);
        }
    }

    /**
     * @param array<string, mixed> $params object
     */
    public function hookActionObjectCombinationUpdateAfter(array $params): void
    {
        $this->hookActionObjectCombinationAddAfter($params);
    }

    /**
     * Legacy combination save (Product::updateAttribute and friends).
     *
     * @param array<string, mixed> $params id_product_attribute
     */
    public function hookActionProductAttributeUpdate(array $params): void
    {
        $idProductAttribute = (int) ($params['id_product_attribute'] ?? 0);

        if ($idProductAttribute > 0) {
            ProductSync::schedule((int) Db::getInstance()->getValue('SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product_attribute` = ' . $idProductAttribute, false));
        }
    }

    /**
     * Images are part of the payload (image_url, images).
     *
     * @param array<string, mixed> $params object
     */
    public function hookActionObjectImageAddAfter(array $params): void
    {
        $image = $params['object'] ?? null;

        if ($image instanceof Image) {
            ProductSync::schedule((int) $image->id_product);
        }
    }

    /**
     * @param array<string, mixed> $params object
     */
    public function hookActionObjectImageUpdateAfter(array $params): void
    {
        $this->hookActionObjectImageAddAfter($params);
    }

    /**
     * @param array<string, mixed> $params object
     */
    public function hookActionObjectImageDeleteAfter(array $params): void
    {
        $this->hookActionObjectImageAddAfter($params);
    }

    /**
     * SkladX tab on the product page (storage class, sync state).
     *
     * @param array<string, mixed> $params id_product
     */
    public function hookDisplayAdminProductsExtra(array $params): string
    {
        return (new ProductTab($this))->render((int) ($params['id_product'] ?? Tools::getValue('id_product')));
    }

    /**
     * @param mixed $product
     */
    private function scheduleProduct($product): void
    {
        if ($product instanceof Product && Validate::isLoadedObject($product)) {
            ProductSync::schedule((int) $product->id);
        }
    }

    /* ------------------------------------------------------------------
     * Checkout: pickup point picker
     * ---------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $params carrier (array)
     */
    public function hookDisplayCarrierExtraContent(array $params): string
    {
        $carrier = (array) ($params['carrier'] ?? []);
        $idCarrier = (int) ($carrier['id'] ?? 0);
        $code = Checkout::carrierCode($idCarrier);
        $cart = $this->context->cart;

        if ($code === '' || !Validate::isLoadedObject($cart)) {
            return '';
        }

        $point = CartPickup::get((int) $cart->id);
        $point = $point !== null && (string) $point['carrier'] === $code ? $point : null;
        $address = new Address((int) $cart->id_address_delivery);

        $this->context->smarty->assign([
            'skladx_carrier' => $code,
            'skladx_id_carrier' => $idCarrier,
            'skladx_point' => $point,
            'skladx_point_address' => $point !== null ? PickupPoint::addressLine($point) : '',
            'skladx_city' => Validate::isLoadedObject($address) ? (string) $address->city : '',
            'skladx_postcode' => Validate::isLoadedObject($address) ? (string) $address->postcode : '',
            'skladx_country' => Validate::isLoadedObject($address) ? (string) Country::getIsoById((int) $address->id_country) : Settings::shopCountryIso(),
        ]);

        return $this->fetch('module:skladx/views/templates/hook/carrier_extra.tpl');
    }

    /**
     * Blocks the delivery step until a pickup point is chosen.
     *
     * @param array<string, mixed> $params step_name, request_params, completed (by reference)
     */
    public function hookActionValidateStepComplete(array $params): void
    {
        if (($params['step_name'] ?? '') !== 'delivery' || !Validate::isLoadedObject($this->context->cart)) {
            return;
        }

        $problem = Checkout::problemForCart($this->context->cart);

        if ($problem !== '') {
            $params['completed'] = false;
            $this->context->controller->errors[] = Checkout::problemMessage($problem);
        }
    }

    /**
     * Forgets a pickup point of another carrier when the customer switches carriers.
     *
     * @param array<string, mixed> $params cart
     */
    public function hookActionCarrierProcess(array $params): void
    {
        $cart = $params['cart'] ?? null;

        if (!$cart instanceof Cart || !Validate::isLoadedObject($cart)) {
            return;
        }

        $point = CartPickup::get((int) $cart->id);

        if ($point !== null && Checkout::carrierCode(Checkout::selectedCarrierId($cart)) !== (string) $point['carrier']) {
            CartPickup::clear((int) $cart->id);
        }
    }

    /**
     * Warns when the chosen pickup point does not accept cash on delivery.
     */
    public function hookDisplayPaymentTop(): string
    {
        $cart = $this->context->cart;

        if (!Validate::isLoadedObject($cart) || Settings::codModules() === []) {
            return '';
        }

        $code = Checkout::carrierCode(Checkout::selectedCarrierId($cart));
        $point = $code !== '' ? CartPickup::get((int) $cart->id) : null;

        if ($point === null || ($point['cod'] ?? null) !== false) {
            return '';
        }

        $this->context->smarty->assign(['skladx_point_name' => (string) $point['name']]);

        return $this->fetch('module:skladx/views/templates/hook/payment_top.tpl');
    }

    public function hookActionFrontControllerSetMedia(): void
    {
        $controller = $this->context->controller;

        if (!$controller instanceof OrderController || !Settings::pickupPickerEnabled() || Settings::pickupCarrierCodes() === []) {
            return;
        }

        $controller->registerStylesheet('skladx-pickup', 'modules/' . $this->name . '/views/css/pickup.css', ['media' => 'all', 'priority' => 150, 'version' => $this->assetVersion('views/css/pickup.css')]);
        $controller->registerJavascript('skladx-pickup', 'modules/' . $this->name . '/views/js/pickup.js', ['position' => 'bottom', 'priority' => 150, 'version' => $this->assetVersion('views/js/pickup.js')]);

        Media::addJsDef(['skladxPickupConfig' => [
            'endpoint' => $this->context->link->getModuleLink($this->name, 'pickup', [], true),
            'token' => Tools::getToken(false),
            'codModules' => Settings::codModules(),
            'packetaKey' => Settings::packetaWidgetKey(),
            'defaultCountry' => Settings::shopCountryIso(),
            'language' => (string) $this->context->language->iso_code,
            'i18n' => Checkout::i18n(),
        ]]);
    }

    /* ------------------------------------------------------------------
     * Customer: tracking and pickup point
     * ---------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $params order
     */
    public function hookDisplayOrderDetail(array $params): string
    {
        return $this->renderCustomerInfo($params['order'] ?? null, true);
    }

    /**
     * @param array<string, mixed> $params order
     */
    public function hookDisplayOrderConfirmation(array $params): string
    {
        return $this->renderCustomerInfo($params['order'] ?? null, false);
    }

    /**
     * Adds the pickup point to the carrier line of the order confirmation e-mail.
     *
     * @param array<string, mixed> $params template, template_vars (by reference)
     */
    public function hookSendMailAlterTemplateVars(array $params): void
    {
        if (($params['template'] ?? '') !== 'order_conf' || !isset($params['template_vars']['{id_order}'])) {
            return;
        }

        $row = OrderRepository::get((int) $params['template_vars']['{id_order}']);
        $point = is_array($row['pickup_point'] ?? null) ? $row['pickup_point'] : null;

        if ($point !== null && isset($params['template_vars']['{carrier}'])) {
            $params['template_vars']['{carrier}'] .= ' – ' . $point['name'] . ', ' . PickupPoint::addressLine($point);
        }
    }

    /**
     * @param mixed $order
     */
    private function renderCustomerInfo($order, bool $withTracking): string
    {
        if (!$order instanceof Order || !Validate::isLoadedObject($order)) {
            return '';
        }

        $row = OrderRepository::get((int) $order->id);
        $point = is_array($row['pickup_point'] ?? null) ? $row['pickup_point'] : null;
        $tracking = $withTracking ? Tracking::forRow($row) : null;

        if ($point === null && $tracking === null) {
            return '';
        }

        $this->context->smarty->assign([
            'skladx_point' => $point,
            'skladx_point_address' => $point !== null ? PickupPoint::addressLine($point) : '',
            'skladx_tracking' => $tracking,
        ]);

        return $this->fetch('module:skladx/views/templates/hook/order_detail.tpl');
    }

    /* ------------------------------------------------------------------
     * Back office
     * ---------------------------------------------------------------- */

    public function hookActionAdminControllerSetMedia(): void
    {
        $controller = $this->context->controller;

        if ($controller !== null) {
            $controller->addCSS($this->_path . 'views/css/admin.css?v=' . $this->assetVersion('views/css/admin.css'));
            $controller->addJS($this->_path . 'views/js/admin.js?v=' . $this->assetVersion('views/js/admin.js'));
        }

        if (Settings::isConfigured()) {
            Runner::runLazily();
        }
    }

    /**
     * Cache-busting version of a module asset.
     */
    private function assetVersion(string $file): string
    {
        return self::VERSION . '-' . (int) @filemtime($this->getLocalPath() . $file);
    }

    public function hookDisplayDashboardTop(): string
    {
        $notices = Notices::all();

        if ($notices === []) {
            return '';
        }

        $this->context->smarty->assign([
            'skladx_notices' => array_reverse($notices),
            'skladx_dismiss_url' => $this->context->link->getAdminLink('AdminSkladxActions', true, [], ['ajax' => 1, 'action' => 'dismissNotice']),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/notices.tpl');
    }
}
