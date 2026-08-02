<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

use Fleetbase\FleetOps\Http\Resources\v1\Waypoint as WaypointResource;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

class FleetOpsWaypointResourceRouteFixture
{
    public function __construct(private string $uri)
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }
}

class FleetOpsWaypointResourceDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

function fleetopsWaypointResourceRequest(bool $internal): Request
{
    $uri     = $internal ? 'api/int/v1/fleet-ops/waypoints/place_public' : 'api/v1/fleet-ops/waypoints/place_public';
    $request = Request::create('/' . $uri, 'GET');
    $request->setRouteResolver(fn () => new FleetOpsWaypointResourceRouteFixture($uri));
    app()->instance('request', $request);
    app()->instance('redis', new class {
        public function connection(): self
        {
            return $this;
        }

        public function __call(string $method, array $arguments): mixed
        {
            return null;
        }
    });

    return $request;
}

function fleetopsWaypointResourceUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table waypoints (id integer primary key autoincrement, uuid varchar(64), public_id varchar(64), payload_uuid varchar(64), place_uuid varchar(64), tracking_number_uuid varchar(64), customer_uuid varchar(64) null, customer_type varchar(255) null, "order" integer null, type varchar(64) null, notes text null, pod_method varchar(64) null, pod_required integer null, time_window_start datetime null, time_window_end datetime null, service_time integer null, created_at datetime null, updated_at datetime null, deleted_at datetime null)');
    $connection->statement('create table tracking_numbers (id integer primary key autoincrement, uuid varchar(64), tracking_number varchar(64), last_status varchar(64) null, last_status_code varchar(64) null, last_status_complete integer null, created_at datetime null, updated_at datetime null, deleted_at datetime null)');
    $connection->statement('create table tracking_statuses (id integer primary key autoincrement, uuid varchar(64), tracking_number_uuid varchar(64), status varchar(64), code varchar(64), complete integer, created_at datetime null, updated_at datetime null, deleted_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsWaypointResourceDatabaseProbe($connection));

    return $connection;
}

function fleetopsWaypointResourcePlace(array $attributes = []): Place
{
    $place = new Place();
    $place->setRawAttributes(array_merge([
        'id'                   => 42,
        'uuid'                 => 'place-uuid',
        'public_id'            => 'place_public',
        'payload_uuid'         => 'payload-uuid',
        'name'                 => 'Waypoint Place',
        'location'             => new Point(1.29027, 103.851959),
        'address'              => 'WAYPOINT PLACE - 12 ROUTE WAY, DOCK 2, SINGAPORE, CENTRAL, 018956',
        'address_html'         => '<strong>12 Route Way</strong>',
        'street1'              => '12 Route Way',
        'street2'              => 'Dock 2',
        'city'                 => 'Singapore',
        'province'             => 'Central',
        'postal_code'          => '018956',
        'neighborhood'         => 'Downtown',
        'district'             => 'District 1',
        'building'             => 'Tower',
        'security_access_code' => '1234',
        'country'              => 'SG',
        'country_name'         => 'Singapore',
        'phone'                => '+6565550000',
        'owner_type'           => null,
        'owner_uuid'           => null,
        'type'                 => 'dropoff',
        'meta'                 => ['dock' => 'A'],
        'eta'                  => '2026-07-27 09:30:00',
        'created_at'           => '2026-07-26 09:00:00',
        'updated_at'           => '2026-07-27 09:00:00',
    ], $attributes), true);

    return $place;
}

