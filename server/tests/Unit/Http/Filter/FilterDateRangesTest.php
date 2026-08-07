<?php

use Fleetbase\Http\Filter\Filter;
use Illuminate\Http\Request;

/**
 * Covers the shared date filter methods across the request filters: a single
 * date narrows with whereDate, while a comma separated pair narrows with
 * whereBetween over the parsed range.
 */
class FleetOpsFilterDateQuery
{
    public array $calls = [];

    public function __call(string $method, array $arguments): self
    {
        $this->calls[] = [$method, $arguments];

        return $this;
    }
}

function fleetopsFilterDateInstance(string $filterClass, FleetOpsFilterDateQuery $builder): object
{
    $filter = (new ReflectionClass($filterClass))->newInstanceWithoutConstructor();

    foreach ([
        'builder' => $builder,
        'session' => new class {
            public function get(string $key): ?string
            {
                return $key === 'company' ? 'company-filter-1' : null;
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

test('date filters narrow by single dates and parsed ranges', function (string $filterClass, string $method, string $column) {
    // A single date narrows to that calendar day
    $singleBuilder = new FleetOpsFilterDateQuery();
    fleetopsFilterDateInstance($filterClass, $singleBuilder)->{$method}('2026-07-29');

    expect($singleBuilder->calls)->toHaveCount(1)
        ->and($singleBuilder->calls[0][0])->toBe('whereDate')
        ->and($singleBuilder->calls[0][1][0])->toBe($column);

    // A comma separated pair narrows to the parsed range
    $rangeBuilder = new FleetOpsFilterDateQuery();
    fleetopsFilterDateInstance($filterClass, $rangeBuilder)->{$method}('2026-07-01,2026-07-29');

    expect($rangeBuilder->calls)->toHaveCount(1)
        ->and($rangeBuilder->calls[0][0])->toBe('whereBetween')
        ->and($rangeBuilder->calls[0][1][0])->toBe($column)
        ->and($rangeBuilder->calls[0][1][1])->toHaveCount(2);
})->with([
    'contact created'       => [Fleetbase\FleetOps\Http\Filter\ContactFilter::class, 'createdAt', 'created_at'],
    'contact updated'       => [Fleetbase\FleetOps\Http\Filter\ContactFilter::class, 'updatedAt', 'updated_at'],
    'fleet created'         => [Fleetbase\FleetOps\Http\Filter\FleetFilter::class, 'createdAt', 'created_at'],
    'fleet updated'         => [Fleetbase\FleetOps\Http\Filter\FleetFilter::class, 'updatedAt', 'updated_at'],
    'vendor created'        => [Fleetbase\FleetOps\Http\Filter\VendorFilter::class, 'createdAt', 'created_at'],
    'vendor updated'        => [Fleetbase\FleetOps\Http\Filter\VendorFilter::class, 'updatedAt', 'updated_at'],
    'device event created'  => [Fleetbase\FleetOps\Http\Filter\DeviceEventFilter::class, 'createdAt', 'created_at'],
    'device event updated'  => [Fleetbase\FleetOps\Http\Filter\DeviceEventFilter::class, 'updatedAt', 'updated_at'],
    'device event occurred' => [Fleetbase\FleetOps\Http\Filter\DeviceEventFilter::class, 'occurredAt', 'occurred_at'],
]);
