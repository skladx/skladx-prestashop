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
 * Translation helper for module classes.
 *
 * Source strings are English. Translations live in translations/<iso>.php
 * (PrestaShop module translation format), all PHP strings under the source
 * key "skladx". Always call it with a single-quoted literal so that
 * dev/i18n.php can extract the strings. Returns plain text (not HTML
 * escaped) — escape when printing.
 */
final class T
{
    public static function __(string $text): string
    {
        if (!defined('_PS_ROOT_DIR_') || !class_exists('Translate')) {
            return $text;
        }

        $translated = \Translate::getModuleTranslation('skladx', $text, 'skladx', null, false, null, true, false);

        return is_string($translated) ? $translated : $text;
    }

    /**
     * Translated text with sprintf() arguments.
     *
     * @param string $text source text with %s / %d / %1$s placeholders
     * @param mixed ...$args Arguments.
     */
    public static function f(string $text, ...$args): string
    {
        return vsprintf(self::__($text), $args);
    }
}
