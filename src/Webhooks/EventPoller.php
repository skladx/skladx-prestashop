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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Pull fallback for missed webhooks: `GET /events?after=<last evt_…>`.
 *
 * The API lists newest first, so all pages are collected and reversed. On
 * the very first run the cursor is only initialised (no history backfill).
 */
final class EventPoller
{
    public const INTERVAL = 600;
    public const MAX_PAGES = 10;

    /**
     * @return int number of new events found
     *
     * @throws ApiException when SkladX cannot be reached (only with $throw)
     */
    public static function poll(bool $throw = false): int
    {
        if (!Settings::isConfigured()) {
            return 0;
        }

        $client = Client::fromSettings();
        $cursor = (string) Settings::get(Settings::EVENTS_CURSOR);
        $events = [];

        try {
            if ($cursor === '') {
                $latest = $client->events(null, 1)['data'][0]['id'] ?? '';
                Settings::set(Settings::EVENTS_CURSOR, (string) $latest);

                return 0;
            }

            $page = null;

            for ($i = 0; $i < self::MAX_PAGES; ++$i) {
                $response = $client->events($cursor, 100, $page);
                $events = array_merge($events, $response['data']);
                $page = $response['meta']['next_cursor'] ?? null;

                if (empty($response['meta']['has_more']) || empty($page)) {
                    break;
                }
            }
        } catch (ApiException $exception) {
            Logger::error('Polling SkladX events failed: ' . $exception->summary());

            if ($throw) {
                throw $exception;
            }

            return 0;
        }

        if ($events === []) {
            return 0;
        }

        $new = 0;

        foreach (array_reverse($events) as $envelope) {
            $eventId = (string) ($envelope['id'] ?? '');
            $type = (string) ($envelope['type'] ?? '');

            if ($eventId !== '' && $type !== '' && EventProcessor::receive($eventId, $type, $envelope, 'poll')) {
                ++$new;
            }
        }

        Settings::set(Settings::EVENTS_CURSOR, (string) ($events[0]['id'] ?? $cursor));

        if ($new > 0) {
            Logger::warning(sprintf('Polling found %d SkladX event(s) not delivered by webhook.', $new));
        }

        return $new;
    }
}
