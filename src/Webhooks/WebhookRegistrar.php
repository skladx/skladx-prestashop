<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Webhooks;

use SkladX\PrestaShop\Api\ApiException;
use SkladX\PrestaShop\Api\Client;
use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Support\Logger;
use SkladX\PrestaShop\Support\T;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Registers the module's webhook endpoint in SkladX and stores the signing
 * secret. The previously registered webhook is removed afterwards.
 */
final class WebhookRegistrar
{
    /**
     * @return string id of the new webhook
     *
     * @throws ApiException
     */
    public static function register(): string
    {
        $client = Client::fromSettings();
        $previous = (string) Settings::get(Settings::WEBHOOK_ID);
        $description = 'PrestaShop ' . Settings::siteHost();

        try {
            $webhook = $client->createWebhook(Settings::webhookUrl(), Settings::WEBHOOK_EVENTS, $description);
        } catch (ApiException $exception) {
            if ($exception->status !== 422) {
                throw $exception;
            }

            // Older SkladX versions without inbound receipts reject the inbound.* events.
            Logger::warning('Webhook registration with inbound events rejected, retrying without them: ' . $exception->summary());
            $webhook = $client->createWebhook(Settings::webhookUrl(), array_values(array_diff(Settings::WEBHOOK_EVENTS, Settings::OPTIONAL_WEBHOOK_EVENTS)), $description);
        }

        if (empty($webhook['secret']) || empty($webhook['id'])) {
            throw new ApiException(T::__('SkladX did not return the webhook secret.'), 200, 'unexpected_response');
        }

        Settings::set(Settings::WEBHOOK_SECRET, (string) $webhook['secret']);
        Settings::set(Settings::WEBHOOK_ID, (string) $webhook['id']);

        if ($previous !== '' && $previous !== (string) $webhook['id']) {
            try {
                $client->deleteWebhook($previous);
            } catch (ApiException $exception) {
                Logger::warning('Old webhook could not be deleted: ' . $exception->summary());
            }
        }

        return (string) $webhook['id'];
    }
}
