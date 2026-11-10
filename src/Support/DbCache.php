<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Support;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Cache in the `skladx_cache` table (pickup point searches, rate limits).
 */
final class DbCache implements Cache
{
    public const TABLE = 'skladx_cache';

    public function get(string $key)
    {
        $row = \Db::getInstance()->getRow('SELECT `value`, `expires_at` FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `cache_key` = \'' . pSQL(self::key($key)) . '\'', false);

        if (!is_array($row) || (int) $row['expires_at'] < time()) {
            return null;
        }

        return json_decode((string) $row['value'], true);
    }

    public function set(string $key, $value, int $ttl): void
    {
        \Db::getInstance()->execute(
            'REPLACE INTO `' . _DB_PREFIX_ . self::TABLE . '` (`cache_key`, `value`, `expires_at`) VALUES (\''
            . pSQL(self::key($key)) . '\', \'' . pSQL((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true) . '\', ' . (time() + $ttl) . ')'
        );
    }

    /**
     * Fixed window counter (e.g. requests per minute); returns the new count.
     */
    public function increment(string $key, int $ttl): int
    {
        $count = (int) $this->get($key) + 1;
        $this->set($key, $count, $ttl);

        return $count;
    }

    public static function prune(): void
    {
        \Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `expires_at` < ' . time());
    }

    private static function key(string $key): string
    {
        return strlen($key) > 64 ? md5($key) : $key;
    }
}
