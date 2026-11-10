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
use SkladX\PrestaShop\Queue\Backoff;

final class BackoffTest extends TestCase
{
    public function testOrderExportRetriesAfter151560180And360Minutes(): void
    {
        $delays = array_map(static fn (int $attempt): ?int => Backoff::delay($attempt, Backoff::ORDER_EXPORT), range(1, 7));

        $this->assertSame([60, 300, 900, 3600, 10800, 21600, null], $delays);
        $this->assertSame(7, Backoff::maxAttempts(Backoff::ORDER_EXPORT));
    }

    public function testDefaultScheduleGivesUpAfterThreeRetries(): void
    {
        $this->assertSame(300, Backoff::delay(1));
        $this->assertSame(3600, Backoff::delay(3));
        $this->assertNull(Backoff::delay(4));
        $this->assertSame(0, Backoff::delay(0));
    }
}
