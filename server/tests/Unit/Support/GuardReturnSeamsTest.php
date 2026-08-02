<?php

use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Warranty;

/**
 * Covers the guard clauses that several helpers use to bail out on missing or
 * unusable input. Each is invoked with exactly the input that trips its guard,
 * paired where practical with the input that gets past it, so the assertions
 * pin the boundary rather than just reaching the line.
 */
function fleetopsGuardInvoke(object $target, string $method, ...$arguments)
{
    $reflection = new ReflectionMethod($target, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($target, ...$arguments);
}

test('metrics report no currency unless a subclass declares one', function () {
    // The base implementation is the fallback for every non-monetary metric
    $metric = (new ReflectionClass(Fleetbase\FleetOps\Support\Metrics\OrdersCompletedMetric::class))->newInstanceWithoutConstructor();

    expect($metric->currency())->toBeNull();
});

test('telematics providers reject empty timestamps and sensor names', function () {
    $flespi = (new ReflectionClass(Fleetbase\FleetOps\Support\Telematics\Providers\FlespiProvider::class))->newInstanceWithoutConstructor();

    // Falsy timestamps bail out before Carbon is asked to parse them
    expect(fleetopsGuardInvoke($flespi, 'parseTimestamp', null))->toBeNull()
        ->and(fleetopsGuardInvoke($flespi, 'parseTimestamp', ''))->toBeNull()
        ->and(fleetopsGuardInvoke($flespi, 'parseTimestamp', 0))->toBeNull();

    // Numeric and string forms both resolve once past the guard
    expect(fleetopsGuardInvoke($flespi, 'parseTimestamp', 1750000000))->toBeString()
        ->and(fleetopsGuardInvoke($flespi, 'parseTimestamp', '2026-07-29 08:30:00'))->toBe('2026-07-29 08:30:00');

    $afaqy = (new ReflectionClass(Fleetbase\FleetOps\Support\Telematics\Providers\AfaqyProvider::class))->newInstanceWithoutConstructor();

    // With no name anywhere in the payload and no fallback there is nothing to return
    expect(fleetopsGuardInvoke($afaqy, 'resolveSensorName', []))->toBeNull()
        ->and(fleetopsGuardInvoke($afaqy, 'resolveSensorName', ['name' => '']))->toBeNull()
        ->and(fleetopsGuardInvoke($afaqy, 'resolveSensorName', [], 'Fallback Sensor'))->toBe('Fallback Sensor')
        ->and(fleetopsGuardInvoke($afaqy, 'resolveSensorName', ['param' => 'temp_1']))->toBe('temp_1');
});

test('maintenance efficiency needs both actual and estimated durations', function () {
    // Datetime casts read their format off the connection grammar, so the model
    // needs a resolver even though nothing is queried here
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);

    $maintenance = new Maintenance();
    // duration_hours is derived from started_at/completed_at rather than stored
    $maintenance->setRawAttributes([
        'uuid'         => 'maintenance-guard-1',
        'scheduled_at' => '2026-07-20 08:00:00',
        'started_at'   => '2026-07-20 08:00:00',
        'completed_at' => '2026-07-20 12:00:00',
        'meta'         => json_encode([]),
    ], true);

    // Assert the actual-duration guard is satisfied first, so a null rating can
    // only come from the missing estimate rather than from the earlier guard
    expect($maintenance->duration_hours)->not->toBeNull()
        ->and($maintenance->scheduled_at)->not->toBeNull()
        ->and($maintenance->completed_at)->not->toBeNull()
        ->and($maintenance->getEfficiencyRating())->toBeNull();
});

test('revenue exclusions skip tables the schema does not have', function () {
    // A connection with no orders table means there is nothing to exclude, so the
    // query must be left alone rather than referencing a missing table
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    app()->instance('db', new class($connection) {
        public function __construct(public $c)
        {
        }

        public function connection($name = null)
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    Illuminate\Support\Facades\Schema::clearResolvedInstances();
    app()->instance('db.schema', $connection->getSchemaBuilder());

    // Deliberately create no `orders` table so the schema guard trips
    $query  = Vehicle::query();
    $before = $query->toSql();

    $reflection = new ReflectionMethod(Fleetbase\FleetOps\Support\Metrics\ActiveRevenueQuery::class, 'excludeInactiveOrders');
    $reflection->setAccessible(true);
    $reflection->invoke(null, $query);

    expect($query->toSql())->toBe($before);
});

test('export location parts bail out when the reference cannot be resolved', function () {
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    app()->instance('db', new class($connection) {
        public function __construct(public $c)
        {
        }

        public function connection($name = null)
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $connection->getSchemaBuilder()->create('places', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'name', 'location'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    // An unresolvable place public id makes Utils::castPoint() hand back null
    // (getPointFromMixed returns null rather than throwing for this input), so
    // there is no coordinate to report for either axis
    $export = new Fleetbase\FleetOps\Exports\VehicleExport();

    expect(fleetopsGuardInvoke($export, 'locationPart', 'place_doesnotexist', 'lat'))->toBeNull()
        ->and(fleetopsGuardInvoke($export, 'locationPart', 'place_doesnotexist', 'lng'))->toBeNull();

    // A real point still reports both axes, so the guard is not swallowing work
    $point = new Fleetbase\LaravelMysqlSpatial\Types\Point(1.2816, 103.8636);
    expect(fleetopsGuardInvoke($export, 'locationPart', $point, 'lat'))->toBe(1.2816)
        ->and(fleetopsGuardInvoke($export, 'locationPart', $point, 'lng'))->toBe(103.8636);
});

test('non transferable warranties refuse to move to a new subject', function () {
    $warranty = new Warranty();
    $warranty->setRawAttributes([
        'uuid'         => 'warranty-guard-1',
        'subject_type' => Vehicle::class,
        'subject_uuid' => 'vehicle-guard-1',
        'terms'        => json_encode(['transferable' => false]),
    ], true);

    $newSubject = new Vehicle();
    $newSubject->setRawAttributes(['uuid' => 'vehicle-guard-2'], true);

    // The transfer is refused outright, leaving the original subject in place
    expect($warranty->transferTo($newSubject))->toBeFalse()
        ->and($warranty->subject_uuid)->toBe('vehicle-guard-1');
});
