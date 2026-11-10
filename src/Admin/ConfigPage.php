<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Admin;

use SkladX\PrestaShop\Api\ApiException;
use SkladX\PrestaShop\Api\Client;
use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Queue\Queue;
use SkladX\PrestaShop\Queue\Runner;
use SkladX\PrestaShop\Support\Logger;
use SkladX\PrestaShop\Support\Redactor;
use SkladX\PrestaShop\Support\T;
use SkladX\PrestaShop\Support\Url;
use SkladX\PrestaShop\Sync\ProductPayloadBuilder;
use SkladX\PrestaShop\Sync\ProductSync;
use SkladX\PrestaShop\Sync\StatusMapper;
use SkladX\PrestaShop\Sync\StockSync;
use SkladX\PrestaShop\Sync\Tracking;
use SkladX\PrestaShop\Webhooks\EventPoller;
use SkladX\PrestaShop\Webhooks\EventStore;
use SkladX\PrestaShop\Webhooks\WebhookRegistrar;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Module configuration page: connection, orders, carriers, products and
 * stock, queue and events. Rendered from views/templates/admin/configure.tpl.
 */
final class ConfigPage
{
    /** @var list<array{type: string, text: string}> */
    private array $messages = [];

    public function __construct(private readonly \Module $module)
    {
    }

