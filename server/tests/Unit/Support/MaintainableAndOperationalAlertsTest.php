<?php

use Fleetbase\FleetOps\Console\Commands\ProcessOperationalAlerts;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Part;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

/**
 * Covers the Maintainable trait through the Part model and the
 * ProcessOperationalAlerts helper bodies against SQLite: maintenance
 * relations and interval checks, maintenance scheduling and cost/frequency
 * aggregation, the operational orders query window, once-only alert
 * metadata, latest-position lookup, route point collection, distance
 * minimization, and alert settings resolution.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Traits\auth')) {
    eval('namespace Fleetbase\FleetOps\Traits; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\Models\session')) {
    eval('namespace Fleetbase\Models; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function missing($k) { return \session($k) === null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

class FleetOpsOperationalAlertsProbe extends ProcessOperationalAlerts
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsMaintainableBoot(): SQLiteConnection
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
        'maintenances' => ['uuid', 'public_id', 'company_uuid', 'maintainable_type', 'maintainable_uuid', 'maintainable_id', 'type', 'status', 'scheduled_at', 'started_at', 'completed_at', 'odometer', 'engine_hours', 'summary', 'notes', 'priority', 'total_cost', 'created_by_uuid', 'meta'],
        'parts'        => ['uuid', 'public_id', 'company_uuid', 'name', 'odometer', 'meta', 'specs', 'purchased_at', 'status'],
        'orders'       => ['uuid', 'public_id', 'company_uuid', 'status', 'scheduled_at', 'started_at', 'started', 'meta', 'route_uuid', 'driver_assigned_uuid'],
        'positions'    => ['uuid', 'public_id', 'company_uuid', 'order_uuid', 'subject_uuid', 'coordinates', 'speed', '_key'],
        'settings'     => ['key', 'value'],
        'companies'    => ['uuid', 'public_id', 'name'],
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

    return $connection;
}

function fleetopsMaintainableVehicle(array $attributes = []): Part
{
    $part = new Part();
    $part->setRawAttributes(array_merge([
        'uuid'         => 'vehicle-1',
        'public_id'    => 'part_maint',
        'company_uuid' => 'company-1',
        'name'         => 'Brake Pads',
    ], $attributes), true);
    $part->exists = true;

    return $part;
}

test('maintainable relations and interval checks resolve against history', function () {
    $connection = fleetopsMaintainableBoot();
    $vehicle    = fleetopsMaintainableVehicle();

    // No history and no interval configuration: no maintenance needed
    expect($vehicle->needsMaintenance())->toBeFalse()
        ->and($vehicle->last_maintenance)->toBeNull()
        ->and($vehicle->next_maintenance)->toBeNull();

    // Overdue scheduled maintenance flips the check
    $connection->table('maintenances')->insert([
        'uuid'              => 'mnt-1',
        'company_uuid'      => 'company-1',
        'maintainable_type' => Part::class,
        'maintainable_uuid' => 'vehicle-1',
        'maintainable_id'   => 'vehicle-1',
        'type'              => 'inspection',
        'status'            => 'scheduled',
        'scheduled_at'      => Carbon::now()->subDays(3)->toDateTimeString(),
    ]);
    expect($vehicle->needsMaintenance())->toBeTrue();

    // Completed history feeds last-maintenance interval checks
    $connection->table('maintenances')->where('uuid', 'mnt-1')->update([
        'status'       => 'completed',
        'completed_at' => Carbon::now()->subDays(120)->toDateTimeString(),
        'total_cost'   => '250',
    ]);
    $configured = fleetopsMaintainableVehicle(['meta' => json_encode(['maintenance_interval_days' => 90])]);
    expect($configured->needsMaintenance())->toBeTrue()
        ->and($configured->last_maintenance)->not->toBeNull();
});

test('maintainable schedules maintenance and aggregates cost and frequency', function () {
    $connection = fleetopsMaintainableBoot();
    $vehicle    = fleetopsMaintainableVehicle(['odometer' => '120000']);

    $scheduled = $vehicle->scheduleMaintenance('oil_change', Carbon::now()->addDays(7), ['summary' => 'Routine oil change', 'priority' => 'low']);
    expect($scheduled)->toBeInstanceOf(Maintenance::class)
        ->and($connection->table('maintenances')->count())->toBe(1)
        ->and($connection->table('maintenances')->value('priority'))->toBe('low');

    $connection->table('maintenances')->insert([
        'uuid'              => 'mnt-done',
        'company_uuid'      => 'company-1',
        'maintainable_type' => Part::class,
        'maintainable_uuid' => 'vehicle-1',
        'maintainable_id'   => 'vehicle-1',
        'type'              => 'inspection',
        'status'            => 'completed',
        'completed_at'      => Carbon::now()->subDays(30)->toDateTimeString(),
        'total_cost'        => '400',
    ]);

    expect((float) $vehicle->getMaintenanceCost())->toBe(400.0)
        ->and($vehicle->getMaintenanceFrequency())->toBeGreaterThan(0);
});

test('operational alert helpers query orders positions and settings', function () {
    $connection = fleetopsMaintainableBoot();
    $probe      = new FleetOpsOperationalAlertsProbe();

    $connection->table('orders')->insert([
        ['uuid' => 'order-1', 'company_uuid' => 'company-1', 'status' => 'created', 'created_at' => Carbon::now()->subHours(2)->toDateTimeString()],
        ['uuid' => 'order-2', 'company_uuid' => 'company-1', 'status' => 'completed', 'created_at' => Carbon::now()->subHours(2)->toDateTimeString()],
        ['uuid' => 'order-3', 'company_uuid' => null, 'status' => 'created', 'created_at' => Carbon::now()->subHours(2)->toDateTimeString()],
    ]);

    $orders = $probe->callHelper('ordersQuery', Carbon::now()->subDay())->get();
    expect($orders)->toHaveCount(1)
        ->and($orders->first()->uuid)->toBe('order-1');

    $connection->table('positions')->insert([
        ['uuid' => 'pos-1', 'company_uuid' => 'company-1', 'order_uuid' => 'order-1', 'created_at' => Carbon::now()->subMinutes(10)->toDateTimeString()],
        ['uuid' => 'pos-2', 'company_uuid' => 'company-1', 'order_uuid' => 'order-1', 'created_at' => Carbon::now()->subMinutes(5)->toDateTimeString()],
    ]);
    $order = Order::where('uuid', 'order-1')->withoutGlobalScopes()->first();
    expect($probe->callHelper('latestPositionForOrder', $order))->toBeInstanceOf(Position::class);

    $settings = $probe->callHelper('alertSettings');
    expect($settings['late_departures']['enabled'])->toBeTrue()
        ->and($settings['route_deviations']['distance_threshold_meters'])->toBe(500)
        ->and($settings['prolonged_stoppages']['duration_threshold_minutes'])->toBe(30);
});

test('route point collection normalizes pairs and measures distances', function () {
    fleetopsMaintainableBoot();
    $probe = new FleetOpsOperationalAlertsProbe();

    $points = $probe->callHelper('collectPoints', [
        [1.30, 103.80],
        [[103.85, 1.35]],
        'not-a-pair',
        [200, 200],
    ]);
    expect(count($points))->toBe(2)
        ->and($points[0])->toBeInstanceOf(Point::class);

    $minimum = $probe->callHelper('minimumDistanceToRoute', new Point(1.30, 103.80), $points);
    expect($minimum)->toBeFloat()
        ->and($minimum)->toBeLessThan(10);

    // Once-only notification skips orders already flagged
    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-1', 'company_uuid' => 'company-1', 'meta' => json_encode(['operational_alerts' => ['late_departure' => ['notified_at' => '2026-07-28 08:00:00']]])], true);
    $order->exists = true;
    expect($probe->callHelper('notifyOnce', $order, 'late_departure', 'SomeNotification', [], true))->toBeFalse();
});
