<?php

namespace SkladX\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\PrestaShop\Api\ApiException;
use SkladX\PrestaShop\Pickup\PickupPoint;
use SkladX\PrestaShop\Pickup\PickupPointService;
use SkladX\PrestaShop\Support\Cache;

final class PickupPointServiceTest extends TestCase
{
    private ArrayCache $cache;

    /** @var list<array<string, scalar>> */
    private array $searches = [];

    /** @var list<string> */
    private array $lookups = [];

    /** @var \Throwable|null */
    private $search_error;

    /** @var \Throwable|null */
    private $lookup_error;

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
    }

    private function service(): PickupPointService
    {
        return new PickupPointService(
            function (array $query): array {
                $this->searches[] = $query;

                if ($this->search_error) {
                    throw $this->search_error;
                }

                return [
                    'data' => [
                        PickupPointTest::apiPoint(),
                        PickupPointTest::apiPoint(['id' => 'packeta:2', 'external_id' => '2', 'is_active' => false]),
                        ['name' => 'broken, no id'],
                    ],
                    'meta' => ['next_cursor' => 'abc', 'has_more' => true],
                ];
            },
            function (string $carrier, string $id): array {
                $this->lookups[] = $carrier . ':' . $id;

                if ($this->lookup_error) {
                    throw $this->lookup_error;
                }

                return PickupPointTest::apiPoint(['id' => $carrier . ':' . $id, 'external_id' => $id]);
            },
            $this->cache
        );
    }

    public function testSearchReturnsActiveNormalizedPointsAndCachesPerQuery(): void
    {
        $query = ['carrier' => 'packeta', 'country' => 'SK', 'limit' => 20, 'q' => 'Bratislava'];
        $result = $this->service()->search($query);

        $this->assertCount(1, $result['points']);
        $this->assertSame('18579', $result['points'][0]['id']);
        $this->assertSame('abc', $result['next_cursor']);
        $this->assertTrue($result['has_more']);

        $this->assertSame($result, $this->service()->search($query));
        $this->assertCount(1, $this->searches, 'Second identical search is served from the cache.');

        $this->service()->search(array_merge($query, ['q' => 'Nitra']));
        $this->assertCount(2, $this->searches);
        $this->assertSame(PickupPointService::SEARCH_TTL, $this->cache->ttl[PickupPoint::cacheKey($query)]);
    }

    public function testPointsFromASearchAreFoundWithoutAnApiCall(): void
    {
        $this->service()->search(['carrier' => 'packeta', 'country' => 'SK', 'limit' => 20]);

        $point = $this->service()->find('packeta', 'packeta:18579');

        $this->assertSame('Z-BOX Bratislava, Obchodná 12', $point['name']);
        $this->assertSame([], $this->lookups);
    }

    public function testFindAsksSkladxOnceAndReturnsNullForUnknownPoints(): void
    {
        $this->assertSame('55', $this->service()->find('packeta', '55')['id']);
        $this->service()->find('packeta', '55');
        $this->assertSame(['packeta:55'], $this->lookups);

        $this->service()->find('packeta', '55', true);
        $this->assertCount(2, $this->lookups, 'A fresh lookup skips the cache.');

        $this->lookup_error = new ApiException('Not found', 404, 'not_found');
        $this->assertNull($this->service()->find('packeta', '404404'));
    }

    public function testNetworkErrorsAreNotHidden(): void
    {
        $this->lookup_error = new ApiException('timeout');

        $this->expectException(ApiException::class);
        $this->service()->find('packeta', '77');
    }

    public function testFailedSearchIsNotCached(): void
    {
        $this->search_error = new ApiException('Server error', 500, 'server_error');

        try {
            $this->service()->search(['carrier' => 'packeta']);
            $this->fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            $this->assertSame(500, $exception->status);
        }

        $this->search_error = null;
        $this->assertCount(1, $this->service()->search(['carrier' => 'packeta'])['points']);
        $this->assertCount(2, $this->searches);
    }
}

/**
 * In-memory cache for the tests.
 */
final class ArrayCache implements Cache
{
    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, int> */
    public array $ttl = [];

    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, $value, int $ttl): void
    {
        $this->data[$key] = $value;
        $this->ttl[$key] = $ttl;
    }
}
