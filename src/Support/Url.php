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
 * URL helpers. Pure PHP — unit tested.
 */
final class Url
{
    /**
     * Why SkladX probably cannot reach the webhook URL, or '' when it looks fine.
     *
     * @param string $url webhook URL
     * @param string $rootDir shop root directory (_PS_ROOT_DIR_)
     *
     * @return string ''|not_absolute|not_https|filesystem_path|local_host
     */
    public static function webhookProblem(string $url, string $rootDir): string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['host'])) {
            return 'not_absolute';
        }

        $root = rtrim(str_replace('\\', '/', $rootDir), '/');

        if ($root !== '' && str_contains((string) ($parts['path'] ?? ''), $root . '/')) {
            return 'filesystem_path';
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return 'not_https';
        }

        $host = strtolower((string) $parts['host']);

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test') || str_ends_with($host, '.local')) {
            return 'local_host';
        }

        return '';
    }

    /**
     * Normalises the API base URL: trimmed, no trailing slash, http(s) only
     * ('' when invalid).
     */
    public static function apiBase(string $url): string
    {
        $url = rtrim(trim($url), '/');

        return preg_match('~^https?://[^\s/]+~i', $url) ? $url : '';
    }
}
