<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Config;

use SkladX\PrestaShop\Support\Url;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Typed access to the module configuration (global `SKLADX_*` values in the
 * PrestaShop configuration table). The only place that knows the keys and
 * defaults. Arrays are stored as JSON.
 */
final class Settings
{
    public const MODULE_VERSION = '0.2.0';

    public const DEFAULT_API_URL = 'https://skladx.sk/api/v1';

    /** Carrier codes accepted by SkladX. */
    public const CARRIERS = ['packeta', 'dpd_sk', 'gls_sk', 'sps', 'posta_sk', 'balikovo'];

    /** Carriers whose shop carriers deliver to a pickup point by default. */
    public const PICKUP_CARRIERS = ['packeta'];

    /** Events the module subscribes to when it registers its webhook. */
    public const WEBHOOK_EVENTS = [
        'order.status_changed',
        'order.shipped',
        'order.delivered',
        'order.delivery_failed',
        'order.cancelled',
        'order.returned',
        'shipment.tracking_updated',
        'stock.changed',
        'product.created',
        'product.updated',
        'inbound.announced',
        'inbound.arrived',
        'inbound.received',
        'inbound.cancelled',
    ];

    /** Events SkladX versions without inbound receipts may reject (registration retries without them). */
    public const OPTIONAL_WEBHOOK_EVENTS = ['inbound.announced', 'inbound.arrived', 'inbound.received', 'inbound.cancelled'];

    /** Scopes the module needs (Test connection warns about missing ones). */
    public const REQUIRED_SCOPES = ['orders:read', 'orders:write', 'products:write', 'events:read', 'webhooks:write', 'pickup_points:read', 'stock:read', 'inbound:read', 'inbound:write'];

    public const API_URL = 'SKLADX_API_URL';
    public const API_KEY = 'SKLADX_API_KEY';
    public const WEBHOOK_SECRET = 'SKLADX_WEBHOOK_SECRET';
    public const WEBHOOK_ID = 'SKLADX_WEBHOOK_ID';
    public const EXPORT_STATES = 'SKLADX_EXPORT_STATES';
    public const CARRIER_MAP = 'SKLADX_CARRIER_MAP';
    public const PICKUP_CARRIER_REFS = 'SKLADX_PICKUP_CARRIERS';
    public const COD_MODULES = 'SKLADX_COD_MODULES';
    public const PACKETA_WIDGET_KEY = 'SKLADX_PACKETA_WIDGET_KEY';
    public const PICKUP_PICKER = 'SKLADX_PICKUP_PICKER';
    public const PRODUCT_SYNC = 'SKLADX_PRODUCT_SYNC';
    public const STOCK_SYNC = 'SKLADX_STOCK_SYNC';
    public const STATUS_MAP = 'SKLADX_STATUS_MAP';
    public const TRACKING_EMAIL = 'SKLADX_TRACKING_EMAIL';
    public const DEBUG = 'SKLADX_DEBUG';
    public const EVENTS_CURSOR = 'SKLADX_EVENTS_CURSOR';
    public const CONNECTION_INFO = 'SKLADX_CONNECTION_INFO';
    public const CRON_TOKEN = 'SKLADX_CRON_TOKEN';
    public const NOTICES = 'SKLADX_NOTICES';
    public const LAST_RUN = 'SKLADX_LAST_RUN';
    public const LAST_CRON = 'SKLADX_LAST_CRON';
    public const LAST_POLL = 'SKLADX_LAST_POLL';
    public const LAST_INBOUND_REFRESH = 'SKLADX_LAST_INBOUND_REFRESH';
    public const LAST_PRUNE = 'SKLADX_LAST_PRUNE';

