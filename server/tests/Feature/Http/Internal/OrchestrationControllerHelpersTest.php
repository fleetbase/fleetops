<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrchestrationController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Orchestration\Engines\DriverAssignmentEngine;
use Fleetbase\FleetOps\Orchestration\Engines\RouteSequencingEngine;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the real bodies of the internal OrchestrationController's protected
 * helper methods against an in-memory SQLite fixture: order/vehicle/driver
 * query scopes, engine construction, transaction management, waypoint
 * sequencing, order-config custom field lookups, and customer/facilitator
 * contact and vendor resolution.
 */
class FleetOpsOrchestrationHelpersProbe extends OrchestrationController
{
    public function callProtected(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(OrchestrationController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsOrchestrationHelpersBoot(): SQLiteConnection
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
        'orders'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'status'],
        'vehicles'       => ['uuid', 'public_id', 'company_uuid', 'driver_uuid'],
        'drivers'        => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid'],
        'users'          => ['uuid', 'public_id', 'company_uuid'],
        'waypoints'      => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'order'],
        'contacts'       => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'type'],
        'vendors'        => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone'],
        'order_configs'  => ['uuid', 'public_id', 'company_uuid', 'name', 'key'],
        'custom_fields'  => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'name', 'label', 'type', 'required', 'order'],
        'settings'       => ['key', 'value'],
        'schedule_items' => ['uuid', 'public_id', 'company_uuid', 'assignee_uuid', 'assignee_type', 'status'],
        'manifests'      => ['uuid', 'public_id', 'company_uuid', 'vehicle_uuid', 'driver_uuid', 'status', 'meta', '_key'],
        'manifest_stops' => ['uuid', 'public_id', 'company_uuid', 'manifest_uuid', 'order_uuid', 'waypoint_uuid', 'sequence', 'type', 'meta', '_key'],
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

function fleetopsOrchestrationHelpersProbe(): FleetOpsOrchestrationHelpersProbe
{
    return app(FleetOpsOrchestrationHelpersProbe::class);
}

test('query scope helpers filter orders vehicles and drivers', function () {
    $connection = fleetopsOrchestrationHelpersBoot();
    $connection->table('orders')->insert([
        ['uuid' => 'order-1', 'public_id' => 'order_a', 'company_uuid' => 'company-1', 'payload_uuid' => null, 'status' => 'created'],
        ['uuid' => 'order-2', 'public_id' => 'order_b', 'company_uuid' => 'company-1', 'payload_uuid' => null, 'status' => 'completed'],
    ]);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_a', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'vehicle_uuid' => 'vehicle-1']);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_a', 'company_uuid' => 'company-1']);

    $probe = fleetopsOrchestrationHelpersProbe();

    expect($probe->callProtected('companyUuid'))->toBe('company-1')
        ->and($probe->callProtected('orchestratorOrdersQuery', 'company-1')->count())->toBe(1)
        ->and($probe->callProtected('orchestrationRunOrdersQuery', 'company-1')->get())->toHaveCount(1)
        ->and($probe->callProtected('orchestrationRunVehiclesQuery', 'company-1')->get())->toHaveCount(1);

    $drivers = $probe->callProtected('driversByPublicId', ['driver_a']);
    expect($drivers->has('driver_a'))->toBeTrue();

    expect($probe->callProtected('vehicleByPublicIdWithDriver', 'vehicle_a'))->toBeInstanceOf(Vehicle::class)
        ->and($probe->callProtected('vehicleByPublicIdWithDriver', 'missing'))->toBeNull()
        ->and($probe->callProtected('vehicleByUuidWithDriver', 'vehicle-1'))->toBeInstanceOf(Vehicle::class)
        ->and($probe->callProtected('vehicleByUuidWithDriver', 'missing'))->toBeNull();
});

test('engine and transaction helpers execute their real bodies', function () {
    fleetopsOrchestrationHelpersBoot();
    $probe = fleetopsOrchestrationHelpersProbe();

    expect($probe->callProtected('driverAssignmentEngine'))->toBeInstanceOf(DriverAssignmentEngine::class)
        ->and($probe->callProtected('routeSequencingEngine'))->toBeInstanceOf(RouteSequencingEngine::class)
        ->and($probe->callProtected('orchestratorEngineSetting'))->toBe('greedy');

    $probe->callProtected('beginOrchestrationTransaction');
    $probe->callProtected('commitOrchestrationTransaction');
    $probe->callProtected('beginOrchestrationTransaction');
    $probe->callProtected('rollBackOrchestrationTransaction');

    expect(true)->toBeTrue();
});

test('waypoint sequence helper updates the waypoint order column', function () {
    $connection = fleetopsOrchestrationHelpersBoot();
    $connection->table('waypoints')->insert(['uuid' => 'waypoint-1', 'public_id' => 'waypoint_a', 'payload_uuid' => 'payload-1', 'order' => '0']);

    fleetopsOrchestrationHelpersProbe()->callProtected('updateWaypointSequence', 'payload-1', 'waypoint_a', 3);

    expect($connection->table('waypoints')->value('order'))->toBe('3');
});

