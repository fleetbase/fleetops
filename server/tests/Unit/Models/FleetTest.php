<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('session')) {
    function session($key = null, $default = null)
    {
        static $values = [];

        if (is_array($key)) {
            $values = array_merge($values, $key);

            return null;
        }

        return $values[$key] ?? $default;
    }
}

use Fleetbase\FleetOps\Models\Fleet;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\SQLiteConnection;
use Spatie\Activitylog\LogOptions;
use Spatie\Sluggable\SlugOptions;

class FleetOpsFleetUnitCountingRelationFake
{
    public array $wheres = [];

    public function __construct(private int $count)
    {
    }

    public function where(string $column, mixed $value): self
    {
        $this->wheres[] = [$column, $value];

        return $this;
    }

    public function count(): int
    {
        return $this->count;
    }
}

class FleetOpsFleetUnitCountingFake extends Fleet
{
    public array $driverRelations  = [];
    public array $vehicleRelations = [];

    public function drivers()
    {
        $relation                = new FleetOpsFleetUnitCountingRelationFake(5);
        $this->driverRelations[] = $relation;

        return $relation;
    }

    public function vehicles()
    {
        $relation                 = new FleetOpsFleetUnitCountingRelationFake(8);
        $this->vehicleRelations[] = $relation;

        return $relation;
    }
}

class FleetOpsFleetUnitSavingFake extends Fleet
{
    public bool $saved = false;

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }
}

function fleetopsFleetUnitUseRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

test('fleet exposes relation contracts activity options and slug options', function () {
    fleetopsFleetUnitUseRelationConnection();

    $fleet = new Fleet();
    $fleet->setRawAttributes(['uuid' => 'fleet-uuid', 'name' => 'Night Fleet'], true);

    expect($fleet->photo())->toBeInstanceOf(BelongsTo::class)
        ->and($fleet->serviceArea())->toBeInstanceOf(BelongsTo::class)
        ->and($fleet->zone())->toBeInstanceOf(BelongsTo::class)
        ->and($fleet->vendor())->toBeInstanceOf(BelongsTo::class)
        ->and($fleet->parentFleet())->toBeInstanceOf(BelongsTo::class)
        ->and($fleet->subFleets())->toBeInstanceOf(HasMany::class)
        ->and($fleet->drivers())->toBeInstanceOf(HasManyThrough::class)
        ->and($fleet->vehicles())->toBeInstanceOf(HasManyThrough::class)
        ->and($fleet->getActivitylogOptions())->toBeInstanceOf(LogOptions::class)
        ->and($fleet->getSlugOptions())->toBeInstanceOf(SlugOptions::class);
});

test('fleet accessors count assigned drivers and vehicles', function () {
    $fleet = new FleetOpsFleetUnitCountingFake();
    $fleet->setRawAttributes(['uuid' => 'fleet-uuid'], true);

    expect($fleet->getPhotoUrlAttribute())->toBe('https://s3.ap-northeast-2.amazonaws.com/fleetbase/public/default-fleet.png')
        ->and($fleet->getDriversCountAttribute())->toBe(5)
        ->and($fleet->getDriversOnlineCountAttribute())->toBe(5)
        ->and($fleet->driverRelations[1]->wheres)->toBe([['online', 1]])
        ->and($fleet->getVehiclesCountAttribute())->toBe(8)
        ->and($fleet->getVehiclesOnlineCountAttribute())->toBe(8)
        ->and($fleet->vehicleRelations[1]->wheres)->toBe([['online', 1]]);
});

test('fleet import resolves supported name columns and optional persistence', function () {
    session(['company' => 'company-fleet-unit']);

    $unsaved = FleetOpsFleetUnitSavingFake::createFromImport([
        'fleet_name' => 'Harbor Fleet',
        'empty'      => null,
    ]);

    $saved = FleetOpsFleetUnitSavingFake::createFromImport([
        'fleet' => 'Airport Fleet',
    ], true);

    expect($unsaved)->toBeInstanceOf(FleetOpsFleetUnitSavingFake::class)
        ->and($unsaved->company_uuid)->toBe('company-fleet-unit')
        ->and($unsaved->name)->toBe('Harbor Fleet')
        ->and($unsaved->status)->toBe('active')
        ->and($unsaved->saved)->toBeFalse()
        ->and($saved->name)->toBe('Airport Fleet')
        ->and($saved->saved)->toBeTrue();
});