test('waypoint resource resolves public waypoint stop details from matching place and payload', function () {
    $connection = fleetopsWaypointResourceUseInMemoryConnection();
    $connection->table('tracking_numbers')->insert([
        'uuid'                 => 'tracking-uuid',
        'tracking_number'      => 'TN-WAYPOINT',
        'last_status'          => 'Arrived',
        'last_status_code'     => 'arrived',
        'last_status_complete' => 1,
        'created_at'           => '2026-07-26 08:00:00',
        'updated_at'           => '2026-07-27 08:00:00',
        'deleted_at'           => null,
    ]);
    $connection->table('tracking_statuses')->insert([
        'uuid'                 => 'tracking-status-uuid',
        'tracking_number_uuid' => 'tracking-uuid',
        'status'               => 'Arrived',
        'code'                 => 'arrived',
        'complete'             => 1,
        'created_at'           => '2026-07-27 08:30:00',
        'updated_at'           => '2026-07-27 08:30:00',
        'deleted_at'           => null,
    ]);
    $connection->table('waypoints')->insert([
        'uuid'                 => 'waypoint-uuid',
        'public_id'            => 'waypoint_public',
        'payload_uuid'         => 'payload-uuid',
        'place_uuid'           => 'place-uuid',
        'tracking_number_uuid' => 'tracking-uuid',
        'customer_uuid'        => null,
        'customer_type'        => null,
        'order'                => 2,
        'type'                 => 'dropoff',
        'notes'                => 'Use loading dock A.',
        'pod_method'           => 'photo',
        'pod_required'         => 1,
        'time_window_start'    => '2026-07-27 09:00:00',
        'time_window_end'      => '2026-07-27 10:00:00',
        'service_time'         => 15,
        'created_at'           => '2026-07-26 08:00:00',
        'updated_at'           => '2026-07-27 08:00:00',
        'deleted_at'           => null,
    ]);

    $payload = (new WaypointResource(fleetopsWaypointResourcePlace()))->resolve(fleetopsWaypointResourceRequest(false));

    expect($payload)->toMatchArray([
        'id'                   => 'place_public',
        'order'                => 2,
        'tracking'             => 'TN-WAYPOINT',
        'status'               => 'Arrived',
        'status_code'          => 'arrived',
        'complete'             => true,
        'name'                 => 'Waypoint Place',
        'address'              => 'WAYPOINT PLACE - 12 ROUTE WAY, DOCK 2, SINGAPORE, CENTRAL, 018956',
        'street1'              => '12 Route Way',
        'street2'              => 'Dock 2',
        'city'                 => 'Singapore',
        'province'             => 'Central',
        'postal_code'          => '018956',
        'neighborhood'         => 'Downtown',
        'district'             => 'District 1',
        'building'             => 'Tower',
        'security_access_code' => '1234',
        'country'              => 'SG',
        'phone'                => '+6565550000',
        'type'                 => 'dropoff',
        'meta'                 => ['dock' => 'A'],
        'eta'                  => '2026-07-27 09:30:00',
        'notes'                => 'Use loading dock A.',
        'pod_method'           => 'photo',
        'pod_required'         => true,
        'service_time'         => 15,
    ])
        ->and($payload)->not->toHaveKey('uuid')
        ->and($payload)->not->toHaveKey('public_id')
        ->and($payload)->not->toHaveKey('waypoint_public_id')
        ->and($payload)->not->toHaveKey('customer_uuid');
});

test('waypoint resource includes internal identifiers for internal requests', function () {
    $connection = fleetopsWaypointResourceUseInMemoryConnection();
    $connection->table('tracking_numbers')->insert([
        'uuid'                 => 'tracking-uuid',
        'tracking_number'      => 'TN-INTERNAL',
        'last_status'          => 'Created',
        'last_status_code'     => 'created',
        'last_status_complete' => 0,
        'created_at'           => '2026-07-26 08:00:00',
        'updated_at'           => '2026-07-27 08:00:00',
        'deleted_at'           => null,
    ]);
    $connection->table('tracking_statuses')->insert([
        'uuid'                 => 'tracking-status-uuid',
        'tracking_number_uuid' => 'tracking-uuid',
        'status'               => 'Created',
        'code'                 => 'created',
        'complete'             => 0,
        'created_at'           => '2026-07-27 08:30:00',
        'updated_at'           => '2026-07-27 08:30:00',
        'deleted_at'           => null,
    ]);
    $connection->table('waypoints')->insert([
        'uuid'                 => 'waypoint-uuid',
        'public_id'            => 'waypoint_public',
        'payload_uuid'         => 'payload-uuid',
        'place_uuid'           => 'place-uuid',
        'tracking_number_uuid' => 'tracking-uuid',
        'customer_uuid'        => null,
        'customer_type'        => null,
        'order'                => 1,
        'type'                 => 'pickup',
        'notes'                => null,
        'pod_method'           => null,
        'pod_required'         => 0,
        'time_window_start'    => null,
        'time_window_end'      => null,
        'service_time'         => null,
        'created_at'           => '2026-07-26 08:00:00',
        'updated_at'           => '2026-07-27 08:00:00',
        'deleted_at'           => null,
    ]);

    $payload = (new WaypointResource(fleetopsWaypointResourcePlace()))->resolve(fleetopsWaypointResourceRequest(true));

    expect($payload)->toMatchArray([
        'id'                 => 42,
        'uuid'               => 'place-uuid',
        'public_id'          => 'place_public',
        'waypoint_public_id' => 'waypoint_public',
        'tracking'           => 'TN-INTERNAL',
        'status'             => 'Created',
        'status_code'        => 'created',
        'complete'           => false,
        'address_html'       => 'WAYPOINT PLACE - 12 ROUTE WAY, DOCK 2, SINGAPORE, CENTRAL, 018956',
        'country_name'       => 'Singapore',
        'pod_required'       => false,
    ])
        ->and($payload['location'])->toBeInstanceOf(Point::class);
});
