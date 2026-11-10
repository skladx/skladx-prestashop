<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Queue;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Database job queue (`skladx_queue`). PrestaShop has no background job
 * runner, so jobs are processed by the module cron URL, right after the
 * response of the request that queued them (fastcgi_finish_request) and
 * lazily on back office requests (see Runner).
 *
 * A pending job with the same (job, ref) is never queued twice. Claiming a
 * job is an atomic UPDATE, and a job whose worker died is released again
 * after LOCK_SECONDS.
 */
final class Queue
{
    public const TABLE = 'skladx_queue';

    public const ORDER_EXPORT = 'order_export';
    public const PRODUCT_SYNC = 'product_sync';
    public const PRODUCTS_ALL = 'products_all';
    public const STOCK_PULL = 'stock_pull';
    public const EVENT = 'event';

    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';

    private const LOCK_SECONDS = 300;

    /**
     * Queues a job unless the same one is already waiting.
     *
     * @param array<string, mixed> $payload
     *
     * @return int job id (existing one when deduplicated)
     */
    public static function push(string $job, string $ref = '', array $payload = [], int $delay = 0): int
    {
        $db = \Db::getInstance();
        $existing = (int) $db->getValue(
            'SELECT `id_skladx_queue` FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `job` = \'' . pSQL($job) . '\' AND `ref` = \'' . pSQL($ref) . '\' AND `status` IN (\'pending\', \'running\')',
            false
        );

        if ($existing > 0) {
            return $existing;
        }

        $now = date('Y-m-d H:i:s');
        $db->insert(self::TABLE, [
            'job' => pSQL($job),
            'ref' => pSQL(mb_substr($ref, 0, 191)),
            'payload' => pSQL((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true),
            'status' => self::PENDING,
            'attempts' => 0,
            'run_after' => date('Y-m-d H:i:s', time() + max(0, $delay)),
            'date_add' => $now,
            'date_upd' => $now,
        ]);

        return (int) $db->Insert_ID();
    }

    public static function isQueued(string $job, string $ref): bool
    {
        return (bool) \Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `job` = \'' . pSQL($job) . '\' AND `ref` = \'' . pSQL($ref) . '\' AND `status` IN (\'pending\', \'running\')',
            false
        );
    }

    /**
     * Ids of due jobs, oldest first.
     *
     * @return list<int>
     */
    public static function due(int $limit): array
    {
        $db = \Db::getInstance();
        $db->execute('UPDATE `' . _DB_PREFIX_ . self::TABLE . '` SET `status` = \'pending\' WHERE `status` = \'running\' AND `locked_until` < \'' . date('Y-m-d H:i:s') . '\'');

        $rows = $db->executeS('SELECT `id_skladx_queue` FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `status` = \'pending\' AND `run_after` <= \'' . date('Y-m-d H:i:s') . '\' ORDER BY `run_after`, `id_skladx_queue` LIMIT ' . max(1, $limit), true, false);

        return array_map(static fn (array $row): int => (int) $row['id_skladx_queue'], is_array($rows) ? $rows : []);
    }

    /**
     * Claims a job for this worker.
     *
     * @return array{id: int, job: string, ref: string, payload: array<string, mixed>, attempts: int}|null
     */
    public static function claim(int $id): ?array
    {
        $db = \Db::getInstance();
        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '` SET `status` = \'running\', `attempts` = `attempts` + 1, `locked_until` = \'' . date('Y-m-d H:i:s', time() + self::LOCK_SECONDS) . '\', `date_upd` = \'' . date('Y-m-d H:i:s') . '\''
            . ' WHERE `id_skladx_queue` = ' . $id . ' AND `status` = \'pending\''
        );

        if ((int) $db->Affected_Rows() !== 1) {
            return null;
        }

        $row = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_skladx_queue` = ' . $id, false);

        if (!is_array($row)) {
            return null;
        }

        $payload = json_decode((string) $row['payload'], true);

        return [
            'id' => (int) $row['id_skladx_queue'],
            'job' => (string) $row['job'],
            'ref' => (string) $row['ref'],
            'payload' => is_array($payload) ? $payload : [],
            'attempts' => (int) $row['attempts'],
        ];
    }

    public static function complete(int $id): void
    {
        self::update($id, ['status' => self::DONE, 'locked_until' => null, 'last_error' => null]);
    }

    public static function retry(int $id, int $delay, string $error): void
    {
        self::update($id, [
            'status' => self::PENDING,
            'run_after' => date('Y-m-d H:i:s', time() + max(0, $delay)),
            'locked_until' => null,
            'last_error' => pSQL(mb_substr($error, 0, 2000), true),
        ]);
    }

    public static function fail(int $id, string $error): void
    {
        self::update($id, ['status' => self::FAILED, 'locked_until' => null, 'last_error' => pSQL(mb_substr($error, 0, 2000), true)]);
    }

    /**
     * @return array<string, int> status => count
     */
    public static function counts(): array
    {
        $rows = \Db::getInstance()->executeS('SELECT `status`, COUNT(*) AS `total` FROM `' . _DB_PREFIX_ . self::TABLE . '` GROUP BY `status`', true, false);
        $counts = [self::PENDING => 0, self::RUNNING => 0, self::DONE => 0, self::FAILED => 0];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Latest failed jobs (settings page).
     *
     * @return list<array<string, mixed>>
     */
    public static function recentFailures(int $limit = 10): array
    {
        $rows = \Db::getInstance()->executeS('SELECT `job`, `ref`, `attempts`, `last_error`, `date_upd` FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `status` = \'failed\' ORDER BY `date_upd` DESC LIMIT ' . max(1, $limit), true, false);

        return is_array($rows) ? array_values($rows) : [];
    }

    public static function retryFailed(): int
    {
        $db = \Db::getInstance();
        $db->execute('UPDATE `' . _DB_PREFIX_ . self::TABLE . '` SET `status` = \'pending\', `attempts` = 0, `run_after` = \'' . date('Y-m-d H:i:s') . '\' WHERE `status` = \'failed\'');

        return (int) $db->Affected_Rows();
    }

    /**
     * Removes finished jobs after 7 days and failed ones after 30 days.
     */
    public static function prune(): void
    {
        $db = \Db::getInstance();
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `status` = \'done\' AND `date_upd` < \'' . date('Y-m-d H:i:s', time() - 7 * 86400) . '\'');
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `status` = \'failed\' AND `date_upd` < \'' . date('Y-m-d H:i:s', time() - 30 * 86400) . '\'');
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function update(int $id, array $values): void
    {
        $values['date_upd'] = date('Y-m-d H:i:s');
        \Db::getInstance()->update(self::TABLE, $values, '`id_skladx_queue` = ' . $id, 0, true);
    }
}
