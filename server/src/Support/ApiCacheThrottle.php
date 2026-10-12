<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\Support\ApiModelCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Invalidates API caches at most once per window for writes too frequent to
 * invalidate on every call.
 *
 * Location pings write drivers and vehicles every few seconds per device, and
 * order lists embed both, so invalidating on each ping would keep those lists
 * from ever being served from cache. Throttled, a ping costs one Cache::add,
 * and cached lists show a location at most a window old.
 */
class ApiCacheThrottle
{
    /**
     * Seconds between invalidations for location writes.
     */
    public const LOCATION_WINDOW = 60;

    /**
     * Invalidate the model's table for the company unless that was done within the window.
     */
    public static function invalidate(Model $model, ?string $companyUuid, int $seconds = self::LOCATION_WINDOW): void
    {
        if (!ApiModelCache::isCachingEnabled()) {
            return;
        }

        $key = 'fleetops:api_cache_throttle:' . $model->getTable() . ':' . ($companyUuid ?? 'all');
        if (Cache::add($key, true, $seconds)) {
            ApiModelCache::invalidateModelCache($model, $companyUuid);
        }
    }
}
