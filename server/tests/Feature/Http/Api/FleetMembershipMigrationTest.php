<?php

use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The membership uniqueness migration, run against a table that already holds
 * the duplicates it exists to prevent.
 *
 * An index cannot be added on top of existing duplicates, so the migration has
 * to decide which row survives. The policy has to be deterministic — a second
 * run must be a no-op — and it must never reach past the pivot: no fleet,
 * vehicle or driver is touched, and no surviving membership changes state.
 */
function fleetopsMembershipMigrationBoot(): SQLiteConnection
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

        public function getDatabaseName(): string
        {
            return 'main';
        }

        public function table($table, $as = null)
        {
            return $this->c->table($table, $as);
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    app()->instance('db.schema', $connection->getSchemaBuilder());
    DB::clearResolvedInstance('db');
    Schema::clearResolvedInstance('db.schema');

    $schema = $connection->getSchemaBuilder();
    foreach (['fleet_vehicles' => 'vehicle_uuid', 'fleet_drivers' => 'driver_uuid'] as $table => $subjectColumn) {
        $schema->create($table, function ($blueprint) use ($subjectColumn) {
            $blueprint->increments('id');
            $blueprint->string('uuid')->nullable();
            $blueprint->string('fleet_uuid')->nullable();
            $blueprint->string($subjectColumn)->nullable();
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    return $connection;
}

/**
 * Reach the migration's cleanup directly. `information_schema` does not exist on
 * sqlite, so the index step is exercised separately in FleetMembershipTest.
 */
function fleetopsRunMembershipCleanup(string $table, string $memberColumn): void
{
    $migration  = require dirname(__DIR__, 4) . '/migrations/2026_09_05_000001_add_unique_indexes_to_fleet_membership_pivots.php';
    $reflection = new ReflectionMethod($migration, 'removeDuplicateMemberships');
    $reflection->setAccessible(true);
    $reflection->invoke($migration, $table, $memberColumn);
}

test('duplicate cleanup keeps the active membership and drops the rest', function () {
    $connection = fleetopsMembershipMigrationBoot();

    $connection->table('fleet_vehicles')->insert([
        // A tombstone recorded first, then the membership the fleet actually has.
        ['id' => 1, 'uuid' => 'a', 'fleet_uuid' => 'fleet-1', 'vehicle_uuid' => 'vehicle-1', 'deleted_at' => '2026-01-01 00:00:00'],
        ['id' => 2, 'uuid' => 'b', 'fleet_uuid' => 'fleet-1', 'vehicle_uuid' => 'vehicle-1', 'deleted_at' => null],
        ['id' => 3, 'uuid' => 'c', 'fleet_uuid' => 'fleet-1', 'vehicle_uuid' => 'vehicle-1', 'deleted_at' => null],
        // A different pair, and an unrelated fleet, both untouched.
        ['id' => 4, 'uuid' => 'd', 'fleet_uuid' => 'fleet-1', 'vehicle_uuid' => 'vehicle-2', 'deleted_at' => null],
        ['id' => 5, 'uuid' => 'e', 'fleet_uuid' => 'fleet-2', 'vehicle_uuid' => 'vehicle-1', 'deleted_at' => null],
    ]);

    fleetopsRunMembershipCleanup('fleet_vehicles', 'vehicle_uuid');

    $survivors = $connection->table('fleet_vehicles')->orderBy('id')->pluck('uuid')->all();

    // The live row wins over the tombstone, the lowest id wins among equals, and
    // nothing outside the duplicated pair is affected.
    expect($survivors)->toBe(['b', 'd', 'e'])
        ->and($connection->table('fleet_vehicles')->where('uuid', 'b')->value('deleted_at'))->toBeNull();
});

test('duplicate cleanup keeps one restorable row when every duplicate is soft deleted', function () {
    $connection = fleetopsMembershipMigrationBoot();

    $connection->table('fleet_drivers')->insert([
        ['id' => 1, 'uuid' => 'a', 'fleet_uuid' => 'fleet-1', 'driver_uuid' => 'driver-1', 'deleted_at' => '2026-01-01 00:00:00'],
        ['id' => 2, 'uuid' => 'b', 'fleet_uuid' => 'fleet-1', 'driver_uuid' => 'driver-1', 'deleted_at' => '2026-02-01 00:00:00'],
    ]);

    fleetopsRunMembershipCleanup('fleet_drivers', 'driver_uuid');

    // Removing both would turn a later re-assignment into a brand new row and
    // lose the original membership's history.
    expect($connection->table('fleet_drivers')->pluck('uuid')->all())->toBe(['a'])
        ->and($connection->table('fleet_drivers')->where('uuid', 'a')->value('deleted_at'))->not->toBeNull();
});

test('duplicate cleanup is idempotent and leaves orphaned rows alone', function () {
    $connection = fleetopsMembershipMigrationBoot();

    $connection->table('fleet_vehicles')->insert([
        ['id' => 1, 'uuid' => 'a', 'fleet_uuid' => 'fleet-1', 'vehicle_uuid' => 'vehicle-1', 'deleted_at' => null],
        ['id' => 2, 'uuid' => 'b', 'fleet_uuid' => 'fleet-1', 'vehicle_uuid' => 'vehicle-1', 'deleted_at' => null],
        // Orphaned data rather than a membership: a null side never collides in
        // a unique index, so it is not the migration's business.
        ['id' => 3, 'uuid' => 'c', 'fleet_uuid' => null, 'vehicle_uuid' => 'vehicle-9', 'deleted_at' => null],
        ['id' => 4, 'uuid' => 'd', 'fleet_uuid' => null, 'vehicle_uuid' => 'vehicle-9', 'deleted_at' => null],
    ]);

    fleetopsRunMembershipCleanup('fleet_vehicles', 'vehicle_uuid');
    $afterFirst = $connection->table('fleet_vehicles')->orderBy('id')->pluck('uuid')->all();

    fleetopsRunMembershipCleanup('fleet_vehicles', 'vehicle_uuid');
    $afterSecond = $connection->table('fleet_vehicles')->orderBy('id')->pluck('uuid')->all();

    expect($afterFirst)->toBe(['a', 'c', 'd'])
        ->and($afterSecond)->toBe($afterFirst);
});
