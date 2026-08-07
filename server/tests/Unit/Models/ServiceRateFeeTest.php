<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Models\ServiceRateFee;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\SQLiteConnection;

function fleetopsServiceRateFeeUnitUseRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

test('service rate fee normalizes imported row values', function () {
    $row = ServiceRateFee::onRowInsert([
        'fee'         => '$12.34',
        'distance'    => '5,500 m',
        'min'         => ' 3 stops ',
        'max'         => '8 stops',
        'priority'    => 'Priority 7',
        'is_fallback' => 'yes',
    ]);

    expect($row['fee'])->toBe(1234)
        ->and($row['distance'])->toBe(5500)
        ->and($row['min'])->toBe(3)
        ->and($row['max'])->toBe(8)
        ->and($row['priority'])->toBe(7)
        ->and($row['is_fallback'])->toBeTrue();

    $defaults = ServiceRateFee::onRowInsert([]);

    expect($defaults['fee'])->toBe(0)
        ->and($defaults['distance'])->toBe(0)
        ->and($defaults['min'])->toBe(0)
        ->and($defaults['max'])->toBe(0)
        ->and($defaults['priority'])->toBe(0)
        ->and($defaults['is_fallback'])->toBeFalse();
});

test('service rate fee exposes relations distance mutator and min max checks', function () {
    fleetopsServiceRateFeeUnitUseRelationConnection();

    $fee = new ServiceRateFee([
        'min'      => 2,
        'max'      => 6,
        'distance' => '7,500 meters',
    ]);

    expect($fee->serviceArea())->toBeInstanceOf(BelongsTo::class)
        ->and($fee->zone())->toBeInstanceOf(BelongsTo::class)
        ->and($fee->getAttributes()['distance'])->toBe(7500)
        ->and($fee->isWithinMinMax(2))->toBeTrue()
        ->and($fee->isWithinMinMax(4))->toBeTrue()
        ->and($fee->isWithinMinMax(6))->toBeTrue()
        ->and($fee->isWithinMinMax(1))->toBeFalse()
        ->and($fee->isWithinMinMax(7))->toBeFalse();
});
