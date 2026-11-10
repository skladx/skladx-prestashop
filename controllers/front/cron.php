<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Queue\Runner;

/**
 * `GET /module/skladx/cron?token=…` — processes the SkladX queue and the
 * periodic tasks (event polling, receipt refresh, cleanup). Call it every
 * minute from the server cron, e.g.
 * `* * * * * curl -fsS "https://shop/module/skladx/cron?token=…" >/dev/null`.
 */
class SkladxCronModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    /** @var bool */
    protected $maintenance = false;

    /**
     * The cron runs while the shop is in maintenance mode too.
     */
    protected function displayMaintenancePage(): void
    {
    }

    protected function displayRestrictedCountryPage(): void
    {
    }

    public function postProcess(): void
    {
        $token = (string) Tools::getValue('token');

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');

        if (!hash_equals(Settings::cronToken(), $token)) {
            http_response_code(403);
            echo json_encode(['error' => 'invalid_token']);
            exit;
        }

        ignore_user_abort(true);
        @set_time_limit(120);
        Settings::set(Settings::LAST_CRON, time());

        $result = Runner::run(100, 50.0);

        echo json_encode(['ok' => true] + $result);
        exit;
    }
}
