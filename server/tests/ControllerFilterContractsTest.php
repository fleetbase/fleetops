<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\DeviceController as ApiDeviceController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\DeviceController as InternalDeviceController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\SettingController;
use Fleetbase\FleetOps\Http\Filter\DeviceFilter;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;

class FleetOpsApiDeviceControllerProbe extends ApiDeviceController
{
    public function callInput(Request $request): array
    {
        return $this->input($request);
    }
}

class FleetOpsControllerFilterQuery
{
    public array $calls = [];

    public function with(array $relations): self
    {
        $this->calls[] = ['with', $relations];

        return $this;
    }

    public function withCount(string $relation): self
    {
        $this->calls[] = ['withCount', $relation];

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->calls[] = ['whereNotNull', $column];

        return $this;
    }

    public function search(?string $query): self
    {
        $this->calls[] = ['search', $query];

        return $this;
    }

    public function whereIn(string $column, mixed $values): self
    {
        $this->calls[] = ['whereIn', $column, $values];

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->calls[] = ['whereNull', $column];

        return $this;
    }

    public function where(...$arguments): self
    {
        if (isset($arguments[0]) && is_callable($arguments[0])) {
            $nested = new self();
            $arguments[0]($nested);
            $this->calls[] = ['whereNested', $nested->calls];

            return $this;
        }

        $this->calls[] = ['where', $arguments];

        return $this;
    }

    public function orWhere(...$arguments): self
    {
        $this->calls[] = ['orWhere', $arguments];

        return $this;
    }

    public function orWhereBetween(string $column, array $range): self
    {
        $this->calls[] = ['orWhereBetween', $column, $range];

        return $this;
    }

    public function orWhereNull(string $column): self
    {
        $this->calls[] = ['orWhereNull', $column];

        return $this;
    }

    public function whereBetween(string $column, array $range): self
    {
        $this->calls[] = ['whereBetween', $column, $range];

        return $this;
    }

    public function whereDate(string $column, mixed $date): self
    {
        $this->calls[] = ['whereDate', $column, $date];

        return $this;
    }
}

function fleetopsProtectedMethod(string $class, string $method): ReflectionMethod
{
    $reflection = new ReflectionMethod($class, $method);
    $reflection->setAccessible(true);

    return $reflection;
}

function fleetopsFilterWithBuilder(string $class, FleetOpsControllerFilterQuery $builder): object
{
    $filter = (new ReflectionClass($class))->newInstanceWithoutConstructor();

    foreach ([
        'builder' => $builder,
        'session' => new class {
            public function get(string $key): ?string
            {
                return $key === 'company' ? 'company-uuid' : null;
            }
        },
        'request' => new Request(),
    ] as $property => $value) {
        $reflection = new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($filter, $value);
    }

    return $filter;
}

test('internal device controller query callback applies attachment status and date filters', function () {
    $request = new Request([
        'attachment_state'  => 'attached',
        'device_id'         => 'device-123',
        'serial_number'     => 'serial-456',
        'connection_status' => ['online', 'never_connected'],
        'last_online_at'    => ['2026-01-01', '2026-01-31'],
        'updated_at'        => '2026-02-01',
    ]);
    $query = new FleetOpsControllerFilterQuery();

    InternalDeviceController::onQueryRecord($query, $request);

    expect($query->calls)->toContain(['with', ['telematic', 'warranty', 'attachable']])
        ->and($query->calls)->toContain(['withCount', 'sensors'])
        ->and($query->calls)->toContain(['whereNotNull', 'attachable_uuid'])
        ->and($query->calls)->toContain(['where', ['device_id', 'like', '%device-123%']])
        ->and($query->calls)->toContain(['where', ['serial_number', 'like', '%serial-456%']])
        ->and($query->calls[0])->toBe(['with', ['telematic', 'warranty', 'attachable']])
        ->and($query->calls[1])->toBe(['withCount', 'sensors']);

    $nestedStatus = collect($query->calls)->first(fn ($call) => $call[0] === 'whereNested');
    expect($nestedStatus[1])->toHaveCount(2)
        ->and($nestedStatus[1][0][0])->toBe('orWhere')
        ->and($nestedStatus[1][0][1][0])->toBe('last_online_at')
        ->and($nestedStatus[1][1])->toBe(['orWhereNull', 'last_online_at']);

    expect(collect($query->calls)->where(0, 'whereBetween')->values())->toHaveCount(1)
        ->and(collect($query->calls)->where(0, 'whereDate')->values())->toHaveCount(1);
});

test('internal device controller find callback eager loads expected relationships', function () {
    $query = new FleetOpsControllerFilterQuery();

    InternalDeviceController::onFindRecord($query, new Request());

    expect($query->calls)->toBe([
        ['with', ['telematic', 'warranty', 'attachable']],
        ['withCount', 'sensors'],
    ]);
});

