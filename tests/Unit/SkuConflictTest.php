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
use SkladX\PrestaShop\Api\ApiException;
use SkladX\PrestaShop\Sync\ProductSyncPolicy;
use SkladX\PrestaShop\Sync\SkuConflict;

final class SkuConflictTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function details(bool $withProduct = true): array
    {
        return [
            'sku' => 'KOL-300',
            'external_id' => 'ps:1:12:0',
            'conflicting_product' => ['id' => 'prd_01HX', 'sku' => 'KOL-300', 'name' => 'Kolagén 300 g', 'external_id' => 'ps:1:99:0'],
            'product' => $withProduct ? ['id' => 'prd_01AB', 'sku' => 'KOL-250', 'name' => 'Kolagén'] : null,
        ];
    }

    private function exception(int $status = 409, string $code = 'sku_conflict'): ApiException
    {
        return new ApiException('SKU KOL-300 už patrí inému produktu.', $status, $code, $this->details());
    }

    public function testSkuConflictIsNotRetryable(): void
    {
        $exception = $this->exception();

        $this->assertTrue($exception->isSkuConflict());
        $this->assertFalse($exception->isRetryable());
        $this->assertSame(ProductSyncPolicy::CONFLICT, ProductSyncPolicy::classify($exception));
    }

    public function testOtherErrorsAreClassified(): void
    {
        $this->assertSame(ProductSyncPolicy::RETRY, ProductSyncPolicy::classify(new ApiException('timeout')));
        $this->assertSame(ProductSyncPolicy::RETRY, ProductSyncPolicy::classify(new ApiException('busy', 429, 'rate_limited')));
        $this->assertSame(ProductSyncPolicy::RETRY, ProductSyncPolicy::classify(new ApiException('down', 503, 'http_503')));
        $this->assertSame(ProductSyncPolicy::FAILED, ProductSyncPolicy::classify(new ApiException('invalid', 422, 'validation_failed')));
        $this->assertSame(ProductSyncPolicy::FAILED, ProductSyncPolicy::classify($this->exception(409, 'conflict')));
    }

    public function testRenameConflictNamesBothProducts(): void
    {
        $message = SkuConflict::fromException($this->exception())->message();

        $this->assertStringContainsString('Cannot change SKU KOL-250 to KOL-300 in SkladX', $message);
        $this->assertStringContainsString('"Kolagén 300 g" (prd_01HX)', $message);
        $this->assertStringContainsString('shop product ps:1:99:0', $message);
        $this->assertStringContainsString('Sync to SkladX now', $message);
    }

    public function testNewProductConflict(): void
    {
        $conflict = SkuConflict::fromDetails($this->details(false));

        $this->assertNull($conflict->product);
        $this->assertSame('prd_01HX', $conflict->owner['id']);
        $this->assertStringStartsWith('SKU KOL-300 already belongs to another SkladX product "Kolagén 300 g" (prd_01HX).', $conflict->message());
    }

    public function testFallsBackToTheServerMessageWithoutDetails(): void
    {
        $conflict = SkuConflict::fromDetails([], 'SKU X už patrí inému produktu.');

        $this->assertStringStartsWith('SKU X už patrí inému produktu.', $conflict->message());
        $this->assertNull(SkuConflict::fromException(new ApiException('invalid', 422, 'validation_failed')));
    }

    public function testConflictingPayloadIsNotResentUntilItChangesOrIsForced(): void
    {
        $conflict = ['sync_hash' => 'abc', 'sync_error' => 'SKU conflict', 'sync_error_code' => 'sku_conflict'];
        $failed = ['sync_hash' => '', 'sync_error' => '[422 validation_failed] …', 'sync_error_code' => 'validation_failed'];
        $synced = ['sync_hash' => 'abc', 'sync_error' => '', 'sync_error_code' => ''];

        $this->assertFalse(ProductSyncPolicy::shouldSend('abc', $conflict, false));
        $this->assertTrue(ProductSyncPolicy::shouldSend('def', $conflict, false));
        $this->assertTrue(ProductSyncPolicy::shouldSend('abc', $conflict, true));
        $this->assertTrue(ProductSyncPolicy::shouldSend('abc', $failed, false));
        $this->assertFalse(ProductSyncPolicy::shouldSend('abc', $synced, false));
        $this->assertTrue(ProductSyncPolicy::shouldSend('def', $synced, false));
    }
}
