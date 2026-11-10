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

use SkladX\PrestaShop\Api\ApiException;
use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Pickup\Checkout;
use SkladX\PrestaShop\Pickup\PickupPoint;
use SkladX\PrestaShop\Support\DbCache;
use SkladX\PrestaShop\Support\Logger;
use SkladX\PrestaShop\Support\T;

/**
 * Pickup point proxy for the checkout picker (the SkladX API key never
 * reaches the browser):
 *
 * - `GET  /module/skladx/pickup?action=search&carrier=packeta&q=…&near=lat,lng&cod=1&cursor=…&country=SK`
 * - `POST /module/skladx/pickup` `action=save&carrier=…&point_id=…&token=…[&widget=JSON]`
 *
 * Only carriers of pickup point carriers are allowed, answers are cached per
 * query, and calls that miss the cache are rate limited per visitor.
 */
class SkladxPickupModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    public function postProcess(): void
    {
        $action = (string) Tools::getValue('action');

        if (!Settings::isConfigured() || !Settings::pickupPickerEnabled()) {
            $this->respond(404, ['message' => T::__('Pickup point search is not available.')]);
        }

        if ($action === 'search') {
            $this->search();
        }

        if ($action === 'save' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $this->save();
        }

        $this->respond(400, ['message' => 'Unknown action.']);
    }

    private function search(): void
    {
        try {
            $query = PickupPoint::sanitizeQuery($_GET, Settings::pickupCarrierCodes(), Settings::shopCountryIso());
        } catch (InvalidArgumentException $exception) {
            $this->respond(400, ['message' => T::__('Invalid pickup point search.')]);
        }

        if ((new DbCache())->get(PickupPoint::cacheKey($query)) === null && !Checkout::withinRateLimit()) {
            $this->respond(429, ['message' => T::__('Too many searches, please wait a moment.')]);
        }

        try {
            $result = Checkout::service()->search($query);
        } catch (ApiException $exception) {
            Logger::error('Pickup point search failed: ' . $exception->summary());
            $this->respond(502, ['message' => T::__('Pickup points cannot be loaded right now. Please try again in a moment.')]);
        }

        $labels = Checkout::dayLabels();

        header('Cache-Control: private, max-age=300');
        $this->respond(200, [
            'points' => array_map(static fn (array $point): array => PickupPoint::forBrowser($point, $labels), $result['points']),
            'next_cursor' => $result['next_cursor'],
            'has_more' => $result['has_more'],
        ]);
    }

    private function save(): void
    {
        if ((string) Tools::getValue('token') !== Tools::getToken(false)) {
            $this->respond(403, ['message' => T::__('Your session has expired. Please reload the page.')]);
        }

        $cart = $this->context->cart;

        if (!Validate::isLoadedObject($cart)) {
            $this->respond(400, ['message' => T::__('Your session has expired. Please reload the page.')]);
        }

        $widget = json_decode((string) Tools::getValue('widget'), true);

        try {
            $point = Checkout::choose($cart, (string) Tools::getValue('carrier'), trim((string) Tools::getValue('point_id')), is_array($widget) ? $widget : null);
        } catch (InvalidArgumentException $exception) {
            $this->respond(422, ['message' => $exception->getMessage()]);
        }

        $this->respond(200, [
            'point' => PickupPoint::forBrowser($point, Checkout::dayLabels()),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
