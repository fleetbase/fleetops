<?php

use Fleetbase\FleetOps\Http\Resources\v1\Maintenance as MaintenanceResource;
use Fleetbase\FleetOps\Http\Resources\v1\MaintenanceSchedule as MaintenanceScheduleResource;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the Maintenance and MaintenanceSchedule API resources: loaded
 * polymorphic relation serialization, the Ember maintenance-subject and
 * facilitator type injections with empty passthroughs, and the morph
 * transformer fallbacks.
 */
function fleetopsMaintenanceResourcesBoot(): void
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
    app()->instance('request', new Request());

    $schema = $connection->getSchemaBuilder();
    foreach ([
        'files'               => ['uuid', 'public_id', 'company_uuid', 'type', 'path', 'disk'],
        'custom_field_values' => ['uuid', 'subject_uuid', 'subject_type', 'custom_field_uuid', 'value', 'value_type'],
        'drivers'             => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'status'],
        'users'               => ['uuid', 'public_id', 'company_uuid', 'name'],
        'devices'             => ['uuid', 'public_id', 'company_uuid', 'attachable_uuid', 'attachable_type', 'device_id', 'status'],
        'orders'              => ['uuid', 'public_id', 'company_uuid', 'vehicle_assigned_uuid', 'driver_assigned_uuid', 'status'],
        'vehicle_devices'     => ['uuid', 'vehicle_uuid', 'device_uuid'],
        'equipment'           => ['uuid', 'public_id', 'company_uuid', 'name', 'status'],
    ] as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }
}

test('maintenance schedule type injections derive subject and facilitator slugs', function () {
    fleetopsMaintenanceResourcesBoot();

    $schedule = new MaintenanceSchedule();
    $schedule->setRawAttributes([
        'uuid'                  => 'ms-1',
        'public_id'             => 'maintenance_schedule_test',
        'subject_type'          => Vehicle::class,
        'default_assignee_type' => Fleetbase\FleetOps\Models\Vendor::class,
    ], true);

    $resource = new MaintenanceScheduleResource($schedule);

    $setSubjectType = new ReflectionMethod(MaintenanceScheduleResource::class, 'setSubjectType');
    $setSubjectType->setAccessible(true);
    $subject = $setSubjectType->invoke($resource, ['uuid' => 'vehicle-1', 'name' => 'Truck']);
    expect($subject['type'])->toBe('maintenance-subject-vehicle')
        ->and($setSubjectType->invoke($resource, null))->toBeNull();

    $setAssigneeType = new ReflectionMethod(MaintenanceScheduleResource::class, 'setAssigneeType');
    $setAssigneeType->setAccessible(true);
    $assignee = $setAssigneeType->invoke($resource, ['uuid' => 'vendor-1', 'name' => 'Shop']);
    expect($assignee['facilitator_type'])->toBe('facilitator-vendor')
        ->and($setAssigneeType->invoke($resource, []))->toBe([]);

    $transform = new ReflectionMethod(MaintenanceScheduleResource::class, 'transformMorphResource');
    $transform->setAccessible(true);
    expect($transform->invoke($resource, null))->toBeNull();

    $plain = new class extends EloquentModel {
        protected $table = 'plain_models';
    };
    $plain->setRawAttributes(['uuid' => 'plain-1', 'name' => 'Plain'], true);
    expect($transform->invoke($resource, $plain)['uuid'])->toBe('plain-1');
});

test('maintenance type injections derive maintainable and performer slugs', function () {
    fleetopsMaintenanceResourcesBoot();

    $maintenance = new Maintenance();
    $maintenance->setRawAttributes([
        'uuid'              => 'mnt-1',
        'public_id'         => 'maintenance_test',
        'maintainable_type' => Vehicle::class,
        'performed_by_type' => Fleetbase\FleetOps\Models\Contact::class,
    ], true);

    $resource = new MaintenanceResource($maintenance);

    $setMaintainableType = new ReflectionMethod(MaintenanceResource::class, 'setMaintainableType');
    $setMaintainableType->setAccessible(true);
    $maintainable = $setMaintainableType->invoke($resource, ['uuid' => 'vehicle-1', 'name' => 'Truck']);
    expect($maintainable['type'])->toContain('vehicle')
        ->and($setMaintainableType->invoke($resource, null))->toBeNull();

    $setPerformedByType = new ReflectionMethod(MaintenanceResource::class, 'setPerformedByType');
    $setPerformedByType->setAccessible(true);
    $performer = $setPerformedByType->invoke($resource, ['uuid' => 'contact-1', 'name' => 'Mechanic']);
    expect($performer)->toBeArray()
        ->and($setPerformedByType->invoke($resource, []))->toBe([]);

    $transform = new ReflectionMethod(MaintenanceResource::class, 'transformMorphResource');
    $transform->setAccessible(true);
    expect($transform->invoke($resource, null))->toBeNull();

    $plain = new class extends EloquentModel {
        protected $table = 'plain_models';
    };
    $plain->setRawAttributes(['uuid' => 'plain-2', 'name' => 'Plain'], true);
    expect($transform->invoke($resource, $plain)['uuid'])->toBe('plain-2');
});

test('loaded polymorphic relations serialize through the when loaded callbacks', function () {
    fleetopsMaintenanceResourcesBoot();

    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_test', 'name' => 'Truck'], true);

    $schedule = new MaintenanceSchedule();
    $schedule->setRawAttributes(['uuid' => 'ms-1', 'public_id' => 'maintenance_schedule_test', 'subject_type' => Vehicle::class], true);
    $schedule->setRelation('subject', $vehicle);

    $payload = (new MaintenanceScheduleResource($schedule))->toArray(new Request());
    expect($payload['subject']['type'])->toBe('maintenance-subject-vehicle');

    $maintenance = new Maintenance();
    $maintenance->setRawAttributes(['uuid' => 'mnt-1', 'public_id' => 'maintenance_test', 'maintainable_type' => Vehicle::class], true);
    $maintenance->setRelation('maintainable', $vehicle);

    $maintenancePayload = (new MaintenanceResource($maintenance))->toArray(new Request());
    expect($maintenancePayload['maintainable'])->toBeArray();
});
