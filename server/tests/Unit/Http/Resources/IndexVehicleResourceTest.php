<?php

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Http\Resources\v1\Index\Vehicle as IndexVehicleResource;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FleetOpsIndexVehicleResourceProbe extends IndexVehicleResource
{
    public function compactDevicesForTest(): array
    {
        return $this->compactDevices();
    }

    public function locationCoordinatesForTest(): ?string
    {
        return $this->locationCoordinates();
    }

    public function speedLabelForTest(): string
    {
        return $this->speedLabel();
    }

    public function headingLabelForTest(): string
    {
        return $this->headingLabel();
    }

    public function statusLabelForTest(): ?string
    {
        return $this->statusLabel();
    }

    public function lastKnownPositionForTest(): ?Position
    {
        return $this->lastKnownPosition();
    }

    public function formatCoordinateForTest(float $coordinate): string
    {
        return $this->formatCoordinate($coordinate);
    }
}

function fleetopsIndexVehicleResourceConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table positions (id integer primary key autoincrement, uuid varchar(64), company_uuid varchar(64) null, subject_uuid varchar(64) null, subject_type varchar(255) null, order_uuid varchar(64) null, coordinates text null, speed integer null, heading integer null, created_at datetime null, updated_at datetime null, deleted_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    return $connection;
}

function fleetopsIndexVehicleResourceRequest(bool $internal = true): Request
{
    $uri     = $internal ? 'api/int/v1/fleet-ops/vehicles/vehicle_public' : 'api/v1/fleet-ops/vehicles/vehicle_public';
    $request = Request::create('/' . $uri, 'GET');
    $request->setRouteResolver(fn () => new class($uri) {
        public function __construct(private string $uri)
        {
        }

        public function uri(): string
        {
            return $this->uri;
        }
    });
    app()->instance('request', $request);

    return $request;
}

function fleetopsIndexVehicleModel(array $attributes = []): Vehicle
{
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(array_merge([
        'id'               => 101,
        'uuid'             => 'vehicle-uuid',
        'public_id'        => 'vehicle_public',
        'company_uuid'     => 'company-uuid',
        'vendor_uuid'      => 'vendor-uuid',
        'photo_uuid'       => null,
        'internal_id'      => 'VEH-101',
        'display_name'     => 'Index Van',
        'driver_name'      => 'Jane Driver',
        'plate_number'     => 'IDX-101',
        'serial_number'    => 'SER-101',
        'fuel_card_number' => 'FUEL-101',
        'vin'              => 'VIN-101',
        'make'             => 'Ford',
        'model'            => 'Transit',
        'year'             => 2026,
        'photo_url'        => null,
        'status'           => 'in_service',
        'location'         => new Point(1.23456, 103.98765),
        'heading'          => '180',
        'altitude'         => '33',
        'speed'            => '44',
        'online'           => 1,
    ], $attributes), true);
    $vehicle->exists = true;
    $vehicle->setRelation('driver', null);
    $vehicle->setRelation('photo', (object) ['url' => 'https://cdn.test/vehicle.png']);

    return $vehicle;
}

test('index vehicle resource helper methods resolve devices and position labels', function () {
    fleetopsIndexVehicleResourceConnection();
    session(['company' => 'company-uuid']);

    $vehicle  = fleetopsIndexVehicleModel();
    $position = new Position();
    $position->setRawAttributes([
        'uuid'    => 'position-uuid',
        'speed'   => 67,
        'heading' => 225,
    ], true);
    $vehicle->setRelation('last_known_position', $position);
    $vehicle->setRelation('devices', new Collection([
        (object) [
            'uuid'          => 'device-uuid',
            'public_id'     => 'device_public',
            'name'          => 'Tracker',
            'display_name'  => 'Tracker One',
            'device_id'     => 'HW-1',
            'serial_number' => 'SER-DEVICE',
            'imei'          => 'IMEI-1',
            'provider'      => 'samsara',
            'status'        => 'online',
        ],
    ]));

    $resource = new FleetOpsIndexVehicleResourceProbe($vehicle);
    fleetopsIndexVehicleResourceRequest(true);

    expect($resource->compactDevicesForTest())->toBe([
        [
            'id'            => 'device-uuid',
            'uuid'          => 'device-uuid',
            'public_id'     => 'device_public',
            'name'          => 'Tracker',
            'display_name'  => 'Tracker One',
            'device_id'     => 'HW-1',
            'serial_number' => 'SER-DEVICE',
            'imei'          => 'IMEI-1',
            'provider'      => 'samsara',
            'status'        => 'online',
        ],
    ])
        ->and($resource->locationCoordinatesForTest())->toBe('1.2346 103.9877')
        ->and($resource->speedLabelForTest())->toBe('67 km/h')
        ->and($resource->headingLabelForTest())->toBe('225 deg')
        ->and($resource->statusLabelForTest())->toBe('In Service')
        ->and($resource->formatCoordinateForTest(1.23456))->toBe('1.2346')
        ->and($resource->lastKnownPositionForTest()?->uuid)->toBe('position-uuid');
});

test('index vehicle resource falls back to vehicle order labels and public identity', function () {
    fleetopsIndexVehicleResourceConnection();
    session(['company' => 'company-uuid']);

    $vehicle = fleetopsIndexVehicleModel([
        'location' => null,
        'speed'    => 'slow',
        'heading'  => null,
        'status'   => null,
    ]);
    $vehicle->setRelation('last_known_position', null);
    $resource = new FleetOpsIndexVehicleResourceProbe($vehicle);
    fleetopsIndexVehicleResourceRequest(false);

    expect($resource->lastKnownPositionForTest())->toBeNull()
        ->and($resource->locationCoordinatesForTest())->toBe('0 0')
        ->and($resource->speedLabelForTest())->toBe('-')
        ->and($resource->headingLabelForTest())->toBe('-')
        ->and($resource->statusLabelForTest())->toBeNull();
});
