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
     * Run a frequent write (a telemetry save) with its model events, but invalidate
     * the model's cached lists at most once per window instead of on every write.
     *
     * Order and food truck lists embed vehicles, so invalidating them on every
     * telemetry event would keep them from ever being served from cache for any
     * company with streaming devices. Needs ApiModelCache::withoutInvalidating()
     * (fleetbase/core-api#301); with an older core the write invalidates as before.
     *
     * @template T
     *
     * @param \Closure(): T $write
     *
     * @return T
     */
    public static function telemetryWrite(Model $model, \Closure $write, int $seconds = self::LOCATION_WINDOW): mixed
    {
        // @codeCoverageIgnoreStart
        // fleetbase/core-api ^1.6.69, which this package requires, always has it; the
        // branch only serves an install that pinned an older core by hand.
        if (!method_exists(ApiModelCache::class, 'withoutInvalidating')) {
            return $write();
        }
        // @codeCoverageIgnoreEnd

        $result = ApiModelCache::withoutInvalidating($write);
        static::invalidate($model, $model->company_uuid, $seconds);

        return $result;
    }

    /**
     * Invalidate the model's table for the company unless that was done within the window.
     */
    public static function invalidate(Model $model, ?string $companyUuid, int $seconds = self::LOCATION_WINDOW): void
    {
        if (!ApiModelCache::isCachingEnabled()) {
            return;
        }

        $key = 'fleetops:api_cache_throttle:' . $model->getTable() . ':' . ($companyUuid ?? 'all');

        try {
            if (Cache::add($key, true, $seconds)) {
                ApiModelCache::invalidateModelCache($model, $companyUuid);
            }
        } catch (\Throwable $e) {
            // A location ping must never fail on cache bookkeeping; cached lists then
            // show the location until their TTL. Core versions before #301 can throw here.
        }
    }
}
