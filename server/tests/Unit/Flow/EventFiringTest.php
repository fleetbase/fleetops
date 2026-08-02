<?php

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Flow\Event;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;

/**
 * Covers Flow\Event::fire(): a name that resolves to a real event class is
 * instantiated with the order, decorated with the activity and waypoint, and
 * dispatched. Names with no matching class dispatch nothing.
 */
class FleetOpsFlowEventRecorder
{
    public static array $fired = [];
}

if (!Illuminate\Support\Str::hasMacro('humanize')) {
    Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
}

if (!function_exists('Fleetbase\Events\logger')) {
    eval('namespace Fleetbase\Events; function logger($message = null, array $context = []) { return new class { public function __call($m, $a) { return null; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Flow\event')) {
    eval('namespace Fleetbase\FleetOps\Flow; function event($event = null) { \FleetOpsFlowEventRecorder::$fired[] = $event; return $event; }');
}

function fleetopsFlowEventBoot(): Illuminate\Database\SQLiteConnection
{
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

    $schema = $connection->getSchemaBuilder();
    foreach (['orders', 'payloads', 'drivers', 'companies', 'waypoints', 'tracking_numbers'] as $table) {
        $schema->create($table, function ($blueprint) {
            $blueprint->increments('id');
            foreach (['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'driver_assigned_uuid', 'status', 'type', '_key'] as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-flow-1']);

    return $connection;
}

test('flow events dispatch resolved classes carrying activity and waypoint', function () {
    fleetopsFlowEventBoot();
    FleetOpsFlowEventRecorder::$fired = [];

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-flow-1', 'public_id' => 'order_flowone'], true);

    $waypoint = new Waypoint();
    $waypoint->setRawAttributes(['uuid' => 'waypoint-flow-1'], true);

    $activity = new Activity(['key' => 'order_completed', 'code' => 'completed', 'status' => 'Completed', 'details' => 'Done'], []);

    // entity_completed resolves to the EntityCompleted event class
    $event = new Event('order_dispatched', $order);
    $event->fire($order, $activity, $waypoint);

    expect(FleetOpsFlowEventRecorder::$fired)->toHaveCount(1)
        ->and(FleetOpsFlowEventRecorder::$fired[0])->toBeInstanceOf(Fleetbase\FleetOps\Events\OrderDispatched::class)
        ->and(FleetOpsFlowEventRecorder::$fired[0]->activity)->toBe($activity)
        ->and(FleetOpsFlowEventRecorder::$fired[0]->waypoint)->toBe($waypoint);

    // A name with no matching event class dispatches nothing
    FleetOpsFlowEventRecorder::$fired = [];
    (new Event('definitely_not_an_event', $order))->fire($order, $activity, $waypoint);
    expect(FleetOpsFlowEventRecorder::$fired)->toHaveCount(0);
});