    /**
     * Every key with its default (arrays are JSON encoded on save).
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            self::API_URL => self::DEFAULT_API_URL,
            self::API_KEY => '',
            self::WEBHOOK_SECRET => '',
            self::WEBHOOK_ID => '',
            self::EXPORT_STATES => self::defaultExportStates(),
            self::CARRIER_MAP => [],
            self::PICKUP_CARRIER_REFS => [],
            self::COD_MODULES => ['ps_cashondelivery'],
            self::PACKETA_WIDGET_KEY => '',
            self::PICKUP_PICKER => 1,
            self::PRODUCT_SYNC => 1,
            self::STOCK_SYNC => 0,
            self::STATUS_MAP => self::defaultStatusMap(),
            self::TRACKING_EMAIL => 1,
            self::DEBUG => 0,
            self::EVENTS_CURSOR => '',
            self::CONNECTION_INFO => [],
            self::CRON_TOKEN => bin2hex(random_bytes(20)),
            self::NOTICES => [],
            self::LAST_RUN => 0,
            self::LAST_CRON => 0,
            self::LAST_POLL => 0,
            self::LAST_INBOUND_REFRESH => 0,
            self::LAST_PRUNE => 0,
        ];
    }

    public static function install(): bool
    {
        foreach (self::defaults() as $key => $value) {
            if (\Configuration::getGlobalValue($key) === false) {
                self::set($key, $value);
            }
        }

        return true;
    }

    public static function uninstall(): bool
    {
        foreach (array_keys(self::defaults()) as $key) {
            \Configuration::deleteByName($key);
        }

        return true;
    }

    /**
     * @return mixed
     */
    public static function get(string $key)
    {
        $value = \Configuration::getGlobalValue($key);

        if ($value === false || $value === null) {
            return self::defaults()[$key] ?? null;
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    public static function set(string $key, $value): void
    {
        if (is_array($value)) {
            $value = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        \Configuration::updateGlobalValue($key, (string) $value, true);
    }

    /**
     * @return array<mixed>
     */
    public static function getArray(string $key): array
    {
        $value = self::get($key);

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function apiUrl(): string
    {
        return Url::apiBase((string) self::get(self::API_URL));
    }

    public static function apiKey(): string
    {
        return trim((string) self::get(self::API_KEY));
    }

    public static function webhookSecret(): string
    {
        return trim((string) self::get(self::WEBHOOK_SECRET));
    }

    public static function isConfigured(): bool
    {
        return self::apiUrl() !== '' && self::apiKey() !== '';
    }

    /**
     * @return list<int> order states that send an order to SkladX
     */
    public static function exportStates(): array
    {
        return array_values(array_filter(array_map('intval', self::getArray(self::EXPORT_STATES))));
    }

    /**
     * @return array<string, string> carrier id_reference => SkladX carrier code
     */
    public static function carrierMap(): array
    {
        $map = [];

        foreach (self::getArray(self::CARRIER_MAP) as $reference => $code) {
            if ((int) $reference > 0 && in_array((string) $code, self::CARRIERS, true)) {
                $map[(string) (int) $reference] = (string) $code;
            }
        }

        return $map;
    }

    /**
     * @return list<int> carrier id_reference values that deliver to a pickup point
     */
    public static function pickupCarrierReferences(): array
    {
        return array_values(array_filter(array_map('intval', self::getArray(self::PICKUP_CARRIER_REFS))));
    }

    /**
     * SkladX carrier code of a pickup point carrier ('' when the carrier is not one).
     */
    public static function pickupCarrierCode(int $carrierReference): string
    {
        if (!in_array($carrierReference, self::pickupCarrierReferences(), true)) {
            return '';
        }

        return self::carrierMap()[(string) $carrierReference] ?? '';
    }

    /**
     * Carrier codes that have at least one pickup point carrier.
     *
     * @return list<string>
     */
    public static function pickupCarrierCodes(): array
    {
        $codes = [];

        foreach (self::pickupCarrierReferences() as $reference) {
            $code = self::carrierMap()[(string) $reference] ?? '';

            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return list<string> payment module names that mean cash on delivery
     */
    public static function codModules(): array
    {
        return array_values(array_filter(array_map('strval', self::getArray(self::COD_MODULES))));
    }

    public static function isCodModule(string $module): bool
    {
        return $module !== '' && in_array($module, self::codModules(), true);
    }

    /**
     * @return array<string, int> SkladX status => id_order_state
     */
    public static function statusMap(): array
    {
        return array_map('intval', array_filter(self::getArray(self::STATUS_MAP)));
    }

    public static function pickupPickerEnabled(): bool
    {
        return (bool) self::get(self::PICKUP_PICKER);
    }

    public static function packetaWidgetKey(): string
    {
        return trim((string) self::get(self::PACKETA_WIDGET_KEY));
    }

    public static function productSyncEnabled(): bool
    {
        return (bool) self::get(self::PRODUCT_SYNC);
    }

    public static function stockSyncEnabled(): bool
    {
        return (bool) self::get(self::STOCK_SYNC);
    }

    public static function trackingEmailEnabled(): bool
    {
        return (bool) self::get(self::TRACKING_EMAIL);
    }

    public static function debugEnabled(): bool
    {
        return (bool) self::get(self::DEBUG);
    }

    public static function cronToken(): string
    {
        $token = (string) self::get(self::CRON_TOKEN);

        if (strlen($token) < 20) {
            $token = bin2hex(random_bytes(20));
            self::set(self::CRON_TOKEN, $token);
        }

        return $token;
    }

    /**
     * Host of the shop, used in idempotency keys and order metadata.
     */
    public static function siteHost(): string
    {
        $shop = \Context::getContext()->shop;
        $domain = $shop && $shop->domain_ssl ? (string) $shop->domain_ssl : (string) \Tools::getShopDomainSsl(false);

        return strtolower((string) (parse_url('https://' . $domain, PHP_URL_HOST) ?: $domain));
    }

    /**
     * Public URL of the webhook endpoint (front controller).
     */
    public static function webhookUrl(): string
    {
        return \Context::getContext()->link->getModuleLink('skladx', 'webhook', [], true);
    }

    public static function cronUrl(): string
    {
        return \Context::getContext()->link->getModuleLink('skladx', 'cron', ['token' => self::cronToken()], true);
    }

    public static function shopCountryIso(): string
    {
        $iso = \Country::getIsoById((int) \Configuration::get('PS_COUNTRY_DEFAULT'));

        return is_string($iso) && $iso !== '' ? strtoupper($iso) : 'SK';
    }

    public static function defaultCurrencyIso(): string
    {
        $currency = \Currency::getDefaultCurrency();

        return $currency ? strtoupper((string) $currency->iso_code) : 'EUR';
    }

    /**
     * @return list<int>
     */
    private static function defaultExportStates(): array
    {
        $states = [];

        foreach (['PS_OS_PAYMENT', 'PS_OS_WS_PAYMENT', 'PS_OS_COD_VALIDATION', 'PS_OS_PREPARATION', 'PS_OS_OUTOFSTOCK_PAID'] as $key) {
            $state = (int) \Configuration::get($key);

            if ($state > 0) {
                $states[] = $state;
            }
        }

        return array_values(array_unique($states));
    }

    /**
     * @return array<string, int>
     */
    private static function defaultStatusMap(): array
    {
        return array_filter([
            'handed_over' => (int) \Configuration::get('PS_OS_SHIPPING'),
            'delivered' => (int) \Configuration::get('PS_OS_DELIVERED'),
            'cancelled' => (int) \Configuration::get('PS_OS_CANCELED'),
        ]);
    }
}
