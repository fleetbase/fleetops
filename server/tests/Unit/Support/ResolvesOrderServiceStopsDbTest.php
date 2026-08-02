<?php

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\ResolvesOrderServiceStops;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Str;

/**
 * Covers the database-backed ResolvesOrderServiceStops helpers that the
 * fake-based trait test cannot reach: proof resolution, stop completion
 * via stored tracking statuses, destination advancement, current-stop
 * activity detection, endpoint tracking-number creation, endpoint activity
 * insertion, and tracking-number status lookups.
 */
if (!function_exists('Fleetbase\FleetOps\Support\event')) {
    eval('namespace Fleetbase\FleetOps\Support; function event($event = null) { \FleetOpsServiceStopsDbRecorder::$events[] = $event; return $event; }');
}

class FleetOpsServiceStopsDbRecorder
{
    public static array $events = [];
}

class FleetOpsServiceStopsDbProbe
{
    use ResolvesOrderServiceStops;

    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsServiceStopsDbBoot(): SQLiteConnection
{
    if (!Str::hasMacro('humanize')) {
        Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Str::snake((string) $value)));
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
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

    $barcodeFake = new class {
        public function __call($method, $arguments)
        {
            return 'barcode';
        }
    };
    app()->instance('DNS2D', $barcodeFake);
    app()->instance('DNS1D', $barcodeFake);

    $schema = $connection->getSchemaBuilder();
    app()->instance('db.schema', $schema);
    $tables = [
        'orders'            => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'order_config_uuid', 'status', 'type', 'meta'],
        'payloads'          => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'pickup_tracking_number_uuid', 'dropoff_tracking_number_uuid', 'meta'],
        'places'            => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'city', 'country', 'province', 'location', 'meta'],
        'waypoints'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'order', 'type'],
        'tracking_numbers'  => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'region', 'location', 'status_uuid', 'owner_uuid', 'owner_type', 'qr_code', 'barcode', '_key'],
        'tracking_statuses' => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'proof_uuid', 'status', 'details', 'location', 'code', 'complete', '_key'],
        'proofs'            => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'remarks', 'raw_data', 'data'],
        'entities'          => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'name', 'type'],
        'companies'         => ['uuid', 'public_id', 'name', 'country'],
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
    $connection->table('companies')->insert(['uuid' => 'company-1', 'public_id' => 'company_test', 'name' => 'Acme', 'country' => 'SG']);
    FleetOpsServiceStopsDbRecorder::$events = [];

    return $connection;
}

function fleetopsServiceStopsDbPlace(string $uuid, string $name): Place
{
    $place = new Place();
    $place->setRawAttributes(['uuid' => $uuid, 'public_id' => 'place_' . $uuid, 'company_uuid' => 'company-1', 'name' => $name, 'country' => 'SG'], true);
    $place->exists = true;

    return $place;
}

function fleetopsServiceStopsDbPayload(): Payload
{
    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-1', 'public_id' => 'payload_test', 'company_uuid' => 'company-1'], true);
    $payload->exists = true;

    $payload->setRelation('pickup', fleetopsServiceStopsDbPlace('place-p', 'Pickup'));
    $payload->setRelation('dropoff', fleetopsServiceStopsDbPlace('place-d', 'Dropoff'));
    $payload->setRelation('waypoints', collect());
    $payload->setRelation('waypointMarkers', collect());
    $payload->setRelation('entities', collect());

    return $payload;
}

function fleetopsServiceStopsDbOrder(Payload $payload, string $status = 'created'): Order
{
    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-1', 'public_id' => 'order_test', 'company_uuid' => 'company-1', 'payload_uuid' => $payload->uuid, 'status' => $status], true);
    $order->exists = true;
    $order->setRelation('payload', $payload);

    return $order;
}

test('proof resolution accepts instances public ids and rejects other input', function () {
    $connection = fleetopsServiceStopsDbBoot();
    $probe      = new FleetOpsServiceStopsDbProbe();

    $proof = new Proof();
    $proof->setRawAttributes(['uuid' => 'proof-1', 'public_id' => 'proof_test'], true);
    expect($probe->callHelper('resolveProof', $proof))->toBe($proof);

    $connection->table('proofs')->insert(['uuid' => 'proof-2', 'public_id' => 'proof_stored', 'company_uuid' => 'company-1']);
    expect($probe->callHelper('resolveProof', 'proof_stored')?->uuid)->toBe('proof-2')
        ->and($probe->callHelper('resolveProof', 42))->toBeNull();
});

