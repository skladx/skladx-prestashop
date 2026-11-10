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
 * Admin notices shown on top of every back office page (hook
 * displayDashboardTop) until dismissed. Messages are plain text; an optional
 * link is rendered by the template.
 */
final class Notices
{
    private const MAX = 20;

    /**
     * @param string $type success|warning|danger|info
     */
    public static function add(string $message, string $type = 'danger', string $link = '', string $linkText = ''): void
    {
        $notices = self::all();
        $id = substr(md5($type . $message), 0, 12);

        $notices = array_values(array_filter($notices, static fn (array $notice): bool => $notice['id'] !== $id));
        $notices[] = [
            'id' => $id,
            'type' => in_array($type, ['success', 'warning', 'danger', 'info'], true) ? $type : 'info',
            'message' => mb_substr($message, 0, 1000),
            'link' => $link,
            'link_text' => $linkText,
            'time' => time(),
        ];

        Settings::set(Settings::NOTICES, array_slice($notices, -self::MAX));
    }

    /**
     * @return list<array{id: string, type: string, message: string, link: string, link_text: string, time: int}>
     */
    public static function all(): array
    {
        $notices = [];

        foreach (Settings::getArray(Settings::NOTICES) as $notice) {
            if (is_array($notice) && isset($notice['id'], $notice['message'])) {
                $notices[] = [
                    'id' => (string) $notice['id'],
                    'type' => (string) ($notice['type'] ?? 'info'),
                    'message' => (string) $notice['message'],
                    'link' => (string) ($notice['link'] ?? ''),
                    'link_text' => (string) ($notice['link_text'] ?? ''),
                    'time' => (int) ($notice['time'] ?? 0),
                ];
            }
        }

        return $notices;
    }

    public static function dismiss(string $id): void
    {
        $remaining = $id === 'all' ? [] : array_values(array_filter(self::all(), static fn (array $notice): bool => $notice['id'] !== $id));
        Settings::set(Settings::NOTICES, $remaining);
    }
}
