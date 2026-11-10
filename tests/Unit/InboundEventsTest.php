<?php

namespace SkladX\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\PrestaShop\Inbound\InboundEvents;
use SkladX\PrestaShop\Inbound\ReceiptStore;

final class InboundEventsTest extends TestCase
{
    private ReceiptStore $store;

    /** @var list<array{string, string|null}> Notified (status, previous status). */
    private array $notified = [];

    protected function setUp(): void
    {
        $this->store = new class implements ReceiptStore {
            /** @var array<string, array<string, mixed>> */
            public array $rows = [];

            public function get(string $id): ?array
            {
                return $this->rows[$id] ?? null;
            }

            public function put(string $id, array $summary): void
            {
                $this->rows[$id] = $summary;
            }

            public function all(): array
            {
                return $this->rows;
            }
        };
        $this->notified = [];
    }

    private function events(): InboundEvents
    {
        return new InboundEvents(
            $this->store,
            function (array $receipt, ?array $previous): void {
                $this->notified[] = [$receipt['status'], $previous['status'] ?? null];
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function receipt(string $status, string $updated_at, ?int $received = null): array
    {
        return [
            'id' => 'inr_01',
            'number' => 'PR-7',
            'name' => 'Jeseň',
            'status' => $status,
            'items' => [['sku' => 'A', 'quantity_expected' => 10, 'quantity_received' => $received]],
            'updated_at' => $updated_at,
        ];
    }

    public function testWebhookUpdatesTheCacheAndNotifiesOncePerStatus(): void
    {
        $events = $this->events();

        $this->assertSame('processed', $events->handleEvent('inbound.announced', ['receipt' => $this->receipt('announced', '2026-11-10T10:00:00Z')]));
        $this->assertSame('ignored', $events->handleEvent('inbound.announced', ['receipt' => $this->receipt('announced', '2026-11-10T10:00:00Z')]));
        $this->assertSame('processed', $events->handleEvent('inbound.received', ['receipt' => $this->receipt('received', '2026-11-12T08:00:00Z', 8)]));

        $this->assertSame([['announced', null], ['received', 'announced']], $this->notified);

        $cached = $this->store->get('inr_01');

        $this->assertSame('received', $cached['status']);
        $this->assertSame(8, $cached['received']);
        $this->assertTrue($cached['has_differences']);
    }

    public function testStaleEventsAreIgnored(): void
    {
        $events = $this->events();

        $events->handleEvent('inbound.received', ['receipt' => $this->receipt('received', '2026-11-12T08:00:00Z', 10)]);
        $result = $events->handleEvent('inbound.arrived', ['receipt' => $this->receipt('arrived', '2026-11-11T08:00:00Z')]);

        $this->assertSame('ignored', $result);
        $this->assertSame('received', $this->store->get('inr_01')['status']);
        $this->assertCount(1, $this->notified);
    }

    public function testRefreshDoesNotAnnounceUnknownReceiptsButNoticesChanges(): void
    {
        $events = $this->events();

        $this->assertSame('processed', $events->apply(\SkladX\PrestaShop\Inbound\Receipt::normalize($this->receipt('announced', '2026-11-10T10:00:00Z'))));
        $this->assertSame([], $this->notified);

        $events->apply(\SkladX\PrestaShop\Inbound\Receipt::normalize($this->receipt('receiving', '2026-11-11T10:00:00Z')));

        $this->assertSame([['receiving', 'announced']], $this->notified);
    }

    public function testAdminChangesAreCachedWithoutNotice(): void
    {
        $events = $this->events();

        $events->apply(\SkladX\PrestaShop\Inbound\Receipt::normalize($this->receipt('draft', '2026-11-10T10:00:00Z')), InboundEvents::SOURCE_ADMIN);
        $events->apply(\SkladX\PrestaShop\Inbound\Receipt::normalize($this->receipt('announced', '2026-11-10T11:00:00Z')), InboundEvents::SOURCE_ADMIN);

        $this->assertSame('announced', $this->store->get('inr_01')['status']);
        $this->assertSame([], $this->notified);

        $events->handleEvent('inbound.announced', ['receipt' => $this->receipt('announced', '2026-11-10T11:00:00Z')]);

        $this->assertSame([], $this->notified);
    }

    public function testIgnoresOtherAndMalformedEvents(): void
    {
        $events = $this->events();

        $this->assertSame('ignored', $events->handleEvent('order.shipped', ['receipt' => $this->receipt('announced', '')]));
        $this->assertSame('ignored', $events->handleEvent('inbound.announced', ['receipt' => ['number' => 'x']]));
        $this->assertSame('ignored', $events->handleEvent('inbound.announced', []));
        $this->assertSame([], $this->store->all());
        $this->assertTrue(InboundEvents::handles('inbound.cancelled'));
        $this->assertFalse(InboundEvents::handles('order.cancelled'));
    }
}
