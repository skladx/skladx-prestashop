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
 * Masks secrets before anything is logged. Pure PHP — unit tested.
 */
final class Redactor
{
    /** Keys whose values are always masked in arrays. */
    private const SENSITIVE_KEYS = ['authorization', 'api_key', 'secret', 'webhook_secret', 'x-skladx-signature', 'password', 'token'];

    /**
     * Masks a secret, keeping only the last four characters.
     */
    public static function mask(string $secret): string
    {
        if ($secret === '') {
            return '';
        }

        $visible = strlen($secret) > 12 ? substr($secret, -4) : '';

        return str_repeat('•', 8) . $visible;
    }

    /**
     * Redacts API keys (`skx_live_…`, `skx_test_…`), bearer tokens, webhook
     * secrets (`whsec_…`) and the given known secrets from a string or array.
     *
     * @param mixed $value string, array or scalar
     * @param list<string> $secrets known secret values to mask wherever they appear
     *
     * @return mixed
     */
    public static function redact($value, array $secrets = [])
    {
        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true) && is_scalar($item) && (string) $item !== '') {
                    $result[$key] = '[redacted]';
                    continue;
                }

                $result[$key] = self::redact($item, $secrets);
            }

            return $result;
        }

        if (!is_string($value)) {
            return $value;
        }

        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 6) {
                $value = str_replace($secret, '[redacted]', $value);
            }
        }

        $value = (string) preg_replace('/\bskx_(live|test)_[A-Za-z0-9]+/', 'skx_$1_[redacted]', $value);
        $value = (string) preg_replace('/\bwhsec_[A-Za-z0-9]+/', 'whsec_[redacted]', $value);

        return (string) preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[redacted]', $value);
    }
}
