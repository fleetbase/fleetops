<?php

namespace Fleetbase\FleetOps\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Service class to manage caching for LiveController endpoints.
 */
class LiveCacheService
{
    /**
     * Default cache TTL in seconds (30 seconds).
     */
    const DEFAULT_TTL = 30;

    /**
     * Generate a cache key for a specific endpoint and parameters.
     * Includes version number for automatic invalidation.
     *
     * @param string $endpoint The endpoint name (e.g., 'orders', 'drivers')
     * @param array  $params   Request parameters to include in the key
     *
     * @return string The generated cache key with version
     */
    public static function getCacheKey(string $endpoint, array $params = []): string
    {
        $company = session('company');
        $version = static::getVersion($endpoint);
        $paramsHash = md5(json_encode($params));

        return "live:{$company}:{$endpoint}:v{$version}:{$paramsHash}";
    }

    /**
     * Get the current version number for an endpoint.
     *
     * @param string $endpoint The endpoint name
     *
     * @return int The current version number
     */
    public static function getVersion(string $endpoint): int
    {
        $company = session('company');
        $versionKey = "live:{$company}:{$endpoint}:version";

        return (int) Cache::get($versionKey, 0);
    }

    /**
     * Increment the version number for an endpoint to invalidate all caches.
     *
     * @param string $endpoint The endpoint name
     *
     * @return int The new version number
     */
    public static function incrementVersion(string $endpoint): int
    {
        $company = session('company');
        $versionKey = "live:{$company}:{$endpoint}:version";

        return Cache::increment($versionKey);
    }

    /**
     * Get cache tags for the current company.
     *
     * @return array Array of cache tags
     */
    public static function getTags(): array
    {
        $company = session('company');

        return ["live:{$company}"];
    }

    /**
     * Get cache tags for a specific endpoint.
     *
     * @param string $endpoint The endpoint name
     *
     * @return array Array of cache tags including company and endpoint
     */
    public static function getEndpointTags(string $endpoint): array
    {
        $company = session('company');

        return ["live:{$company}", "live:{$company}:{$endpoint}"];
    }

    /**
     * Invalidate cache for a specific endpoint or all live endpoints.
     * Uses version increment for cache driver compatibility.
     *
     * @param string|null $endpoint The endpoint to invalidate, or null for all
     */
    public static function invalidate(?string $endpoint = null): void
    {
        if ($endpoint) {
            // Increment version to invalidate all caches for this endpoint
            static::incrementVersion($endpoint);
            
            // Also flush tags if supported (Redis/Memcached)
            try {
                Cache::tags(static::getEndpointTags($endpoint))->flush();
            } catch (\Exception $e) {
                // Tags not supported, version increment is sufficient
            }
        } else {
            // Invalidate all endpoints
            $endpoints = ['orders', 'routes', 'coordinates', 'drivers', 'vehicles', 'places'];
            foreach ($endpoints as $ep) {
                static::incrementVersion($ep);
            }
            
            // Also flush tags if supported
            try {
                Cache::tags(static::getTags())->flush();
            } catch (\Exception $e) {
                // Tags not supported, version increment is sufficient
            }
        }
    }

    /**
     * Invalidate multiple endpoints at once.
     *
     * @param array $endpoints Array of endpoint names to invalidate
     */
    public static function invalidateMultiple(array $endpoints): void
    {
        // Increment versions for all endpoints
        foreach ($endpoints as $endpoint) {
            static::incrementVersion($endpoint);
        }
        
        // Also flush tags if supported
        try {
            foreach ($endpoints as $endpoint) {
                Cache::tags(static::getEndpointTags($endpoint))->flush();
            }
        } catch (\Exception $e) {
            // Tags not supported, version increment is sufficient
        }
    }

    /**
     * Remember a value in cache with tags.
     *
     * @param string   $endpoint The endpoint name
     * @param array    $params   Request parameters
     * @param \Closure $callback The callback to execute if cache miss
     * @param int|null $ttl      Time to live in seconds (default: 30)
     *
     * @return mixed The cached or freshly computed value
     */
    public static function remember(string $endpoint, array $params, \Closure $callback, ?int $ttl = null)
    {
        $cacheKey = static::getCacheKey($endpoint, $params);
        $tags = static::getEndpointTags($endpoint);
        $ttl = $ttl ?? static::DEFAULT_TTL;

        return Cache::tags($tags)->remember($cacheKey, $ttl, $callback);
    }
}
