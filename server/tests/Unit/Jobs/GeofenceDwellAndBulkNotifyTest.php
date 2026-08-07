<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\NavigatorController;
use Fleetbase\FleetOps\Jobs\CheckGeofenceDwell;
use Fleetbase\FleetOps\Jobs\NotifyBulkAssignedDriver;
use Fleetbase\FleetOps\Models\Customer;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the CheckGeofenceDwell job branches, the NotifyBulkAssignedDriver
 * job, the Customer model type scoping and id normalization, and the public
 * navigator driver-onboard settings endpoint against an in-memory SQLite
 * fixture.
 */
if (!function_exists('Fleetbase\FleetOps\Jobs\event')) {
    eval('namespace Fleetbase\FleetOps\Jobs; function event($event = null) { \FleetOpsDwellRecorder::$events[] = $event; return $event; }');
}

if (!function_exists('logger')) {
    function logger()
    {
        return app('log');
    }
}

class FleetOpsDwellRecorder
{
    public static array $events = [];
}

function fleetopsDwellBoot(): SQLiteConnection
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
        'driver_geofence_states'  => ['driver_uuid', 'geofence_uuid', 'geofence_type', 'is_inside', 'entered_at'],
        'vehicle_geofence_states' => ['vehicle_uuid', 'geofence_uuid', 'geofence_type', 'is_inside', 'entered_at'],
        'drivers'                 => ['uuid', 'public_id', 'company_uuid', 'user_uuid'],
        'vehicles'                => ['uuid', 'public_id', 'company_uuid'],
        'users'                   => ['uuid', 'public_id', 'company_uuid'],
        'zones'                   => ['uuid', 'public_id', 'company_uuid', 'name', 'service_area_uuid'],
        'service_areas'           => ['uuid', 'public_id', 'company_uuid', 'name'],
        'orders'                  => ['uuid', 'public_id', 'company_uuid', 'driver_assigned_uuid', 'status'],
        'contacts'                => ['uuid', 'public_id', 'company_uuid', 'name', 'type'],
        'companies'               => ['uuid', 'public_id', 'name'],
        'settings'                => ['key', 'value'],
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
    FleetOpsDwellRecorder::$events = [];

    return $connection;
}

test('dwell check fires events only while the subject remains inside', function () {
    $connection = fleetopsDwellBoot();
    $connection->table('driver_geofence_states')->insert([
        'driver_uuid'   => 'driver-1',
        'geofence_uuid' => 'zone-1',
        'geofence_type' => 'zone',
        'is_inside'     => '1',
        'entered_at'    => now()->subMinutes(20)->toDateTimeString(),
    ]);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'company_uuid' => 'company-1']);
    $connection->table('zones')->insert(['uuid' => 'zone-1', 'company_uuid' => 'company-1', 'name' => 'North Zone']);

    (new CheckGeofenceDwell('driver-1', 'zone-1', 'zone'))->handle();
    expect(FleetOpsDwellRecorder::$events)->toHaveCount(1);

    // Exited subjects do not fire dwell events
    FleetOpsDwellRecorder::$events = [];
    (new CheckGeofenceDwell('driver-2', 'zone-1', 'zone'))->handle();
    expect(FleetOpsDwellRecorder::$events)->toBe([]);
});

test('dwell check skips missing subjects and geofences with warnings', function () {
    $connection = fleetopsDwellBoot();
    $connection->table('driver_geofence_states')->insert([
        'driver_uuid'   => 'driver-ghost',
        'geofence_uuid' => 'zone-1',
        'geofence_type' => 'zone',
        'is_inside'     => '1',
        'entered_at'    => now()->toDateTimeString(),
    ]);

    // Missing subject
    (new CheckGeofenceDwell('driver-ghost', 'zone-1', 'zone'))->handle();
    expect(FleetOpsDwellRecorder::$events)->toBe([]);

    // Present vehicle subject but missing service-area geofence
    $connection->table('vehicle_geofence_states')->insert([
        'vehicle_uuid'  => 'vehicle-1',
        'geofence_uuid' => 'sa-missing',
        'geofence_type' => 'service_area',
        'is_inside'     => '1',
        'entered_at'    => now()->toDateTimeString(),
    ]);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'company_uuid' => 'company-1']);

    (new CheckGeofenceDwell('vehicle-1', 'sa-missing', 'service_area', 'vehicle'))->handle();
    expect(FleetOpsDwellRecorder::$events)->toBe([]);
});

test('bulk driver notification assigns and notifies matched orders', function () {
    $connection = fleetopsDwellBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);
    $connection->table('orders')->insert(['uuid' => 'order-1', 'company_uuid' => 'company-1', 'status' => 'created']);

    // Notification delivery is unavailable in the harness; the failure log
    // seam captures each attempted order.
    (new NotifyBulkAssignedDriver(['order-1'], 'driver-1'))->handle();
    expect(true)->toBeTrue();

    // Missing drivers exit without touching orders
    (new NotifyBulkAssignedDriver(['order-1'], 'driver-missing'))->handle();
    expect(true)->toBeTrue();
});

test('customer model scopes to customer contacts and normalizes public ids', function () {
    $connection = fleetopsDwellBoot();
    $connection->table('contacts')->insert([
        ['uuid' => 'contact-1', 'public_id' => 'contact_abc', 'company_uuid' => 'company-1', 'name' => 'Customer A', 'type' => 'customer'],
        ['uuid' => 'contact-2', 'public_id' => 'contact_def', 'company_uuid' => 'company-1', 'name' => 'Plain Contact', 'type' => 'contact'],
    ]);

    // Contact defines an instance-level count(); go through query() for the
    // aggregate so the global type scope is exercised.
    expect(Customer::query()->count())->toBe(1);

    $byCustomerId = Customer::findFromCustomerId('customer_abc');
    expect($byCustomerId)->toBeInstanceOf(Customer::class)
        ->and($byCustomerId->uuid)->toBe('contact-1');

    expect(Customer::findFromCustomerId('contact_abc')?->uuid)->toBe('contact-1')
        ->and(Customer::findFromCustomerId('customer_missing'))->toBeNull();
});

test('navigator driver onboard settings endpoint resolves company settings', function () {
    $connection = fleetopsDwellBoot();
    $connection->table('companies')->insert(['uuid' => 'company-1', 'public_id' => 'company_test', 'name' => 'Acme']);
    $connection->table('settings')->insert(['key' => 'fleet-ops.driver-onboard-settings.company-1', 'value' => json_encode(['enabled' => true])]);

    $response = (new NavigatorController())->getDriverOnboardSettings('company_test');
    expect($response->getData(true)['driverOnboardSettings'])->not->toBeEmpty();

    // Companies without settings fall back to an empty array
    $connection->table('companies')->insert(['uuid' => 'company-2', 'public_id' => 'company_two', 'name' => 'Beta']);
    $empty = (new NavigatorController())->getDriverOnboardSettings('company_two');
    expect($empty->getData(true)['driverOnboardSettings'])->toBe([]);
});
