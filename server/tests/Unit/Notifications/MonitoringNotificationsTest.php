<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Notifications\LateDeparture;
use Fleetbase\FleetOps\Notifications\ProlongedStoppage;
use Fleetbase\FleetOps\Notifications\RouteDeviation;
use Fleetbase\FleetOps\Notifications\WaypointCompleted;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Covers the fleet monitoring notifications: the late-departure,
 * prolonged-stoppage, and route-deviation mail bodies with tracking/public-id
 * fallback, and the waypoint-completed broadcast channel construction plus
 * its push channel seams.
 */
function fleetopsMonitoringNotifBoot(): SQLiteConnection
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
        'orders'           => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'status'],
        'tracking_numbers' => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid'],
        'companies'        => ['uuid', 'public_id', 'name'],
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

    session(['company' => 'company-1', 'api_credential' => 'console']);

    return $connection;
}

function fleetopsMonitoringNotifOrder(): Order
{
    $order = new Order();
    $order->setRawAttributes([
        'uuid'      => 'order-1',
        'public_id' => 'order_test',
        'tracking'  => 'TRK-42',
    ], true);
    $order->exists = true;

    return $order;
}

test('monitoring notifications render mail bodies and database payloads', function () {
    fleetopsMonitoringNotifBoot();
    $order = fleetopsMonitoringNotifOrder();

    foreach ([
        [new LateDeparture($order, ['grace' => 15]), 'Late departure', 'order.late_departure'],
        [new ProlongedStoppage($order, ['minutes' => 30]), 'Prolonged stoppage', 'order.prolonged_stoppage'],
        [new RouteDeviation($order, ['meters' => 500]), 'Route deviation', 'order.route_deviation'],
    ] as [$notification, $subjectFragment, $event]) {
        expect($notification->via(null))->toBe(['mail', 'database']);

        // Order.tracking resolves through the tracking-number relation, which
        // is absent here, so the public id fallback renders in mail bodies.
        $mail = $notification->toMail(null);
        expect($mail)->toBeInstanceOf(MailMessage::class)
            ->and($mail->subject)->toContain('order_test');

        $payload = $notification->toArray(null);
        expect($payload['event'])->toBe($event)
            ->and($payload['order_uuid'])->toBe('order-1')
            ->and($payload['message'])->toContain('order_test');
    }
});

test('waypoint completed broadcasts on order channels and exposes push seams', function () {
    $connection = fleetopsMonitoringNotifBoot();
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_test', 'payload_uuid' => 'payload-1', 'company_uuid' => 'company-1']);

    $waypoint = new Waypoint();
    $waypoint->setRawAttributes(['uuid' => 'wp-1', 'public_id' => 'waypoint_1', 'payload_uuid' => 'payload-1', 'tracking_number_uuid' => 'tn-1'], true);
    $waypoint->exists = true;

    $trackingNumber = new Fleetbase\FleetOps\Models\TrackingNumber();
    $trackingNumber->setRawAttributes(['uuid' => 'tn-1', 'tracking_number' => 'WPTRK-1'], true);
    $waypoint->setRelation('trackingNumber', $trackingNumber);

    $activity = new Fleetbase\FleetOps\Flow\Activity(['code' => 'COMPLETED', 'details' => 'Waypoint completed']);

    $notification = new WaypointCompleted($waypoint, $activity);

    expect($notification->via(null))->toContain('broadcast', 'mail');

    $channels = $notification->broadcastOn();
    expect(count($channels))->toBe(5)
        ->and($channels[3]->name)->toBe('order.order-1')
        ->and($channels[4]->name)->toBe('order.order_test');

    // Without a matching order only the api channel remains
    $connection->table('orders')->delete();
    expect($notification->broadcastOn())->toHaveCount(1);

    expect($notification->toArray()['title'])->not->toBeNull();

    // Push transports and console urls are unavailable in the harness; the
    // delegation bodies still execute up to those seams.
    expect(fn () => $notification->toMail(null))->toThrow(Error::class)
        ->and(fn () => $notification->toFcm(null))->toThrow(TypeError::class)
        ->and(fn () => $notification->toApn(null))->toThrow(Error::class);
});