    public function render(): string
    {
        $this->process();

        $context = \Context::getContext();
        $idLang = (int) $context->language->id;
        $info = Settings::getArray(Settings::CONNECTION_INFO);
        $missing = $info !== [] ? array_values(array_diff(Settings::REQUIRED_SCOPES, (array) ($info['scopes'] ?? []))) : [];
        $webhookUrl = Settings::webhookUrl();
        $problem = Url::webhookProblem($webhookUrl, _PS_ROOT_DIR_);

        $context->smarty->assign([
            'skladx_messages' => $this->messages,
            'skladx_action' => $context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->module->name]),
            'skladx_version' => Settings::MODULE_VERSION,
            'skladx_logo' => $this->module->getPathUri() . 'logo.png',
            'skladx_api_url' => (string) Settings::get(Settings::API_URL),
            'skladx_api_key_masked' => Redactor::mask(Settings::apiKey()),
            'skladx_webhook_secret_masked' => Redactor::mask(Settings::webhookSecret()),
            'skladx_webhook_id' => (string) Settings::get(Settings::WEBHOOK_ID),
            'skladx_webhook_url' => $webhookUrl,
            'skladx_webhook_problem' => $this->webhookProblemText($problem),
            'skladx_cron_url' => Settings::cronUrl(),
            'skladx_configured' => Settings::isConfigured(),
            'skladx_info' => $info,
            'skladx_info_client' => $info !== [] ? self::infoName($info['client'] ?? null) : '',
            'skladx_info_environment' => $info !== [] ? self::infoName($info['environment'] ?? null) : '',
            'skladx_info_sandbox' => !empty($info['environment']['sandbox']),
            'skladx_missing_scopes' => $missing,
            'skladx_order_states' => \OrderState::getOrderStates($idLang),
            'skladx_export_states' => Settings::exportStates(),
            'skladx_payment_modules' => $this->paymentModules(),
            'skladx_cod_modules' => Settings::codModules(),
            'skladx_statuses' => StatusMapper::STATUSES,
            'skladx_status_map' => Settings::statusMap(),
            'skladx_tracking_email' => Settings::trackingEmailEnabled(),
            'skladx_carriers' => $this->carriers($idLang),
            'skladx_carrier_codes' => Settings::CARRIERS,
            'skladx_carrier_names' => array_combine(Settings::CARRIERS, array_map([\SkladX\PrestaShop\Inbound\Receipts::class, 'carrierName'], Settings::CARRIERS)),
            'skladx_pickup_picker' => Settings::pickupPickerEnabled(),
            'skladx_packeta_key' => Settings::packetaWidgetKey(),
            'skladx_product_sync' => Settings::productSyncEnabled(),
            'skladx_stock_sync' => Settings::stockSyncEnabled(),
            'skladx_debug' => Settings::debugEnabled(),
            'skladx_log_file' => Logger::file(),
            'skladx_queue_counts' => Queue::counts(),
            'skladx_queue_failures' => Queue::recentFailures(),
            'skladx_event_counts' => EventStore::counts(),
            'skladx_events' => EventStore::recent(),
            'skladx_last_cron' => (int) Settings::get(Settings::LAST_CRON),
            'skladx_last_run' => (int) Settings::get(Settings::LAST_RUN),
            'skladx_storage_classes' => ProductPayloadBuilder::STORAGE_CLASSES,
            'skladx_receipts_url' => \SkladX\PrestaShop\Inbound\Receipts::url(),
        ]);

        return $this->module->display(_PS_MODULE_DIR_ . $this->module->name . '/' . $this->module->name . '.php', 'views/templates/admin/configure.tpl');
    }

    private function process(): void
    {
        if (\Tools::isSubmit('submitSkladxSettings')) {
            $this->save();
        } elseif (\Tools::isSubmit('skladxTestConnection')) {
            $this->testConnection();
        } elseif (\Tools::isSubmit('skladxRegisterWebhook')) {
            $this->registerWebhook();
        } elseif (\Tools::isSubmit('skladxSyncProducts')) {
            $this->requireConfigured() && $this->queued(ProductSync::queueAll(...), T::__('Full product sync queued. Products are sent in batches of 25 by the queue.'));
        } elseif (\Tools::isSubmit('skladxPullStock')) {
            if (!Settings::stockSyncEnabled()) {
                $this->messages[] = ['type' => 'warning', 'text' => T::__('Turn on stock sync first.')];
            } elseif ($this->requireConfigured()) {
                $this->queued(StockSync::queuePull(...), T::__('Taking over stock from SkladX has been queued.'));
            }
        } elseif (\Tools::isSubmit('skladxPollEvents')) {
            $this->pollEvents();
        } elseif (\Tools::isSubmit('skladxRunQueue')) {
            $result = Runner::run(50, 25.0);
            $this->messages[] = $result['skipped']
                ? ['type' => 'warning', 'text' => T::__('The queue is being processed by another request right now.')]
                : ['type' => 'success', 'text' => T::f('Queue processed: %1$d jobs, %2$d failed or postponed.', $result['jobs'], $result['failed'])];
        } elseif (\Tools::isSubmit('skladxRetryFailed')) {
            $this->messages[] = ['type' => 'success', 'text' => T::f('%d failed jobs queued again.', Queue::retryFailed())];
        }
    }

    private function save(): void
    {
        $oldUrl = Settings::apiUrl();
        $oldKey = Settings::apiKey();
        $wasStockSync = Settings::stockSyncEnabled();

        $url = Url::apiBase((string) \Tools::getValue('SKLADX_API_URL'));

        if ($url === '') {
            $this->messages[] = ['type' => 'danger', 'text' => T::__('The API base URL must start with https:// (e.g. https://skladx.sk/api/v1).')];
        } else {
            Settings::set(Settings::API_URL, $url);
        }

        $key = trim((string) \Tools::getValue('SKLADX_API_KEY'));

        if ($key !== '') {
            if (!preg_match('/^skx_(live|test)_[A-Za-z0-9]+$/', $key)) {
                $this->messages[] = ['type' => 'warning', 'text' => T::__('The API key does not look like a SkladX key (skx_live_… or skx_test_…). It was saved anyway.')];
            }

            Settings::set(Settings::API_KEY, $key);
        }

        $secret = trim((string) \Tools::getValue('SKLADX_WEBHOOK_SECRET'));

        if ($secret !== '') {
            Settings::set(Settings::WEBHOOK_SECRET, $secret);
        }

        if ($oldUrl !== Settings::apiUrl() || $oldKey !== Settings::apiKey()) {
            Settings::set(Settings::EVENTS_CURSOR, '');
            Settings::set(Settings::CONNECTION_INFO, []);
        }

        $validStates = array_map(static fn (array $state): int => (int) $state['id_order_state'], \OrderState::getOrderStates((int) \Context::getContext()->language->id));
        $states = array_values(array_intersect(array_map('intval', (array) \Tools::getValue('SKLADX_EXPORT_STATES', [])), $validStates));
        Settings::set(Settings::EXPORT_STATES, $states);

        $paymentNames = array_column($this->paymentModules(), 'name');
        Settings::set(Settings::COD_MODULES, array_values(array_intersect(array_map('strval', (array) \Tools::getValue('SKLADX_COD_MODULES', [])), $paymentNames)));
        Settings::set(Settings::STATUS_MAP, StatusMapper::sanitizeMap((array) \Tools::getValue('SKLADX_STATUS_MAP', []), $validStates));
        Settings::set(Settings::TRACKING_EMAIL, (bool) \Tools::getValue('SKLADX_TRACKING_EMAIL'));

        $carrierMap = [];
        $pickup = [];
        $references = array_map(static fn (array $carrier): int => (int) $carrier['id_reference'], $this->carriers((int) \Context::getContext()->language->id));

        foreach ((array) \Tools::getValue('SKLADX_CARRIER_MAP', []) as $reference => $code) {
            if (in_array((int) $reference, $references, true) && in_array((string) $code, Settings::CARRIERS, true)) {
                $carrierMap[(string) (int) $reference] = (string) $code;
            }
        }

        foreach ((array) \Tools::getValue('SKLADX_PICKUP_CARRIERS', []) as $reference) {
            if (isset($carrierMap[(string) (int) $reference])) {
                $pickup[] = (int) $reference;
            }
        }

        Settings::set(Settings::CARRIER_MAP, $carrierMap);
        Settings::set(Settings::PICKUP_CARRIER_REFS, $pickup);
        self::attachCarriers($pickup);
        self::detachCarriers($pickup);
        $this->setTrackingUrls($carrierMap);

        Settings::set(Settings::PICKUP_PICKER, (bool) \Tools::getValue('SKLADX_PICKUP_PICKER'));
        Settings::set(Settings::PACKETA_WIDGET_KEY, trim((string) \Tools::getValue('SKLADX_PACKETA_WIDGET_KEY')));
        Settings::set(Settings::PRODUCT_SYNC, (bool) \Tools::getValue('SKLADX_PRODUCT_SYNC'));
        Settings::set(Settings::STOCK_SYNC, (bool) \Tools::getValue('SKLADX_STOCK_SYNC'));
        Settings::set(Settings::DEBUG, (bool) \Tools::getValue('SKLADX_DEBUG'));

        if (!$wasStockSync && Settings::stockSyncEnabled() && StockSync::queuePull()) {
            $this->messages[] = ['type' => 'info', 'text' => T::__('Stock sync turned on: all stock levels are being taken over from SkladX.')];
        }

        $this->messages[] = ['type' => 'success', 'text' => T::__('Settings saved.')];
    }

    private function testConnection(): void
    {
        if (!$this->requireConfigured()) {
            return;
        }

        try {
            $info = Client::fromSettings()->ping();
            Settings::set(Settings::CONNECTION_INFO, $info);
            $this->messages[] = ['type' => 'success', 'text' => T::f('Connected to SkladX. Client: %1$s, environment: %2$s.', self::infoName($info['client'] ?? null), self::infoName($info['environment'] ?? null))];
            $missing = array_diff(Settings::REQUIRED_SCOPES, (array) ($info['scopes'] ?? []));

            if ($missing !== []) {
                $this->messages[] = ['type' => 'warning', 'text' => T::f('The API key is missing these scopes: %s. Some functions will not work.', implode(', ', $missing))];
            }
        } catch (ApiException $exception) {
            $this->messages[] = ['type' => 'danger', 'text' => T::f('Connection failed: %s', $exception->summary())];
        }
    }

    /**
     * Name of a `{slug, name}` object of the ping response (or a plain string).
     *
     * @param mixed $value
     */
    public static function infoName($value): string
    {
        $name = is_array($value) ? (string) ($value['name'] ?? ($value['slug'] ?? '')) : (is_scalar($value) ? (string) $value : '');

        return $name !== '' ? $name : '?';
    }

    private function registerWebhook(): void
    {
        if (!$this->requireConfigured()) {
            return;
        }

        try {
            $id = WebhookRegistrar::register();
            $this->messages[] = ['type' => 'success', 'text' => T::f('Webhook registered in SkladX (%s), signing secret saved.', $id)];
        } catch (ApiException $exception) {
            $this->messages[] = ['type' => 'danger', 'text' => T::f('Registering the webhook failed: %s', $exception->summary())];
        }
    }

    private function pollEvents(): void
    {
        if (!$this->requireConfigured()) {
            return;
        }

        try {
            $first = (string) Settings::get(Settings::EVENTS_CURSOR) === '';
            $count = EventPoller::poll(true);
            Settings::set(Settings::LAST_POLL, time());
            $result = Runner::run(50, 20.0, false);
            $this->messages[] = ['type' => 'success', 'text' => $first
                ? T::__('Event polling initialised: newer events will be fetched from now on.')
                : T::f('%1$d missed events fetched, %2$d jobs processed.', $count, $result['jobs'])];
        } catch (ApiException $exception) {
            $this->messages[] = ['type' => 'danger', 'text' => T::f('Fetching events failed: %s', $exception->summary())];
        }
    }

    private function queued(callable $queue, string $message): bool
    {
        $queue();
        $this->messages[] = ['type' => 'success', 'text' => $message];

        return true;
    }

    private function requireConfigured(): bool
    {
        if (Settings::isConfigured()) {
            return true;
        }

        $this->messages[] = ['type' => 'warning', 'text' => T::__('Fill in and save the API URL and API key first.')];

        return false;
    }

    private function webhookProblemText(string $problem): string
    {
        switch ($problem) {
            case 'not_absolute':
                return T::__('The webhook URL is not an absolute URL. Check the shop domain in Shop parameters → Traffic & SEO.');
            case 'filesystem_path':
                return T::__('The webhook URL contains a server path. Check the shop domain and base URI in Shop parameters → Traffic & SEO.');
            case 'not_https':
                return T::__('The webhook URL is not HTTPS. SkladX delivers webhooks only to HTTPS addresses with a valid certificate.');
            case 'local_host':
                return T::__('The webhook URL is a local address that SkladX cannot reach. Missed events are still fetched by polling.');
            default:
                return '';
        }
    }

    /**
     * @return list<array{id_module: int, name: string, display_name: string}>
     */
    private function paymentModules(): array
    {
        $modules = [];

        foreach ((array) \PaymentModule::getInstalledPaymentModules() as $row) {
            $instance = \Module::getInstanceByName((string) $row['name']);
            $modules[] = [
                'id_module' => (int) $row['id_module'],
                'name' => (string) $row['name'],
                'display_name' => $instance ? (string) $instance->displayName : (string) $row['name'],
            ];
        }

        return $modules;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function carriers(int $idLang): array
    {
        $carriers = [];
        $map = Settings::carrierMap();
        $pickup = Settings::pickupCarrierReferences();

        foreach ((array) \Carrier::getCarriers($idLang, false, false, false, null, \Carrier::ALL_CARRIERS) as $carrier) {
            $reference = (int) $carrier['id_reference'];
            $carriers[] = [
                'id_carrier' => (int) $carrier['id_carrier'],
                'id_reference' => $reference,
                'name' => (string) $carrier['name'],
                'active' => (bool) $carrier['active'],
                'code' => $map[(string) $reference] ?? '',
                'pickup' => in_array($reference, $pickup, true),
                'foreign_module' => !empty($carrier['is_module']) && (string) $carrier['external_module_name'] !== 'skladx' ? (string) $carrier['external_module_name'] : '',
            ];
        }

        return $carriers;
    }

    /**
     * Attaches pickup point carriers to the module: PrestaShop shows the
     * picker (displayCarrierExtraContent) and validates the step only for
     * module carriers. Prices keep coming from the carrier's ranges.
     *
     * @param list<int> $references
     */
    public static function attachCarriers(array $references): void
    {
        foreach ($references as $reference) {
            \Db::getInstance()->update(
                'carrier',
                ['is_module' => 1, 'external_module_name' => 'skladx', 'shipping_external' => 0, 'need_range' => 1],
                '`id_reference` = ' . (int) $reference . ' AND `deleted` = 0 AND (`is_module` = 0 OR `external_module_name` = \'skladx\')'
            );
        }
    }

    /**
     * Releases module carriers that are no longer pickup point carriers
     * (all of them on uninstall).
     *
     * @param list<int> $keep id_reference values that stay attached
     */
    public static function detachCarriers(array $keep): void
    {
        $where = '`external_module_name` = \'skladx\'';

        if ($keep !== []) {
            $where .= ' AND `id_reference` NOT IN (' . implode(',', array_map('intval', $keep)) . ')';
        }

        \Db::getInstance()->update('carrier', ['is_module' => 0, 'external_module_name' => '', 'shipping_external' => 0], $where);
    }

    /**
     * Fills in the carrier tracking URL (used by the "in transit" e-mail)
     * where the merchant has not set one.
     *
     * @param array<string, string> $carrierMap
     */
    private function setTrackingUrls(array $carrierMap): void
    {
        foreach ($carrierMap as $reference => $code) {
            $template = Tracking::carrierUrlTemplate($code);

            if ($template !== '') {
                \Db::getInstance()->update('carrier', ['url' => pSQL($template)], '`id_reference` = ' . (int) $reference . ' AND `deleted` = 0 AND (`url` = \'\' OR `url` IS NULL)');
            }
        }
    }
}
