<?php

use Fleetbase\FleetOps\Rules\ResolvableVehicle;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the vehicle resolution rule: identifiers are matched by uuid or by
 * public id depending on their shape, empty values defer to the nullable
 * rule, and unresolvable identifiers fail validation.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

function fleetopsResolvableVehicleBoot(): SQLiteConnection
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

    $connection->getSchemaBuilder()->create('vehicles', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'plate_number'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    $connection->table('vehicles')->insert([
        'uuid'         => '88888888-8888-4888-8888-888888888801',
        'public_id'    => 'vehicle_resolve1',
        'company_uuid' => 'company-rule-1',
        'plate_number' => 'SG-9999',
    ]);

    return $connection;
}

test('vehicle rule resolves identifiers by uuid and public id', function () {
    fleetopsResolvableVehicleBoot();

    // Uuid-shaped identifiers match on the uuid column
    $byUuid = new ResolvableVehicle();
    expect($byUuid->passes('vehicle', '88888888-8888-4888-8888-888888888801'))->toBeTrue();

    // Anything else is treated as a public id
    $byPublicId = new ResolvableVehicle();
    expect($byPublicId->passes('vehicle', 'vehicle_resolve1'))->toBeTrue();

    // Nested payloads surface their identifier
    $fromArray = new ResolvableVehicle();
    expect($fromArray->passes('vehicle', ['id' => 'vehicle_resolve1']))->toBeTrue();

    // Unknown identifiers fail, in both shapes
    $missingUuid = new ResolvableVehicle();
    expect($missingUuid->passes('vehicle', '88888888-8888-4888-8888-888888888899'))->toBeFalse();

    $missingPublicId = new ResolvableVehicle();
    expect($missingPublicId->passes('vehicle', 'vehicle_missing'))->toBeFalse();

    // Empty values defer to the nullable rule rather than failing here
    $empty = new ResolvableVehicle();
    expect($empty->passes('vehicle', null))->toBeTrue()
        ->and($empty->message())->toBeString();
});
