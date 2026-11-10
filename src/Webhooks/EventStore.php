<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Webhooks;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Inbox of received SkladX events (webhooks and polling), used for dedupe.
 * The primary key makes `INSERT IGNORE` an atomic "first time we see this
 * event?" check, so a webhook delivered twice, or a webhook and the polling
 * fallback seeing the same event, is processed once.
 */
final class EventStore
{
    public const TABLE = 'skladx_event';
    public const RETENTION_DAYS = 30;

    /**
     * Stores the event. Returns false when it was already stored (duplicate).
     *
     * @param array<string, mixed> $envelope full event envelope `{id, type, created_at, test, data}`
     */
    public static function insert(string $eventId, string $type, array $envelope, string $source): bool
    {
        $db = \Db::getInstance();
        $db->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . self::TABLE . '` (`event_id`, `type`, `source`, `payload`, `status`, `received_at`) VALUES (\''
            . pSQL(mb_substr($eventId, 0, 64)) . '\', \'' . pSQL(mb_substr($type, 0, 64)) . '\', \'' . pSQL($source) . '\', \''
            . pSQL((string) json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true) . '\', \'pending\', \'' . date('Y-m-d H:i:s') . '\')'
        );

        return (int) $db->Affected_Rows() === 1;
    }

    /**
     * @return array{event_id: string, type: string, status: string, envelope: array<string, mixed>}|null
     */
    public static function find(string $eventId): ?array
    {
        $row = \Db::getInstance()->getRow('SELECT `event_id`, `type`, `status`, `payload` FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `event_id` = \'' . pSQL($eventId) . '\'', false);

        if (!is_array($row)) {
            return null;
        }

        $envelope = json_decode((string) $row['payload'], true);

        return [
            'event_id' => (string) $row['event_id'],
            'type' => (string) $row['type'],
            'status' => (string) $row['status'],
            'envelope' => is_array($envelope) ? $envelope : [],
        ];
    }

    public static function mark(string $eventId, string $status, ?string $error = null): void
    {
        \Db::getInstance()->update(
            self::TABLE,
            [
                'status' => pSQL($status),
                'error' => $error !== null ? pSQL(mb_substr($error, 0, 1000), true) : null,
                'processed_at' => date('Y-m-d H:i:s'),
            ],
            '`event_id` = \'' . pSQL($eventId) . '\'',
            0,
            true
        );
    }

    /**
     * @return array<string, int> status => count
     */
    public static function counts(): array
    {
        $rows = \Db::getInstance()->executeS('SELECT `status`, COUNT(*) AS `total` FROM `' . _DB_PREFIX_ . self::TABLE . '` GROUP BY `status`', true, false);
        $counts = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 10): array
    {
        $rows = \Db::getInstance()->executeS('SELECT `event_id`, `type`, `source`, `status`, `error`, `received_at` FROM `' . _DB_PREFIX_ . self::TABLE . '` ORDER BY `received_at` DESC LIMIT ' . max(1, $limit), true, false);

        return is_array($rows) ? array_values($rows) : [];
    }

    public static function prune(): void
    {
        \Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `received_at` < \'' . date('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400) . '\'');
    }
}