test('order config field helpers load configs and fall back to direct custom field queries', function () {
    $connection = fleetopsOrchestrationHelpersBoot();
    $connection->table('order_configs')->insert(['uuid' => 'config-1', 'public_id' => 'order_config_a', 'company_uuid' => 'company-1', 'name' => 'Transport', 'key' => 'transport']);
    $connection->table('custom_fields')->insert(['uuid' => 'field-1', 'subject_uuid' => 'config-1', 'subject_type' => 'order-config', 'name' => 'priority', 'label' => 'Priority', 'type' => 'text', 'order' => '1']);

    $probe   = fleetopsOrchestrationHelpersProbe();
    $configs = $probe->callProtected('getOrderConfigFieldConfigs', 'company-1');
    expect($configs)->toHaveCount(1);

    $fields = $probe->callProtected('getCustomFieldsForOrderConfig', 'config-1');
    expect($fields)->toHaveCount(1)
        ->and($fields->first()->name)->toBe('priority');
});

test('resolve or create contact matches by email phone or creates customers', function () {
    $connection = fleetopsOrchestrationHelpersBoot();
    $connection->table('contacts')->insert(['uuid' => 'contact-1', 'company_uuid' => 'company-1', 'email' => 'known@example.com', 'phone' => '+15550001', 'name' => 'Known', 'type' => 'customer']);

    $probe = fleetopsOrchestrationHelpersProbe();

    $byEmail = $probe->callProtected('resolveOrCreateContact', ['customer_email' => 'known@example.com'], 'company-1', 'customer');
    expect($byEmail)->toBeInstanceOf(Contact::class)
        ->and($byEmail->uuid)->toBe('contact-1');

    $byPhone = $probe->callProtected('resolveOrCreateContact', ['customer_phone' => '+15550001'], 'company-1', 'customer');
    expect($byPhone?->uuid)->toBe('contact-1');

    $created = $probe->callProtected('resolveOrCreateContact', ['customer_name' => 'New Customer', 'customer_email' => 'new@example.com'], 'company-1', 'customer');
    expect($created)->toBeInstanceOf(Contact::class)
        ->and($connection->table('contacts')->count())->toBe(2);

    expect($probe->callProtected('resolveOrCreateContact', [], 'company-1', 'customer'))->toBeNull();
});

test('resolve or create vendor matches by email phone name or creates vendors', function () {
    $connection = fleetopsOrchestrationHelpersBoot();
    $connection->table('vendors')->insert(['uuid' => 'vendor-1', 'company_uuid' => 'company-1', 'email' => 'acme@example.com', 'phone' => '+15550009', 'name' => 'Acme Logistics']);

    $probe = fleetopsOrchestrationHelpersProbe();

    expect($probe->callProtected('resolveOrCreateVendor', ['facilitator_email' => 'acme@example.com'], 'company-1', 'facilitator')?->uuid)->toBe('vendor-1')
        ->and($probe->callProtected('resolveOrCreateVendor', ['facilitator_phone' => '+15550009'], 'company-1', 'facilitator')?->uuid)->toBe('vendor-1')
        ->and($probe->callProtected('resolveOrCreateVendor', ['facilitator_name' => 'ACME LOGISTICS'], 'company-1', 'facilitator')?->uuid)->toBe('vendor-1');

    $created = $probe->callProtected('resolveOrCreateVendor', ['facilitator_name' => 'New Vendor'], 'company-1', 'facilitator');
    expect($created)->toBeInstanceOf(Vendor::class)
        ->and($connection->table('vendors')->count())->toBe(2);

    expect($probe->callProtected('resolveOrCreateVendor', [], 'company-1', 'facilitator'))->toBeNull();
});

test('record lookup and manifest creation helpers persist rows', function () {
    $connection = fleetopsOrchestrationHelpersBoot();
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_manif1', 'company_uuid' => 'company-1', 'status' => 'created']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_manif1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_manif1', 'company_uuid' => 'company-1']);

    $probe = fleetopsOrchestrationHelpersProbe();

    expect($probe->callProtected('findVehicleByPublicId', 'vehicle_manif1')?->uuid)->toBe('vehicle-1')
        ->and($probe->callProtected('findDriverByPublicId', 'driver_manif1')?->uuid)->toBe('driver-1')
        ->and($probe->callProtected('findOrderByPublicId', 'order_manif1')?->uuid)->toBe('order-1')
        ->and($probe->callProtected('findVehicleByPublicId', 'vehicle_missing'))->toBeNull();

    $manifest = $probe->callProtected('createManifest', ['company_uuid' => 'company-1', 'vehicle_uuid' => 'vehicle-1', 'status' => 'pending']);
    expect($connection->table('manifests')->count())->toBe(1);

    $probe->callProtected('createManifestStop', ['company_uuid' => 'company-1', 'manifest_uuid' => $manifest->uuid, 'order_uuid' => 'order-1', 'sequence' => 1]);
    expect($connection->table('manifest_stops')->count())->toBe(1);

    expect($probe->callProtected('orchestrationTransactionLevel'))->toBeInt();
});
