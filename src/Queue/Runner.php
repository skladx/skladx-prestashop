<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Queue;

use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Inbound\Receipts;
use SkladX\PrestaShop\Support\DbCache;
use SkladX\PrestaShop\Support\Logger;
use SkladX\PrestaShop\Sync\OrderExporter;
use SkladX\PrestaShop\Sync\ProductSync;
use SkladX\PrestaShop\Sync\StockSync;
use SkladX\PrestaShop\Webhooks\EventPoller;
use SkladX\PrestaShop\Webhooks\EventProcessor;
use SkladX\PrestaShop\Webhooks\EventStore;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Processes due jobs and periodic tasks.
 *
 * Triggers: the module cron URL (every minute, recommended), right after
 * the response of a request that queued a job (order validation, webhook),
 * and lazily on back office requests at most once a minute. A MySQL named
 * lock keeps runners from overlapping.
 */
final class Runner
{
    /** Seconds between lazy runs from back office requests. */
    public const LAZY_INTERVAL = 60;

    /**
     * @return array{jobs: int, failed: int, skipped: bool}
     */
    public static function run(int $maxJobs = 25, float $budgetSeconds = 20.0, bool $periodic = true): array
    {
        $result = ['jobs' => 0, 'failed' => 0, 'skipped' => false];

        if (!self::lock()) {
            $result['skipped'] = true;

            return $result;
        }

        $started = microtime(true);
        Settings::set(Settings::LAST_RUN, time());

        try {
            if ($periodic && Settings::isConfigured()) {
                self::periodic();
            }

            foreach (Queue::due($maxJobs) as $id) {
                if (microtime(true) - $started > $budgetSeconds) {
                    break;
                }

                $outcome = self::runJob($id);

                if ($outcome !== null) {
                    ++$result['jobs'];
                    $result['failed'] += $outcome ? 0 : 1;
                }
            }
        } finally {
            self::unlock();
        }

        return $result;
    }

    /**
     * Runs one job now (e.g. right after it was queued).
     *
     * @return bool|null true = done, false = failed or retried, null = not claimed
     */
    public static function runJob(int $id): ?bool
    {
        $job = Queue::claim($id);

        if ($job === null) {
            return null;
        }

        try {
            self::handle($job);
            Queue::complete($id);

            return true;
        } catch (RetryLater $exception) {
            Queue::retry($id, $exception->delay, $exception->getMessage());
        } catch (\Throwable $exception) {
            $delay = Backoff::delay($job['attempts']);

            if ($delay !== null) {
                Queue::retry($id, $delay, $exception->getMessage());
            } else {
                Queue::fail($id, $exception->getMessage());
            }

            Logger::error(sprintf('SkladX job %s (%s) failed: %s', $job['job'], $job['ref'], $exception->getMessage()));
        }

        return false;
    }

    /**
     * Runs $id after the response has been sent to the browser (PHP-FPM),
     * so the visitor never waits for SkladX. Without FastCGI the job stays
     * queued for the cron / the next back office request.
     */
    public static function runAfterResponse(int $id): void
    {
        if ($id <= 0 || !function_exists('fastcgi_finish_request')) {
            return;
        }

        register_shutdown_function(static function () use ($id): void {
            if (connection_status() === CONNECTION_NORMAL) {
                @fastcgi_finish_request();
            }

            ignore_user_abort(true);
            @set_time_limit(60);

            try {
                if (self::lock(2)) {
                    try {
                        self::runJob($id);
                    } finally {
                        self::unlock();
                    }
                }
            } catch (\Throwable $exception) {
                Logger::error('SkladX job after response failed: ' . $exception->getMessage());
            }
        });
    }

    /**
     * Lazy processing on back office requests (at most every LAZY_INTERVAL s).
     */
    public static function runLazily(): void
    {
        if (!function_exists('fastcgi_finish_request') || (time() - (int) Settings::get(Settings::LAST_RUN)) < self::LAZY_INTERVAL) {
            return;
        }

        Settings::set(Settings::LAST_RUN, time());

        register_shutdown_function(static function (): void {
            if (connection_status() === CONNECTION_NORMAL) {
                @fastcgi_finish_request();
            }

            ignore_user_abort(true);
            @set_time_limit(60);

            try {
                self::run(10, 8.0);
            } catch (\Throwable $exception) {
                Logger::error('SkladX lazy queue run failed: ' . $exception->getMessage());
            }
        });
    }

    /**
     * @param array{id: int, job: string, ref: string, payload: array<string, mixed>, attempts: int} $job
     */
    private static function handle(array $job): void
    {
        switch ($job['job']) {
            case Queue::ORDER_EXPORT:
                OrderExporter::export((int) $job['ref'], $job['attempts']);
                break;
            case Queue::PRODUCT_SYNC:
                ProductSync::syncQueued((int) $job['ref'], $job['attempts']);
                break;
            case Queue::PRODUCTS_ALL:
                ProductSync::syncBatch($job['payload']);
                break;
            case Queue::STOCK_PULL:
                StockSync::pull($job['payload']);
                break;
            case Queue::EVENT:
                EventProcessor::process($job['ref']);
                break;
        }
    }

    /**
     * Polling fallback every 10 minutes, receipt refresh every 30 minutes,
     * cleanup once a day.
     */
    private static function periodic(): void
    {
        $now = time();

        if ($now - (int) Settings::get(Settings::LAST_POLL) >= EventPoller::INTERVAL) {
            Settings::set(Settings::LAST_POLL, $now);
            EventPoller::poll();
        }

        if ($now - (int) Settings::get(Settings::LAST_INBOUND_REFRESH) >= Receipts::REFRESH_INTERVAL) {
            Settings::set(Settings::LAST_INBOUND_REFRESH, $now);
            Receipts::refresh();
        }

        if ($now - (int) Settings::get(Settings::LAST_PRUNE) >= 86400) {
            Settings::set(Settings::LAST_PRUNE, $now);
            Queue::prune();
            EventStore::prune();
            DbCache::prune();
        }
    }

    private static function lock(int $timeout = 0): bool
    {
        return (int) \Db::getInstance()->getValue('SELECT GET_LOCK(\'' . pSQL(self::lockName()) . '\', ' . $timeout . ')', false) === 1;
    }

    private static function unlock(): void
    {
        \Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'' . pSQL(self::lockName()) . '\')', false);
    }

    private static function lockName(): string
    {
        return 'skladx_runner_' . substr(md5(_DB_NAME_ . _DB_PREFIX_), 0, 12);
    }
}
