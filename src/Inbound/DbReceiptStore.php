<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Inbound;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Receipt summaries in `skladx_receipt` (at most MAX rows, newest kept).
 */
final class DbReceiptStore implements ReceiptStore
{
    public const TABLE = 'skladx_receipt';
    private const MAX = 200;

    public function get(string $id): ?array
    {
        $summary = \Db::getInstance()->getValue('SELECT `summary` FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_receipt` = \'' . pSQL($id) . '\'', false);
        $decoded = is_string($summary) ? json_decode($summary, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    public function put(string $id, array $summary): void
    {
        $db = \Db::getInstance();
        $db->execute(
            'REPLACE INTO `' . _DB_PREFIX_ . self::TABLE . '` (`id_receipt`, `summary`, `date_upd`) VALUES (\'' . pSQL($id) . '\', \''
            . pSQL((string) json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true) . '\', \'' . date('Y-m-d H:i:s') . '\')'
        );

        $count = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '`', false);

        if ($count > self::MAX) {
            $db->execute('DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` ORDER BY `date_upd` ASC LIMIT ' . ($count - self::MAX));
        }
    }

    public function all(): array
    {
        $rows = \Db::getInstance()->executeS('SELECT `id_receipt`, `summary` FROM `' . _DB_PREFIX_ . self::TABLE . '` ORDER BY `date_upd` DESC', true, false);
        $all = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $summary = json_decode((string) $row['summary'], true);

            if (is_array($summary)) {
                $all[(string) $row['id_receipt']] = $summary;
            }
        }

        return $all;
    }
}
