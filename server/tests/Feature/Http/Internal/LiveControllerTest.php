<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\LiveController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    class_alias(Illuminate\Database\Eloquent\Model::class, 'Illuminate\Foundation\Auth\User');
}

class FleetOpsLiveQueryRecorder
{
    public array $calls = [];

    public function whereNotNull(string $column): self
    {
        $this->calls[] = ['whereNotNull', $column];

        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->calls[] = ['whereRaw', trim($sql), $bindings];

        return $this;
    }
}

class FleetOpsLiveMonitorDriverFake extends Driver
{
    public function getAttribute($key)
    {
        return $this->attributes[$key] ?? null;
    }
}

class FleetOpsLiveMonitorVehicleFake extends Vehicle
{
    public function getAttribute($key)
    {
        return $this->attributes[$key] ?? null;
    }
}

function callLiveControllerMethod(string $method, array $arguments = [])
{
    $reflection = new ReflectionMethod(LiveController::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs(new LiveController(), $arguments);
}

test('live viewport bounds are normalized for stable cache keys', function () {
    $request = new Request([
        'bounds' => ['1.234567', '103.876543', '1.345678', '103.987654'],
    ]);

    expect(callLiveControllerMethod('normalizeLiveBounds', [$request]))
        ->toBe([1.2346, 103.8765, 1.3457, 103.9877]);
});

test('invalid live viewport bounds fall back to unbounded queries', function ($bounds) {
    $request = new Request(['bounds' => $bounds]);

    expect(callLiveControllerMethod('normalizeLiveBounds', [$request]))->toBeNull();
})->with([
    'missing coordinate' => [[1, 2, 3]],
    'non numeric'        => [[1, 'west', 3, 4]],
    'invalid latitude'   => [[-91, 103, 1, 104]],
    'invalid longitude'  => [[1, -181, 2, 104]],
    'inverted latitude'  => [[2, 103, 1, 104]],
    'inverted longitude' => [[1, 104, 2, 103]],
]);

test('live viewport limit defaults and clamps', function () {
    expect(callLiveControllerMethod('normalizeLiveLimit', [new Request()]))->toBe(500)
        ->and(callLiveControllerMethod('normalizeLiveLimit', [new Request(['limit' => 25])]))->toBe(25)
        ->and(callLiveControllerMethod('normalizeLiveLimit', [new Request(['limit' => 0])]))->toBe(500)
        ->and(callLiveControllerMethod('normalizeLiveLimit', [new Request(['limit' => 5000])]))->toBe(1000);
});

test('live viewport query avoids spatial constructors with fixed srids', function () {
    $controller = file_get_contents(dirname(__DIR__, 4) . '/src/Http/Controllers/Internal/v1/LiveController.php');

    expect($controller)->toContain('protected function applyLiveLocationGuards')
        ->and($controller)->toContain('protected function applyLiveViewportBounds')
        ->and($controller)->toContain('ST_Y(location) BETWEEN ? AND ?')
        ->and($controller)->toContain('ST_X(location) BETWEEN ? AND ?')
        ->and($controller)->not->toContain('ST_MakeEnvelope')
        ->and($controller)->not->toContain('ST_GeomFromText');
});

test('live controller applies location guards and optional viewport bounds', function () {
    $query = new FleetOpsLiveQueryRecorder();

    callLiveControllerMethod('applyLiveLocationGuards', [$query]);
    callLiveControllerMethod('applyLiveViewportBounds', [$query, [1.1, 103.2, 1.4, 103.9]]);
    callLiveControllerMethod('applyLiveViewportBounds', [$query, null]);

    expect($query->calls)->toBe([
        ['whereNotNull', 'location'],
        [
            'whereRaw',
            'ST_Y(location) BETWEEN -90 AND 90
                AND ST_X(location) BETWEEN -180 AND 180
                AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)',
            [],
        ],
        [
            'whereRaw',
            'ST_Y(location) BETWEEN ? AND ? AND ST_X(location) BETWEEN ? AND ?',
            [1.1, 1.4, 103.2, 103.9],
        ],
    ]);
});

test('live controller builds operations monitor fleet trees', function () {
    $fleetNodes = new Collection([
        'root' => [
            'uuid'              => 'root',
            'parent_fleet_uuid' => null,
            'subfleets'         => [],
        ],
        'child' => [
            'uuid'              => 'child',
            'parent_fleet_uuid' => 'root',
            'subfleets'         => [],
        ],
        'orphan' => [
            'uuid'              => 'orphan',
            'parent_fleet_uuid' => 'missing',
            'subfleets'         => [],
        ],
    ]);

    expect(callLiveControllerMethod('buildOperationsMonitorFleetTree', [$fleetNodes]))->toBe([
        [
            'uuid'              => 'root',
            'parent_fleet_uuid' => null,
            'subfleets'         => [
                [
                    'uuid'              => 'child',
                    'parent_fleet_uuid' => 'root',
                    'subfleets'         => [],
                ],
            ],
        ],
        [
            'uuid'              => 'orphan',
            'parent_fleet_uuid' => 'missing',
            'subfleets'         => [],
        ],
    ]);
});