test('device filter records scalar list attachment and connection filters', function () {
    $query  = new FleetOpsControllerFilterQuery();
    $filter = fleetopsFilterWithBuilder(DeviceFilter::class, $query);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->query('gps tracker');
    $filter->status('active,inactive');
    $filter->deviceId('device-1');
    $filter->type(['gps', 'sensor']);
    $filter->serialNumber('serial-1');
    $filter->provider('teltonika');
    $filter->warrantyUuid('warranty-uuid');
    $filter->attachableType('fleet-ops:vehicle');
    $filter->attachableUuid('vehicle-uuid');
    $filter->connectionStatus(['recently_offline', 'offline', 'long_offline', 'ignored']);
    $filter->attachmentState('unattached');

    expect($query->calls)->toContain(['where', ['company_uuid', 'company-uuid']])
        ->and($query->calls)->toContain(['search', 'gps tracker'])
        ->and($query->calls)->toContain(['whereIn', 'status', ['active', 'inactive']])
        ->and($query->calls)->toContain(['where', ['device_id', 'like', '%device-1%']])
        ->and($query->calls)->toContain(['whereIn', 'type', ['gps', 'sensor']])
        ->and($query->calls)->toContain(['where', ['serial_number', 'like', '%serial-1%']])
        ->and($query->calls)->toContain(['where', ['provider', 'teltonika']])
        ->and($query->calls)->toContain(['where', ['warranty_uuid', 'warranty-uuid']])
        ->and($query->calls)->toContain(['where', ['attachable_type', 'fleet-ops:vehicle']])
        ->and($query->calls)->toContain(['where', ['attachable_uuid', 'vehicle-uuid']])
        ->and($query->calls)->toContain(['whereNull', 'attachable_uuid']);

    $connectionStatus = collect($query->calls)->first(fn ($call) => $call[0] === 'whereNested');

    expect($connectionStatus[1])->toHaveCount(3)
        ->and($connectionStatus[1][0][0])->toBe('orWhereBetween')
        ->and($connectionStatus[1][1][0])->toBe('orWhereBetween')
        ->and($connectionStatus[1][2][0])->toBe('orWhere');
});

test('api device controller input maps coordinates and clears blank attachables', function () {
    $controller = new FleetOpsApiDeviceControllerProbe();

    $input = $controller->callInput(new Request([
        'device_id'       => 'device-123',
        'type'            => 'gps',
        'latitude'        => '1.3521',
        'longitude'       => '103.8198',
        'attachable'      => '',
        'attachable_type' => 'vehicle',
        'online'          => true,
    ]));

    expect($input['device_id'])->toBe('device-123')
        ->and($input['type'])->toBe('gps')
        ->and($input['online'])->toBeTrue()
        ->and($input['last_position'])->toBeInstanceOf(Point::class)
        ->and($input['last_position']->getLat())->toBe(1.3521)
        ->and($input['last_position']->getLng())->toBe(103.8198)
        ->and($input['attachable_type'])->toBeNull()
        ->and($input['attachable_uuid'])->toBeNull();
});

test('setting controller normalizes map and tracking alert settings', function () {
    $controller  = new SettingController();
    $mapMethod   = fleetopsProtectedMethod(SettingController::class, 'normalizeCompanyMapSettings');
    $alertMethod = fleetopsProtectedMethod(SettingController::class, 'normalizeTrackingAlertSettings');

    $mapSettings = $mapMethod->invoke($controller, [
        'mapProvider'                => 'invalid',
        'googleMapsMapType'          => 'moon',
        'showGoogleMapsTrafficLayer' => 'yes',
        'showGoogleMapsTransitLayer' => '0',
    ]);

    $alerts = $alertMethod->invoke($controller, [
        'late_departures' => [
            'enabled'              => false,
            'grace_period_minutes' => -5,
        ],
        'route_deviations' => [
            'distance_threshold_meters' => '750',
        ],
        'prolonged_stoppages' => [
            'enabled'                    => true,
            'duration_threshold_minutes' => '45',
        ],
    ]);

    expect($mapSettings)->toMatchArray([
        'mapProvider'                => 'leaflet',
        'googleMapsMapType'          => 'roadmap',
        'showGoogleMapsTrafficLayer' => true,
        'showGoogleMapsTransitLayer' => false,
    ])
        ->and($alerts)->toMatchArray([
            'late_departures' => [
                'enabled'              => false,
                'grace_period_minutes' => 0,
            ],
            'route_deviations' => [
                'enabled'                   => true,
                'distance_threshold_meters' => 750,
            ],
            'prolonged_stoppages' => [
                'enabled'                    => true,
                'duration_threshold_minutes' => 45,
            ],
        ]);
});
