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
 * `X-SkladX-Signature: t=<unix>,v1=<hex hmac_sha256(secret, "<t>.<raw body>")>`.
 *
 * The timestamp is part of the signed string; requests older (or newer) than
 * TOLERANCE seconds are rejected so a captured request cannot be replayed.
 * Pure PHP — unit tested.
 */
final class SignatureVerifier
{
    public const TOLERANCE = 300;

    /**
     * @param string $secret webhook signing secret (`whsec_…`)
     * @param string $body raw request body, exactly as received
     * @param string $header value of X-SkladX-Signature
     * @param int|null $now current unix time (for tests)
     */
    public static function verify(string $secret, string $body, string $header, ?int $now = null): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            if ($pair[0] === 't') {
                $timestamp = $pair[1];
            } elseif ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }

        if ($timestamp === null || !ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }

        $expected = self::sign($secret, $body, (int) $timestamp);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, strtolower($signature))) {
                return true;
            }
        }

        return false;
    }

    public static function sign(string $secret, string $body, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public static function header(string $secret, string $body, int $timestamp): string
    {
        return 't=' . $timestamp . ',v1=' . self::sign($secret, $body, $timestamp);
    }
}