test('endpoint stop completion reads stored tracking statuses', function () {
    $connection = fleetopsServiceStopsDbBoot();
    $probe      = new FleetOpsServiceStopsDbProbe();
    $payload    = fleetopsServiceStopsDbPayload();
    $order      = fleetopsServiceStopsDbOrder($payload);
    $stops      = $probe->callHelper('payloadServiceStops', $payload);

    // Completed tracking statuses complete the endpoint stop
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-p', 'company_uuid' => 'company-1', 'status_uuid' => 'ts-p']);
    $connection->table('tracking_statuses')->insert(['uuid' => 'ts-p', 'tracking_number_uuid' => 'tn-p', 'code' => 'COMPLETED', 'complete' => '1']);
    $payload->pickup_tracking_number_uuid = 'tn-p';

    expect($probe->callHelper('serviceStopIsComplete', $order, $payload, $stops->first()))->toBeTrue();
});

test('next incomplete stop resolution advances the payload destination', function () {
    $connection = fleetopsServiceStopsDbBoot();
    $probe      = new FleetOpsServiceStopsDbProbe();
    $payload    = fleetopsServiceStopsDbPayload();
    $order      = fleetopsServiceStopsDbOrder($payload);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1']);

    // Current stop defaults to the first (pickup); next incomplete is dropoff
    $next = $probe->callHelper('nextIncompleteServiceStop', $order, $payload);
    expect($next['type'])->toBe('dropoff');

    $advanced = $probe->callHelper('advanceCurrentServiceStopDestination', $order, $payload);
    expect($advanced['type'])->toBe('dropoff')
        ->and($payload->current_waypoint_uuid)->toBe('place-d');

    // With the dropoff current there is nothing further to advance to
    expect($probe->callHelper('advanceCurrentServiceStopDestination', $order, $payload))->toBeNull();
});

test('current stop activity detection checks tracking status codes', function () {
    $connection = fleetopsServiceStopsDbBoot();
    $probe      = new FleetOpsServiceStopsDbProbe();
    $payload    = fleetopsServiceStopsDbPayload();
    $activity   = new Activity(['code' => 'dispatched', 'status' => 'Dispatched', 'details' => 'Order dispatched']);

    // No tracking number on the current endpoint stop
    expect($probe->callHelper('payloadHasCurrentServiceStopActivity', $payload, $activity))->toBeFalse()
        ->and($probe->callHelper('payloadHasCurrentServiceStopActivity', null, $activity))->toBeFalse();

    // Matching tracking status code found for the endpoint stop
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-p', 'company_uuid' => 'company-1']);
    $connection->table('tracking_statuses')->insert(['uuid' => 'ts-1', 'tracking_number_uuid' => 'tn-p', 'code' => 'DISPATCHED']);
    $payload->pickup_tracking_number_uuid = 'tn-p';
    expect($probe->callHelper('payloadHasCurrentServiceStopActivity', $payload, $activity))->toBeTrue();

    // Waypoint stops check their own tracking number
    $waypoint = new Waypoint();
    $waypoint->setRawAttributes(['uuid' => 'wp-1', 'payload_uuid' => 'payload-1', 'place_uuid' => 'place-w', 'tracking_number_uuid' => 'tn-p', 'order' => '1'], true);
    $waypoint->exists = true;
    $waypoint->setRelation('place', fleetopsServiceStopsDbPlace('place-w', 'Waypoint'));
    $waypoint->setRelation('trackingNumber', null);
    $payload->setRelation('waypointMarkers', collect([$waypoint]));
    $payload->current_waypoint_uuid = 'wp-1';
    expect($probe->callHelper('payloadHasCurrentServiceStopActivity', $payload, $activity))->toBeTrue();

    $waypoint->tracking_number_uuid = null;
    expect($probe->callHelper('payloadHasCurrentServiceStopActivity', $payload, $activity))->toBeFalse();
});

