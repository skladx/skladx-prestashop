<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Pickup;

use SkladX\PrestaShop\Api\ApiException;
use SkladX\PrestaShop\Support\Cache;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Cached pickup point search and lookup against SkladX. Pure PHP with
 * injected API calls and cache — unit tested.
 *
 * Every search answer is cached per query; every point it contains is also
 * cached on its own, so choosing a point from the list and validating it at
 * checkout normally needs no further API call.
 */
final class PickupPointService
{
    /** Seconds a search answer is cached. */
    public const SEARCH_TTL = 3600;

    /** Seconds a single point is cached. */
    public const POINT_TTL = 21600;

    /** @var callable */
    private $searchApi;

    /** @var callable */
    private $pointApi;

    /**
     * @param callable $searchApi `GET /pickup-points` (query → {data, meta}); throws ApiException
     * @param callable $pointApi `GET /pickup-points/{carrier}/{id}` (→ point); throws ApiException
     */
    public function __construct(callable $searchApi, callable $pointApi, private readonly Cache $cache)
    {
        $this->searchApi = $searchApi;
        $this->pointApi = $pointApi;
    }

    /**
     * @param array<string, scalar> $query sanitised query (PickupPoint::sanitizeQuery())
     *
     * @return array{points: list<array<string, mixed>>, next_cursor: string|null, has_more: bool}
     *
     * @throws ApiException when SkladX cannot be reached or rejects the query
     */
    public function search(array $query): array
    {
        $key = PickupPoint::cacheKey($query);
        $cached = $this->cache->get($key);

        if (is_array($cached) && isset($cached['points'])) {
            return $cached;
        }

        $response = ($this->searchApi)($query);
        $points = [];

        foreach ((array) ($response['data'] ?? []) as $raw) {
            $point = is_array($raw) ? PickupPoint::normalize($raw, (string) ($query['carrier'] ?? '')) : null;

            if ($point !== null && $point['active']) {
                $points[] = $point;
                $this->cache->set(PickupPoint::pointCacheKey($point['carrier'], $point['id']), $point, self::POINT_TTL);
            }
        }

        $next = $response['meta']['next_cursor'] ?? null;
        $result = [
            'points' => $points,
            'next_cursor' => is_string($next) && $next !== '' ? $next : null,
            'has_more' => !empty($response['meta']['has_more']) && is_string($next) && $next !== '',
        ];

        $this->cache->set($key, $result, self::SEARCH_TTL);

        return $result;
    }

    /**
     * One point by carrier and id (plain or `carrier:id`).
     *
     * @param bool $fresh skip the cache (and refresh it)
     *
     * @return array<string, mixed>|null null when SkladX does not know the point
     *
     * @throws ApiException on network errors and unexpected API errors (not 404)
     */
    public function find(string $carrier, string $id, bool $fresh = false): ?array
    {
        $id = str_starts_with($id, $carrier . ':') ? substr($id, strlen($carrier) + 1) : $id;

        if ($carrier === '' || $id === '') {
            return null;
        }

        $key = PickupPoint::pointCacheKey($carrier, $id);

        if (!$fresh) {
            $cached = $this->cache->get($key);

            if (is_array($cached) && isset($cached['id'])) {
                return $cached;
            }
        }

        try {
            $point = PickupPoint::normalize(($this->pointApi)($carrier, $id), $carrier);
        } catch (ApiException $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }

        if ($point !== null) {
            $this->cache->set($key, $point, self::POINT_TTL);
        }

        return $point;
    }
}
