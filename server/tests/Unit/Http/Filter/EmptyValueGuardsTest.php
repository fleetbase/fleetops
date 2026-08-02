<?php

use Fleetbase\FleetOps\Http\Filter\DeviceEventFilter;
use Fleetbase\FleetOps\Http\Filter\DeviceFilter;
use Fleetbase\Http\Filter\Filter;
use Illuminate\Http\Request;

/**
 * Covers the empty-value guards on the multi-value device filters. Both accept a
 * string or an array and normalise it with `Utils::arrayFrom()`; an empty array
 * normalises to nothing to match on, and the filter must leave the query
 * untouched rather than emitting a constraint that excludes every row.
 */
class FleetOpsEmptyGuardQuery
{
    public array $calls = [];

    public function __call(string $method, array $arguments): self
    {
        $this->calls[] = $method;

        return $this;
    }
}

function fleetopsEmptyGuardFilter(string $filterClass, FleetOpsEmptyGuardQuery $builder): object
{
    $filter = (new ReflectionClass($filterClass))->newInstanceWithoutConstructor();

    foreach ([
        'builder' => $builder,
        'session' => new class {
            public function get(string $key): ?string
            {
                return $key === 'company' ? 'company-uuid' : null;
            }
        },
        'request' => new Request(),
    ] as $property => $value) {
        $reflection = new ReflectionProperty(Filter::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($filter, $value);
    }

    return $filter;
}

test('device filters ignore empty multi value input', function () {
    // An empty array normalises to no states, so nothing is applied
    $emptyStatus = new FleetOpsEmptyGuardQuery();
    fleetopsEmptyGuardFilter(DeviceFilter::class, $emptyStatus)->connectionStatus([]);
    expect($emptyStatus->calls)->toBe([]);

    $emptyProcessed = new FleetOpsEmptyGuardQuery();
    fleetopsEmptyGuardFilter(DeviceEventFilter::class, $emptyProcessed)->processed([]);
    expect($emptyProcessed->calls)->toBe([]);

    // A real value still reaches the builder, so the guard is not swallowing work
    $withStatus = new FleetOpsEmptyGuardQuery();
    fleetopsEmptyGuardFilter(DeviceFilter::class, $withStatus)->connectionStatus(['online']);
    expect($withStatus->calls)->not->toBe([]);

    $withProcessed = new FleetOpsEmptyGuardQuery();
    fleetopsEmptyGuardFilter(DeviceEventFilter::class, $withProcessed)->processed(['1']);
    expect($withProcessed->calls)->not->toBe([]);
});
