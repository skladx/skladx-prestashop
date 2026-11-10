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

use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Queue\Queue;
use SkladX\PrestaShop\Queue\Runner;
use SkladX\PrestaShop\Support\Logger;
use SkladX\PrestaShop\Webhooks\EventProcessor;
use SkladX\PrestaShop\Webhooks\SignatureVerifier;

/**
 * `POST /module/skladx/webhook` (index.php?fc=module&module=skladx&controller=webhook)
 *
 * Verifies the HMAC signature over the raw body, stores the event once
 * (dedupe by X-SkladX-Event-Id), answers 200 immediately and processes the
 * event after the response has been sent.
 */
class SkladxWebhookModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    /** @var bool */
    public $ssl = true;

    /** @var bool */
    protected $maintenance = false;

    public function postProcess(): void
    {
        $this->handle();
    }

    /**
     * Webhooks are accepted while the shop is in maintenance mode.
     */
    protected function displayMaintenancePage(): void
    {
    }

    /**
     * Webhooks are accepted from every country.
     */
    protected function displayRestrictedCountryPage(): void
    {
    }

    private function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->respond(405, ['error' => ['code' => 'method_not_allowed', 'message' => 'Use POST.']]);
        }

        $secret = Settings::webhookSecret();

        if ($secret === '') {
            $this->respond(503, ['error' => ['code' => 'not_configured', 'message' => 'Webhook secret is not configured.']]);
        }

        $body = (string) file_get_contents('php://input');
        $signature = (string) ($_SERVER['HTTP_X_SKLADX_SIGNATURE'] ?? '');
        $headerEventId = (string) ($_SERVER['HTTP_X_SKLADX_EVENT_ID'] ?? '');

        if (!SignatureVerifier::verify($secret, $body, $signature)) {
            Logger::warning('Webhook rejected: invalid or expired signature.', ['event_id' => $headerEventId]);
            $this->respond(401, ['error' => ['code' => 'invalid_signature', 'message' => 'Invalid or expired signature.']]);
        }

        $envelope = json_decode($body, true);

        if (!is_array($envelope)) {
            $this->respond(400, ['error' => ['code' => 'invalid_payload', 'message' => 'Body is not valid JSON.']]);
        }

        $eventId = $headerEventId !== '' ? $headerEventId : (string) ($envelope['id'] ?? '');
        $type = (string) ($_SERVER['HTTP_X_SKLADX_EVENT'] ?? '');
        $type = $type !== '' ? $type : (string) ($envelope['type'] ?? '');

        if ($eventId === '' || $type === '') {
            $this->respond(400, ['error' => ['code' => 'invalid_payload', 'message' => 'Missing event id or type.']]);
        }

        if (!EventProcessor::receive($eventId, $type, $envelope, 'webhook')) {
            $this->respond(200, ['received' => true, 'duplicate' => true]);
        }

        Logger::debug(sprintf('Webhook %s (%s) received, delivery %s.', $eventId, $type, (string) ($_SERVER['HTTP_X_SKLADX_DELIVERY'] ?? '')));

        if ($type !== 'ping') {
            Runner::runAfterResponse(Queue::push(Queue::EVENT, $eventId));
        }

        $this->respond(200, ['received' => true]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body);
        exit;
    }
}
