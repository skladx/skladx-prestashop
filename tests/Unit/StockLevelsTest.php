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
use SkladX\PrestaShop\Webhooks\StockLevels;

final class StockLevelsTest extends TestCase
{
    public function testReadsTheStockChangedPayloadOfSkladx(): void
    {
        $this->assertSame(['PS-TRI-M' => 17], StockLevels::fromEvent(['product' => ['sku' => 'PS-TRI-M', 'name' => 'Tričko'], 'stock' => ['on_hand' => 20, 'reserved' => 3, 'available' => 17]]));
    }

    public function testReadsOtherShapes(): void
    {
        $this->assertSame(['A' => 5], StockLevels::fromEvent(['product' => ['sku' => 'A', 'stock' => ['available' => 5]]]));
        $this->assertSame(['B' => 2], StockLevels::fromEvent(['sku' => 'B', 'available' => '2']));
        $this->assertSame(['C' => 1], StockLevels::fromEvent(['stock' => ['sku' => 'C', 'available' => 1]]));
        $this->assertSame(['D' => 0, 'E' => 9], StockLevels::fromEvent(['stock' => [['sku' => 'D', 'available' => 0], ['sku' => 'E', 'available' => 9], ['sku' => '', 'available' => 1], ['sku' => 'F']]]));
        $this->assertSame([], StockLevels::fromEvent(['order' => ['id' => 'ord_1']]));
    }

    public function testShopQuantitySubtractsHeldUnitsAndNeverGoesNegative(): void
    {
        $this->assertSame(15, StockLevels::shopQuantity(17, 2));
        $this->assertSame(0, StockLevels::shopQuantity(1, 3));
        $this->assertSame(4, StockLevels::shopQuantity(4, -1));
    }

    public function testStaleEventsAreDetectedByTheTimeOrderedId(): void
    {
        $this->assertTrue(StockLevels::isStale('evt_01m73jg000', 'evt_01m73jh000'));
        $this->assertFalse(StockLevels::isStale('evt_01m73jh000', 'evt_01m73jg000'));
        $this->assertFalse(StockLevels::isStale('', 'evt_01m73jh000'));
        $this->assertFalse(StockLevels::isStale('evt_01m73jh000', ''));
    }
}