test('endpoint tracking numbers resolve existing rows and create new ones', function () {
    $connection = fleetopsServiceStopsDbBoot();
    $probe      = new FleetOpsServiceStopsDbProbe();
    $payload    = fleetopsServiceStopsDbPayload();
    $order      = fleetopsServiceStopsDbOrder($payload);
    $stops      = $probe->callHelper('payloadServiceStops', $payload);

    // Without create nothing is resolved
    expect($probe->callHelper('endpointServiceStopTrackingNumber', $order, $payload, $stops->first(), false))->toBeNull();

    // Creation persists a tracking number and links it on the payload
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1']);
    $created = $probe->callHelper('endpointServiceStopTrackingNumber', $order, $payload, $stops->first(), true);
    expect($created)->toBeInstanceOf(TrackingNumber::class)
        ->and($connection->table('tracking_numbers')->count())->toBe(1);

    // Existing linked tracking numbers resolve directly
    $existing = $probe->callHelper('endpointServiceStopTrackingNumber', $order, $payload, $stops->first(), false);
    expect($existing)->toBeInstanceOf(TrackingNumber::class);
});

test('endpoint activity insertion writes tracking statuses and updates the number', function () {
    $connection = fleetopsServiceStopsDbBoot();
    $probe      = new FleetOpsServiceStopsDbProbe();
    $payload    = fleetopsServiceStopsDbPayload();
    $order      = fleetopsServiceStopsDbOrder($payload);
    $stops      = $probe->callHelper('payloadServiceStops', $payload);
    $activity   = new Activity(['code' => 'completed', 'status' => 'Completed', 'details' => 'Stop completed']);

    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1']);

    $activityId = $probe->callHelper('insertEndpointServiceStopActivity', $order, $payload, $stops->first(), $activity, [1.3, 103.8]);

    // Tracking-number creation writes an initial CREATED status; the
    // endpoint activity adds the COMPLETED one and links it on the number.
    expect($activityId)->not->toBeNull()
        ->and($connection->table('tracking_statuses')->count())->toBe(2)
        ->and($connection->table('tracking_statuses')->where('code', 'COMPLETED')->count())->toBe(1)
        ->and($connection->table('tracking_numbers')->value('status_uuid'))->toBe($activityId);
});

test('tracking number status lookup prefers the linked status uuid', function () {
    $connection = fleetopsServiceStopsDbBoot();
    $probe      = new FleetOpsServiceStopsDbProbe();

    expect($probe->callHelper('trackingNumberStatus', 'missing'))->toBeNull();

    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-1', 'company_uuid' => 'company-1', 'status_uuid' => 'ts-2']);
    $connection->table('tracking_statuses')->insert([
        ['uuid' => 'ts-1', 'tracking_number_uuid' => 'tn-1', 'code' => 'CREATED'],
        ['uuid' => 'ts-2', 'tracking_number_uuid' => 'tn-1', 'code' => 'DISPATCHED'],
    ]);
    expect($probe->callHelper('trackingNumberStatus', 'tn-1')?->code)->toBe('DISPATCHED');

    // Without a linked status the latest row wins
    $connection->table('tracking_numbers')->where('uuid', 'tn-1')->update(['status_uuid' => null]);
    expect($probe->callHelper('trackingNumberStatus', 'tn-1'))->not->toBeNull();
});

test('update current stop activity records endpoint activity or bails without location', function () {
    $connection = fleetopsServiceStopsDbBoot();
    $probe      = new FleetOpsServiceStopsDbProbe();
    $payload    = fleetopsServiceStopsDbPayload();
    $order      = fleetopsServiceStopsDbOrder($payload);
    $activity   = new Activity(['code' => 'started', 'status' => 'Started', 'details' => 'Order started']);

    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1']);

    // Without a location the stop is returned untouched
    $probe->callHelper('updateCurrentServiceStopActivity', $order, $activity, null);
    expect($connection->table('tracking_statuses')->count())->toBe(0);

    // With a location the endpoint activity is inserted (plus the initial
    // CREATED status written when the tracking number is generated)
    $probe->callHelper('updateCurrentServiceStopActivity', $order, $activity, [1.3, 103.8]);
    expect($connection->table('tracking_statuses')->where('code', 'STARTED')->count())->toBe(1);

    // skipEndpointOrderActivity suppresses the insertion
    $probe->callHelper('updateCurrentServiceStopActivity', $order, $activity, [1.3, 103.8], null, true);
    expect($connection->table('tracking_statuses')->where('code', 'STARTED')->count())->toBe(1);
});
