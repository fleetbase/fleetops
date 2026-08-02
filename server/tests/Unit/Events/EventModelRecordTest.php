<?php

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Waypoint;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers getModelRecord() on the entity and waypoint activity events: each
 * resolves the order that owns the subject's payload.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

function fleetopsEventRecordBoot(): SQLiteConnection
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

    $connection->getSchemaBuilder()->create('orders', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'status'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    session(['company' => 'company-event-1']);
    $connection->table('orders')->insert([
        ['uuid' => 'order-event-1', 'company_uuid' => 'company-event-1', 'payload_uuid' => 'payload-event-1'],
        ['uuid' => 'order-event-2', 'company_uuid' => 'company-event-1', 'payload_uuid' => 'payload-event-2'],
    ]);

    return $connection;
}

function fleetopsEventActivity(): Activity
{
    return new Activity(['key' => 'order_started', 'code' => 'started', 'status' => 'Started', 'details' => 'Started'], []);
}

test('entity activity events resolve the order owning the payload', function (string $eventClass) {
    fleetopsEventRecordBoot();

    $entity = new Entity();
    $entity->setRawAttributes(['uuid' => 'entity-event-1', 'payload_uuid' => 'payload-event-1'], true);

    $event = new $eventClass($entity, fleetopsEventActivity());
    expect($event->getModelRecord()?->uuid)->toBe('order-event-1');

    // An entity on another payload resolves that payload's order
    $other = new Entity();
    $other->setRawAttributes(['uuid' => 'entity-event-2', 'payload_uuid' => 'payload-event-2'], true);
    expect((new $eventClass($other, fleetopsEventActivity()))->getModelRecord()?->uuid)->toBe('order-event-2');
})->with([
    'activity changed' => [Fleetbase\FleetOps\Events\EntityActivityChanged::class],
    'completed'        => [Fleetbase\FleetOps\Events\EntityCompleted::class],
]);

test('waypoint activity events resolve the order owning the payload', function (string $eventClass) {
    fleetopsEventRecordBoot();

    $waypoint = new Waypoint();
    $waypoint->setRawAttributes(['uuid' => 'waypoint-event-1', 'payload_uuid' => 'payload-event-1'], true);

    $event = new $eventClass($waypoint, fleetopsEventActivity());
    expect($event->getModelRecord()?->uuid)->toBe('order-event-1');
})->with([
    'activity changed' => [Fleetbase\FleetOps\Events\WaypointActivityChanged::class],
    'completed'        => [Fleetbase\FleetOps\Events\WaypointCompleted::class],
]);
