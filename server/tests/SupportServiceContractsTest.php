<?php

use Fleetbase\FleetOps\Scopes\DriverScope;
use Fleetbase\FleetOps\Support\LiveCacheService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class FleetOpsTaggedCacheFake
{
    public array $calls = [];

    public function __construct(private mixed $rememberValue = null, private bool $throwOnFlush = false)
    {
    }

    public function remember($key, $ttl, Closure $callback)
    {
        $this->calls[] = ['remember', $key, $ttl];

        return $this->rememberValue ?? $callback();
    }

    public function flush()
    {
        $this->calls[] = ['flush'];

        if ($this->throwOnFlush) {
            throw new RuntimeException('tagged cache is unavailable');
        }
    }
}

class FleetOpsLiveCacheFake
{
    public array $gets = [];
    public array $increments = [];
    public array $tags = [];
    public ?FleetOpsTaggedCacheFake $taggedCache = null;
    public bool $throwOnTags = false;
    private array $versions = [
        'live:company-1:orders:version'  => 3,
        'live:company-1:drivers:version' => 1,
    ];

    public function get($key, $default = null)
    {
        $this->gets[] = [$key, $default];

        return $this->versions[$key] ?? $default;
    }

    public function increment($key)
    {
        $this->increments[] = $key;

        $this->versions[$key] = ($this->versions[$key] ?? 0) + 1;

        return $this->versions[$key];
    }

    public function tags(array $tags)
    {
        $this->tags[] = $tags;

        if ($this->throwOnTags) {
            throw new RuntimeException('tagged cache is unavailable');
        }

        return $this->taggedCache ??= new FleetOpsTaggedCacheFake();
    }
}

class FleetOpsDriverScopeBuilderFake extends Builder
{
    public array $whereHas = [];

    public function __construct()
    {
    }

    public function whereHas($relation, ?Closure $callback = null, $operator = '>=', $count = 1)
    {
        $this->whereHas[] = [$relation, $callback, $operator, $count];

        return $this;
    }
}

test('live cache service builds company scoped keys and tags', function () {
    session(['company' => 'company-1']);

    $cache = new FleetOpsLiveCacheFake();
    Cache::swap($cache);

    $params = ['active' => true, 'bounds' => [1, 2, 3, 4]];

    expect(LiveCacheService::getCacheKey('orders', $params))
        ->toBe('live:company-1:orders:v3:' . md5(json_encode($params)))
        ->and(LiveCacheService::getTags())->toBe(['live:company-1'])
        ->and(LiveCacheService::getEndpointTags('orders'))->toBe([
            'live:company-1',
            'live:company-1:orders',
        ])
        ->and($cache->gets)->toBe([['live:company-1:orders:version', 0]]);
});

test('live cache service increments versions and remembers tagged values', function () {
    session(['company' => 'company-1']);

    $cache              = new FleetOpsLiveCacheFake();
    $cache->taggedCache = new FleetOpsTaggedCacheFake();
    Cache::swap($cache);

    expect(LiveCacheService::incrementVersion('drivers'))->toBe(2)
        ->and(LiveCacheService::remember('drivers', ['limit' => 25], fn () => ['fresh' => true], 45))->toBe([
            'fresh' => true,
        ])
        ->and($cache->increments)->toBe(['live:company-1:drivers:version'])
        ->and($cache->tags)->toBe([['live:company-1', 'live:company-1:drivers']])
        ->and($cache->taggedCache->calls)->toBe([
            ['remember', 'live:company-1:drivers:v2:' . md5(json_encode(['limit' => 25])), 45],
        ]);
});

test('live cache service invalidates endpoints with version bumps and optional tag flushes', function () {
    session(['company' => 'company-1']);

    $cache              = new FleetOpsLiveCacheFake();
    $cache->taggedCache = new FleetOpsTaggedCacheFake();
    Cache::swap($cache);

    LiveCacheService::invalidate('vehicles');

    expect($cache->increments)->toBe(['live:company-1:vehicles:version'])
        ->and($cache->tags)->toBe([['live:company-1', 'live:company-1:vehicles']])
        ->and($cache->taggedCache->calls)->toBe([['flush']]);
});

test('live cache service invalidates all endpoints and ignores unsupported tag flushes', function () {
    session(['company' => 'company-1']);

    $cache              = new FleetOpsLiveCacheFake();
    $cache->throwOnTags = true;
    Cache::swap($cache);

    LiveCacheService::invalidate();

    expect($cache->increments)->toBe([
        'live:company-1:orders:version',
        'live:company-1:routes:version',
        'live:company-1:coordinates:version',
        'live:company-1:drivers:version',
        'live:company-1:vehicles:version',
        'live:company-1:places:version',
        'live:company-1:operations-monitor:version',
    ])->and($cache->tags)->toBe([['live:company-1']]);
});

test('live cache service invalidates multiple endpoints and ignores tag flush failures', function () {
    session(['company' => 'company-1']);

    $cache              = new FleetOpsLiveCacheFake();
    $cache->throwOnTags = true;
    Cache::swap($cache);

    LiveCacheService::invalidateMultiple(['orders', 'drivers']);

    expect($cache->increments)->toBe([
        'live:company-1:orders:version',
        'live:company-1:drivers:version',
    ])->and($cache->tags)->toBe([['live:company-1', 'live:company-1:orders']]);
});

test('driver scope requires an active user relationship', function () {
    $builder = new FleetOpsDriverScopeBuilderFake();
    $model   = new class extends Model {
    };

    expect((new DriverScope())->apply($builder, $model))->toBeNull()
        ->and($builder->whereHas)->toHaveCount(1)
        ->and($builder->whereHas[0][0])->toBe('user');
});
