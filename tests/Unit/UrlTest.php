<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\PrestaShop\Support\Url;

final class UrlTest extends TestCase
{
    public function testWebhookUrlProblems(): void
    {
        $root = '/var/www/shop/public';

        $this->assertSame('', Url::webhookProblem('https://shop.example/module/skladx/webhook', $root));
        $this->assertSame('not_https', Url::webhookProblem('http://shop.example/module/skladx/webhook', $root));
        $this->assertSame('not_absolute', Url::webhookProblem('/module/skladx/webhook', $root));
        $this->assertSame('filesystem_path', Url::webhookProblem('https://shop.example/var/www/shop/public/module/skladx/webhook', $root));
        $this->assertSame('local_host', Url::webhookProblem('https://prestashop.test/module/skladx/webhook', $root));
        $this->assertSame('local_host', Url::webhookProblem('https://localhost/module/skladx/webhook', $root));
    }

    public function testApiBaseIsNormalised(): void
    {
        $this->assertSame('https://skladx.sk/api/v1', Url::apiBase(' https://skladx.sk/api/v1/ '));
        $this->assertSame('', Url::apiBase('skladx.sk/api/v1'));
        $this->assertSame('', Url::apiBase('ftp://skladx.sk'));
    }
}
