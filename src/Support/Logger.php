<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Support;

use SkladX\PrestaShop\Config\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Writes to var/logs/skladx-YYYY-MM-DD.log (debug and info only when the
 * debug log is on; warnings and errors always). Errors also go to the
 * PrestaShop log (Advanced parameters → Logs). Secrets are masked.
 */
final class Logger
{
    public static function debug(string $message, array $context = []): void
    {
        self::write('DEBUG', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);

        if (class_exists('PrestaShopLogger')) {
            \PrestaShopLogger::addLog('SkladX: ' . mb_substr((string) Redactor::redact($message, self::secrets()), 0, 900), 3, null, 'SkladX', 0, true);
        }
    }

    public static function file(): string
    {
        $dir = defined('_PS_ROOT_DIR_') ? _PS_ROOT_DIR_ . '/var/logs' : sys_get_temp_dir();

        return $dir . '/skladx-' . date('Y-m-d') . '.log';
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (in_array($level, ['DEBUG', 'INFO'], true) && !Settings::debugEnabled()) {
            return;
        }

        $line = sprintf('[%s] %s: %s', date('c'), $level, (string) Redactor::redact($message, self::secrets()));

        if ($context !== []) {
            $line .= ' ' . json_encode(Redactor::redact($context, self::secrets()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        @file_put_contents(self::file(), $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<string>
     */
    private static function secrets(): array
    {
        return array_values(array_filter([Settings::apiKey(), Settings::webhookSecret()]));
    }
}
