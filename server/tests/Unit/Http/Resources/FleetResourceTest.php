<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\FleetController;
use Fleetbase\FleetOps\Http\Resources\v1\Fleet as FleetResource;
use Fleetbase\FleetOps\Models\Fleet;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the Fleet resource relation preloading: requested with-relations
 * are camelized and loaded, and subfleet driver/vehicle collections are
 * eagerly attached when both are requested.
 */
if (!Request::hasMacro('isArray')) {
    Request::macro('isArray', fn (string $key) => is_array($this->input($key)));
}

if (!Request::hasMacro('array')) {
    Request::macro('array', fn (string $key, $default = []) => (array) $this->input($key, $default));
}

function fleetopsFleetResourceBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $c)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'fleets'              => ['uuid', 'public_id', 'company_uuid', 'parent_fleet_uuid', 'service_area_uuid', 'zone_uuid', 'name', 'task', 'status', 'meta', '_key'],
        'fleet_drivers'       => ['uuid', 'fleet_uuid', 'driver_uuid', '_key'],
        'fleet_vehicles'      => ['uuid', 'fleet_uuid', 'vehicle_uuid', '_key'],
        'drivers'             => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'status', '_key'],
        'vehicles'            => ['uuid', 'public_id', 'company_uuid', 'name', 'status', '_key'],
        'users'               => ['uuid', 'public_id', 'company_uuid', 'name', '_key'],
        'custom_fields'       => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'name', 'label', '_key'],
        'custom_field_values' => ['uuid', 'public_id', 'company_uuid', 'custom_field_uuid', 'subject_uuid', 'subject_type', 'value', 'value_type', '_key'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);
    app()->instance('request', Request::create('/v1/fleets', 'GET'));

    $connection->table('fleets')->insert([
        ['uuid' => 'fleet-parent-1', 'public_id' => 'fleet_parentone', 'company_uuid' => 'company-1', 'parent_fleet_uuid' => null, 'name' => 'Parent Fleet'],
        ['uuid' => 'fleet-sub-1', 'public_id' => 'fleet_subone1', 'company_uuid' => 'company-1', 'parent_fleet_uuid' => 'fleet-parent-1', 'name' => 'Sub Fleet'],
    ]);
    $connection->table('users')->insert(['uuid' => 'user-fleet-1', 'company_uuid' => 'company-1', 'name' => 'Fleet Driver']);
    $connection->table('drivers')->insert(['uuid' => 'driver-fleet-1', 'public_id' => 'driver_fleetone', 'company_uuid' => 'company-1', 'user_uuid' => 'user-fleet-1']);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-fleet-1', 'public_id' => 'vehicle_fleetone', 'company_uuid' => 'company-1', 'name' => 'Fleet Truck']);
    $connection->table('fleet_drivers')->insert(['uuid' => 'fd-1', 'fleet_uuid' => 'fleet-sub-1', 'driver_uuid' => 'driver-fleet-1']);
    $connection->table('fleet_vehicles')->insert(['uuid' => 'fv-1', 'fleet_uuid' => 'fleet-sub-1', 'vehicle_uuid' => 'vehicle-fleet-1']);

    return $connection;
}

/**
 * Resolve expansions the way a request does, then serialize.
 */
function fleetopsFleetResourcePayload(Fleet $fleet, array $query): array
{
    $request    = Request::create('/v1/fleets/fleet_parentone', 'GET', $query);
    $controller = new FleetController();
    $reflection = new ReflectionMethod(FleetController::class, 'applyPublicExpansions');
    $reflection->setAccessible(true);
    $reflection->invoke($controller, $request, FleetController::EXPANDABLE);

    return (new FleetResource($fleet))->resolve($request);
}

test('fleet resource preloads requested subfleet driver and vehicle relations', function () {
    fleetopsFleetResourceBoot();

    $fleet    = Fleet::where('uuid', 'fleet-parent-1')->first();
    $resolved = fleetopsFleetResourcePayload($fleet, ['with' => ['subfleets', 'drivers', 'vehicles']]);

    // `subFleets` is the relation Eloquent actually has. The public name differs
    // from it only in case, and PHP method calls are case-insensitive, so
    // `load('subfleets')` used to succeed and store a *second* copy under the
    // mis-cased key — which `whenLoaded('subFleets')` then could not see.
    expect($resolved['name'])->toBe('Parent Fleet')
        ->and($fleet->relationLoaded('subFleets'))->toBeTrue()
        ->and($resolved)->toHaveKey('subfleets');

    $subFleet = $fleet->getRelation('subFleets')->first();

    // Asking for subfleets alongside drivers and vehicles still nests them, as
    // the released contract did.
    expect($subFleet)->not->toBeNull()
        ->and($subFleet->relationLoaded('drivers'))->toBeTrue()
        ->and($subFleet->relationLoaded('vehicles'))->toBeTrue()
        ->and($subFleet->drivers)->toHaveCount(1)
        ->and($subFleet->vehicles)->toHaveCount(1);
});

test('fleet resource resolves subfleets on its own, which the mis-cased load never did', function () {
    fleetopsFleetResourceBoot();

    $fleet    = Fleet::where('uuid', 'fleet-parent-1')->first();
    $resolved = fleetopsFleetResourcePayload($fleet, ['with' => 'subfleets']);

    // Before the mapping, this returned no `subfleets` key at all: the load
    // landed under `subfleets` and the resource looked for `subFleets`.
    expect($resolved)->toHaveKey('subfleets')
        ->and($fleet->relationLoaded('subFleets'))->toBeTrue();
});

test('fleet resource accepts the explicit nested expansion spelling', function () {
    fleetopsFleetResourceBoot();

    $fleet = Fleet::where('uuid', 'fleet-parent-1')->first();
    fleetopsFleetResourcePayload($fleet, ['with' => ['subfleets.drivers']]);

    $subFleet = $fleet->getRelation('subFleets')->first();

    expect($subFleet->relationLoaded('drivers'))->toBeTrue()
        ->and($subFleet->relationLoaded('vehicles'))->toBeFalse();
});
