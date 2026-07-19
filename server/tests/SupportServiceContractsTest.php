<?php

use Fleetbase\FleetOps\Scopes\DriverScope;
use Fleetbase\FleetOps\Support\LiveCacheService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

test('live cache service builds company scoped keys and tags', function () {
    session(['company' => 'company-1']);

    Cache::shouldReceive('get')
        ->once()
        ->with('live:company-1:orders:version', 0)
        ->andReturn(3);

    $params = ['active' => true, 'bounds' => [1, 2, 3, 4]];

    expect(LiveCacheService::getCacheKey('orders', $params))
        ->toBe('live:company-1:orders:v3:' . md5(json_encode($params)))
        ->and(LiveCacheService::getTags())->toBe(['live:company-1'])
        ->and(LiveCacheService::getEndpointTags('orders'))->toBe([
            'live:company-1',
            'live:company-1:orders',
        ]);
});

test('live cache service increments versions and remembers tagged values', function () {
    session(['company' => 'company-1']);

    $taggedCache = Mockery::mock();
    $taggedCache->shouldReceive('remember')
        ->once()
        ->with('live:company-1:drivers:v2:' . md5(json_encode(['limit' => 25])), 45, Mockery::type(Closure::class))
        ->andReturnUsing(fn ($key, $ttl, Closure $callback) => $callback());

    Cache::shouldReceive('increment')
        ->once()
        ->with('live:company-1:drivers:version')
        ->andReturn(2);
    Cache::shouldReceive('get')
        ->once()
        ->with('live:company-1:drivers:version', 0)
        ->andReturn(2);
    Cache::shouldReceive('tags')
        ->once()
        ->with(['live:company-1', 'live:company-1:drivers'])
        ->andReturn($taggedCache);

    expect(LiveCacheService::incrementVersion('drivers'))->toBe(2)
        ->and(LiveCacheService::remember('drivers', ['limit' => 25], fn () => ['fresh' => true], 45))->toBe([
            'fresh' => true,
        ]);
});

test('live cache service invalidates endpoints with version bumps and optional tag flushes', function () {
    session(['company' => 'company-1']);

    $taggedCache = Mockery::mock();
    $taggedCache->shouldReceive('flush')->once();

    Cache::shouldReceive('increment')
        ->once()
        ->with('live:company-1:vehicles:version')
        ->andReturn(4);
    Cache::shouldReceive('tags')
        ->once()
        ->with(['live:company-1', 'live:company-1:vehicles'])
        ->andReturn($taggedCache);

    LiveCacheService::invalidate('vehicles');
});

test('live cache service invalidates all endpoints and ignores unsupported tag flushes', function () {
    session(['company' => 'company-1']);

    $endpoints = ['orders', 'routes', 'coordinates', 'drivers', 'vehicles', 'places', 'operations-monitor'];

    foreach ($endpoints as $index => $endpoint) {
        Cache::shouldReceive('increment')
            ->once()
            ->with("live:company-1:{$endpoint}:version")
            ->andReturn($index + 1);
    }

    Cache::shouldReceive('tags')
        ->once()
        ->with(['live:company-1'])
        ->andThrow(new RuntimeException('tagged cache is unavailable'));

    LiveCacheService::invalidate();
});

test('live cache service invalidates multiple endpoints and ignores tag flush failures', function () {
    session(['company' => 'company-1']);

    foreach (['orders', 'drivers'] as $index => $endpoint) {
        Cache::shouldReceive('increment')
            ->once()
            ->with("live:company-1:{$endpoint}:version")
            ->andReturn($index + 1);
    }

    Cache::shouldReceive('tags')
        ->once()
        ->with(['live:company-1', 'live:company-1:orders'])
        ->andThrow(new RuntimeException('tagged cache is unavailable'));

    LiveCacheService::invalidateMultiple(['orders', 'drivers']);
});

test('driver scope requires an active user relationship', function () {
    $builder = Mockery::mock(Builder::class);
    $model   = Mockery::mock(Model::class);

    $builder->shouldReceive('whereHas')
        ->once()
        ->with('user')
        ->andReturnSelf();

    expect((new DriverScope())->apply($builder, $model))->toBeNull();
});
