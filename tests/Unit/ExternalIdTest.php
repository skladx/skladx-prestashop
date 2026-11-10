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
use SkladX\PrestaShop\Sync\ExternalId;

final class ExternalIdTest extends TestCase
{
    public function testProductWithoutCombinationsUsesZeroAsTheCombination(): void
    {
        $this->assertSame('ps:1:42:0', ExternalId::forUnit(1, 42));
        $this->assertSame('ps:1:42:0', ExternalId::forUnit(1, 42, 0));
    }

    public function testEveryCombinationHasItsOwnId(): void
    {
        $this->assertSame('ps:2:42:117', ExternalId::forUnit(2, 42, 117));
        $this->assertNotSame(ExternalId::forUnit(1, 42, 117), ExternalId::forUnit(1, 42, 118));
    }

    public function testInvalidShopFallsBackToOne(): void
    {
        $this->assertSame('ps:1:5:0', ExternalId::forUnit(0, 5));
    }

    public function testParse(): void
    {
        $this->assertSame(['id_shop' => 3, 'id_product' => 42, 'id_product_attribute' => 117], ExternalId::parse('ps:3:42:117'));
        $this->assertNull(ExternalId::parse('woo:42'));
        $this->assertNull(ExternalId::parse('ps:1:42'));
    }
}
