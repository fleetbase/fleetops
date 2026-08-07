<?php

use Fleetbase\FleetOps\Http\Resources\v1\WorkOrder as WorkOrderResource;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\WorkOrder;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the WorkOrder API resource: serialization with loaded polymorphic
 * target/assignee relations, the Ember polymorphic type injection helpers,
 * and the morph resource transformer with its resource-lookup fallback.
 */
function fleetopsWorkOrderResourceBoot(): void
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
        'vehicles'            => ['uuid', 'public_id', 'company_uuid', 'plate_number', 'year', 'make', 'model'],
        'contacts'            => ['uuid', 'public_id', 'company_uuid', 'name', 'type'],
        'custom_field_values' => ['uuid', 'subject_uuid', 'subject_type', 'custom_field_uuid', 'value', 'value_type'],
        // Serializing a loaded Vehicle target pulls in its avatar, driver and
        // device relations; a loaded Contact assignee pulls in its user.
        'files'               => ['uuid', 'public_id', 'company_uuid', 'type', 'path', 'disk'],
        'drivers'             => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'status'],
        'users'               => ['uuid', 'public_id', 'company_uuid', 'name'],
        'devices'             => ['uuid', 'public_id', 'company_uuid', 'attachable_uuid', 'attachable_type', 'device_id', 'status'],
        'vehicle_devices'     => ['uuid', 'vehicle_uuid', 'device_uuid'],
        'orders'              => ['uuid', 'public_id', 'company_uuid', 'vehicle_assigned_uuid', 'driver_assigned_uuid', 'status'],
        'places'              => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'owner_type', 'name'],
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

function fleetopsWorkOrderResourceModel(): WorkOrder
{
    $workOrder = new WorkOrder();
    $workOrder->setRawAttributes([
        'uuid'          => 'wo-1',
        'public_id'     => 'work_order_test',
        'code'          => 'WO-100',
        'subject'       => 'Replace brake pads',
        'category'      => 'maintenance',
        'status'        => 'open',
        'priority'      => 'high',
        'target_type'   => Vehicle::class,
        'target_uuid'   => 'vehicle-1',
        'assignee_type' => Fleetbase\FleetOps\Models\Contact::class,
        'assignee_uuid' => 'contact-1',
    ], true);
    $workOrder->exists = true;

    return $workOrder;
}

test('work order resource serializes core attributes and computed fields', function () {
    fleetopsWorkOrderResourceBoot();

    $payload = (new WorkOrderResource(fleetopsWorkOrderResourceModel()))->toArray(new Request());

    expect($payload['code'])->toBe('WO-100')
        ->and($payload['subject'])->toBe('Replace brake pads')
        ->and($payload['status'])->toBe('open')
        ->and($payload['is_overdue'])->toBeFalse()
        ->and($payload['completion_percentage'])->toBe(0.0);
});

test('loaded polymorphic target and assignee serialize through the when loaded callbacks', function () {
    fleetopsWorkOrderResourceBoot();

    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_test', 'plate_number' => 'SGX1234'], true);

    $contact = new Fleetbase\FleetOps\Models\Contact();
    $contact->setRawAttributes(['uuid' => 'contact-1', 'public_id' => 'contact_test', 'name' => 'Mechanic'], true);

    $workOrder = fleetopsWorkOrderResourceModel();
    $workOrder->setRelation('target', $vehicle);
    $workOrder->setRelation('assignee', $contact);

    $payload = (new WorkOrderResource($workOrder))->toArray(new Request());

    // Unloaded relations are omitted entirely by whenLoaded, so reaching these
    // keys at all is what proves the callbacks ran.
    expect($payload['target']['subject_type'])->toBe('maintenance-subject-vehicle')
        ->and($payload['assignee']['facilitator_type'])->toBe('facilitator-contact');
});

test('polymorphic type injection derives ember subject and facilitator slugs', function () {
    fleetopsWorkOrderResourceBoot();
    $resource = new WorkOrderResource(fleetopsWorkOrderResourceModel());

    $setTargetType = new ReflectionMethod(WorkOrderResource::class, 'setTargetType');
    $setTargetType->setAccessible(true);

    $target = $setTargetType->invoke($resource, ['uuid' => 'vehicle-1', 'name' => 'Truck 9']);
    expect($target['type'])->toBe('maintenance-subject-vehicle')
        ->and($target['subject_type'])->toBe('maintenance-subject-vehicle');

    // Empty resolutions pass through untouched
    expect($setTargetType->invoke($resource, null))->toBeNull()
        ->and($setTargetType->invoke($resource, []))->toBe([]);

    $setAssigneeType = new ReflectionMethod(WorkOrderResource::class, 'setAssigneeType');
    $setAssigneeType->setAccessible(true);

    $assignee = $setAssigneeType->invoke($resource, ['uuid' => 'contact-1', 'name' => 'Mechanic']);
    expect($assignee['type'])->toBe('facilitator-contact')
        ->and($assignee['facilitator_type'])->toBe('facilitator-contact');

    expect($setAssigneeType->invoke($resource, null))->toBeNull();
});

test('morph resource transformer resolves registered and fallback resources', function () {
    fleetopsWorkOrderResourceBoot();
    $resource = new WorkOrderResource(fleetopsWorkOrderResourceModel());

    $transform = new ReflectionMethod(WorkOrderResource::class, 'transformMorphResource');
    $transform->setAccessible(true);

    // Null morphs resolve to null
    expect($transform->invoke($resource, null))->toBeNull();

    // Models without a registered http resource use the JsonResource fallback
    $plain = new class extends EloquentModel {
        protected $table = 'plain_models';
    };
    $plain->setRawAttributes(['uuid' => 'plain-1', 'name' => 'Plain'], true);

    $resolved = $transform->invoke($resource, $plain);
    expect($resolved)->toBeArray()
        ->and($resolved['uuid'])->toBe('plain-1');
});
