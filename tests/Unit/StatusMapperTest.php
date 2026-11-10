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
use SkladX\PrestaShop\Sync\StatusMapper;

final class StatusMapperTest extends TestCase
{
    private function mapper(): StatusMapper
    {
        return new StatusMapper(['handed_over' => 4, 'delivered' => '5', 'cancelled' => 6, 'picking' => 0]);
    }

    public function testMapsToPrestaShopStates(): void
    {
        $this->assertSame(4, $this->mapper()->toPrestaShop('handed_over'));
        $this->assertSame(5, $this->mapper()->toPrestaShop('delivered'));
        $this->assertNull($this->mapper()->toPrestaShop('picking'));
        $this->assertNull($this->mapper()->toPrestaShop('packed'));
    }

    public function testNeedsChangeOnlyForANewMappedState(): void
    {
        $this->assertTrue($this->mapper()->needsChange('handed_over', 2, [13, 2]));
        $this->assertFalse($this->mapper()->needsChange('handed_over', 4, [2, 4]), 'Already in the state.');
        $this->assertFalse($this->mapper()->needsChange('handed_over', 3, [2, 4, 3]), 'A state the order already had is not applied again.');
        $this->assertFalse($this->mapper()->needsChange('packed', 2), 'Unmapped status.');
    }

    public function testCancellableAndKnownStatuses(): void
    {
        $this->assertTrue(StatusMapper::isCancellable('ready'));
        $this->assertFalse(StatusMapper::isCancellable('picking'));
        $this->assertTrue(StatusMapper::isKnown('out_for_delivery'));
        $this->assertFalse(StatusMapper::isKnown('lost'));
    }

    public function testSanitizesSubmittedMaps(): void
    {
        $this->assertSame(
            ['handed_over' => 4, 'delivered' => 5],
            StatusMapper::sanitizeMap(['handed_over' => '4', 'delivered' => 5, 'cancelled' => 99, 'unknown' => 4, 'picking' => '0'], [2, 4, 5, 6])
        );
    }
}
