<?php

use Fleetbase\FleetOps\Support\Ai\Capabilities\AssetStatusCapability;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the AssetStatusCapability count helpers against SQLite: totals,
 * online/offline partitions and status aggregation scoped to the session
 * company.
 */
function fleetopsAssetStatusCapabilityBoot(): SQLiteConnection
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
        'orders'           => ['uuid', 'public_id', 'company_uuid', 'transaction_uuid', 'tracking_number_uuid', 'status', 'meta', '_key'],
        'vehicles'         => ['uuid', 'public_id', 'company_uuid', 'name', '_key'],
        'transactions'     => ['uuid', 'public_id', 'company_uuid', 'amount', 'currency', '_key'],
        'tracking_numbers' => ['uuid', 'public_id', 'company_uuid', 'tracking_number', '_key'],
        'contacts'         => ['uuid', 'public_id', 'company_uuid', 'name', 'type', '_key'],
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
    $schema->create('users', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'name', 'email', 'type', 'status', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });
    $schema->create('drivers', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'user_uuid', 'status', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->integer('online')->nullable();
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    session(['company' => 'company-1']);
    $connection->table('users')->insert([
        ['uuid' => 'user-cap-1', 'company_uuid' => 'company-1'],
        ['uuid' => 'user-cap-2', 'company_uuid' => 'company-1'],
        ['uuid' => 'user-cap-3', 'company_uuid' => 'company-1'],
        ['uuid' => 'user-cap-4', 'company_uuid' => 'company-2'],
    ]);
    $connection->table('drivers')->insert([
        ['uuid' => 'driver-cap-1', 'user_uuid' => 'user-cap-1', 'company_uuid' => 'company-1', 'status' => 'active', 'online' => 1],
        ['uuid' => 'driver-cap-2', 'user_uuid' => 'user-cap-2', 'company_uuid' => 'company-1', 'status' => 'active', 'online' => 0],
        ['uuid' => 'driver-cap-3', 'user_uuid' => 'user-cap-3', 'company_uuid' => 'company-1', 'status' => 'inactive', 'online' => null],
        ['uuid' => 'driver-cap-4', 'user_uuid' => 'user-cap-4', 'company_uuid' => 'company-2', 'status' => 'active', 'online' => 1],
    ]);

    return $connection;
}

test('asset status count helpers partition drivers by presence and status', function () {
    fleetopsAssetStatusCapabilityBoot();

    $capability = (new ReflectionClass(AssetStatusCapability::class))->newInstanceWithoutConstructor();
    $helper     = function (string $method, ...$arguments) use ($capability) {
        $reflection = new ReflectionMethod(AssetStatusCapability::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($capability, ...$arguments);
    };

    expect($helper('totalForModel', Fleetbase\FleetOps\Models\Driver::class))->toBe(3)
        ->and($helper('onlineCountForModel', Fleetbase\FleetOps\Models\Driver::class))->toBe(1)
        ->and($helper('offlineCountForModel', Fleetbase\FleetOps\Models\Driver::class))->toBe(2)
        ->and($helper('countsByStatusForModel', Fleetbase\FleetOps\Models\Driver::class))->toBe(['active' => 2, 'inactive' => 1]);
});

test('search capability queries scope companies with eager relations', function () {
    fleetopsAssetStatusCapabilityBoot();
    $capability = (new ReflectionClass(Fleetbase\FleetOps\Support\Ai\Capabilities\SearchResourcesCapability::class))->newInstanceWithoutConstructor();
    $helper     = function (string $method, ...$arguments) use ($capability) {
        $reflection = new ReflectionMethod(Fleetbase\FleetOps\Support\Ai\Capabilities\SearchResourcesCapability::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($capability, ...$arguments);
    };

    expect($helper('orderSearchQuery')->count())->toBe(0)
        ->and($helper('vehicleSearchQuery')->count())->toBe(0)
        ->and($helper('driverSearchQuery')->count())->toBe(3)
        ->and($helper('genericSearchQuery', Fleetbase\FleetOps\Models\Contact::class)->count())->toBe(0);
});