test('live controller serializes operations monitor drivers and vehicles', function () {
    $updatedAt = now()->subMinute();
    $createdAt = now()->subHour();

    $driver = new FleetOpsLiveMonitorDriverFake();
    $driver->setRawAttributes([
        'uuid'             => 'driver-uuid',
        'public_id'        => 'driver_public',
        'company_uuid'     => 'company-uuid',
        'user_uuid'        => 'user-uuid',
        'vehicle_uuid'     => 'vehicle-uuid',
        'vendor_uuid'      => 'vendor-uuid',
        'current_job_uuid' => 'job-uuid',
        'name'             => 'Jamie Driver',
        'email'            => 'jamie@example.test',
        'phone'            => '+15550001111',
        'photo_url'        => 'https://example.test/photo.jpg',
        'vehicle_name'     => 'Van 9',
        'status'           => 'active',
        'location'         => ['latitude' => 1.3521, 'longitude' => 103.8198],
        'heading'          => '270',
        'altitude'         => '12',
        'speed'            => '42',
        'online'           => 1,
        'updated_at'       => $updatedAt,
        'created_at'       => $createdAt,
    ], true);

    $vehicle = new FleetOpsLiveMonitorVehicleFake();
    $vehicle->setRawAttributes([
        'uuid'             => 'vehicle-uuid',
        'public_id'        => 'vehicle_public',
        'company_uuid'     => 'company-uuid',
        'vendor_uuid'      => 'vendor-uuid',
        'photo_uuid'       => 'photo-uuid',
        'internal_id'      => 'internal-9',
        'name'             => 'Van 9',
        'display_name'     => 'Delivery Van 9',
        'driver_name'      => 'Jamie Driver',
        'plate_number'     => 'SG-1234',
        'serial_number'    => 'SERIAL9',
        'fuel_card_number' => 'FUEL9',
        'vin'              => 'VIN9',
        'make'             => 'Ford',
        'model'            => 'Transit',
        'year'             => '2024',
        'photo_url'        => 'https://example.test/vehicle.jpg',
        'avatar_url'       => 'https://example.test/avatar.jpg',
        'status'           => 'available',
        'location'         => ['latitude' => 1.4, 'longitude' => 103.9],
        'heading'          => '90',
        'altitude'         => '8',
        'speed'            => '35',
        'online'           => true,
        'updated_at'       => $updatedAt,
        'created_at'       => $createdAt,
    ], true);

    $serializedDriver  = callLiveControllerMethod('serializeMonitorDriver', [$driver]);
    $serializedVehicle = callLiveControllerMethod('serializeMonitorVehicle', [$vehicle]);

    expect($serializedDriver)->toMatchArray([
        'id'                    => 'driver-uuid',
        'uuid'                  => 'driver-uuid',
        'public_id'             => 'driver_public',
        'company_uuid'          => 'company-uuid',
        'user_uuid'             => 'user-uuid',
        'vehicle_uuid'          => 'vehicle-uuid',
        'vendor_uuid'           => 'vendor-uuid',
        'current_job_uuid'      => 'job-uuid',
        'name'                  => 'Jamie Driver',
        'email'                 => 'jamie@example.test',
        'phone'                 => '+15550001111',
        'photo_url'             => 'https://example.test/photo.jpg',
        'avatar_url'            => 'https://example.test/photo.jpg',
        'vehicle_name'          => 'Van 9',
        'status'                => 'active',
        'heading'               => 270,
        'altitude'              => 12,
        'speed'                 => 42,
        'online'                => true,
        'assigned_orders_count' => null,
        'meta'                  => ['_index_resource' => true],
        'updated_at'            => $updatedAt,
        'created_at'            => $createdAt,
    ])
        ->and($serializedDriver['location']->getLat())->toBe(1.3521)
        ->and($serializedDriver['location']->getLng())->toBe(103.8198)
        ->and($serializedVehicle)->toMatchArray([
            'id'                    => 'vehicle-uuid',
            'uuid'                  => 'vehicle-uuid',
            'public_id'             => 'vehicle_public',
            'company_uuid'          => 'company-uuid',
            'vendor_uuid'           => 'vendor-uuid',
            'photo_uuid'            => 'photo-uuid',
            'internal_id'           => 'internal-9',
            'name'                  => 'Van 9',
            'display_name'          => 'Delivery Van 9',
            'driver_name'           => 'Jamie Driver',
            'plate_number'          => 'SG-1234',
            'serial_number'         => 'SERIAL9',
            'fuel_card_number'      => 'FUEL9',
            'vin'                   => 'VIN9',
            'make'                  => 'Ford',
            'model'                 => 'Transit',
            'year'                  => '2024',
            'photo_url'             => 'https://example.test/vehicle.jpg',
            'avatar_url'            => 'https://example.test/avatar.jpg',
            'status'                => 'available',
            'heading'               => 90,
            'altitude'              => 8,
            'speed'                 => 35,
            'online'                => true,
            'assigned_orders_count' => null,
            'meta'                  => ['_index_resource' => true],
            'updated_at'            => $updatedAt,
            'created_at'            => $createdAt,
        ])
        ->and($serializedVehicle['location']->getLat())->toBe(1.4)
        ->and($serializedVehicle['location']->getLng())->toBe(103.9);
});
