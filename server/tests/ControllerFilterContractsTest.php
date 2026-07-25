<?php

use Fleetbase\FleetOps\Auth\Directives\CustomerContacts;
use Fleetbase\FleetOps\Auth\Directives\CustomerListPlaces;
use Fleetbase\FleetOps\Auth\Directives\CustomerOrders;
use Fleetbase\FleetOps\Auth\Directives\CustomerPlaces;
use Fleetbase\FleetOps\Auth\Directives\CustomerUser;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\DeviceController as ApiDeviceController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\DeviceController as InternalDeviceController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\DeviceEventController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\SettingController;
use Fleetbase\FleetOps\Http\Filter\ContactFilter;
use Fleetbase\FleetOps\Http\Filter\DeviceEventFilter;
use Fleetbase\FleetOps\Http\Filter\DeviceFilter;
use Fleetbase\FleetOps\Http\Filter\EntityFilter;
use Fleetbase\FleetOps\Http\Filter\EquipmentFilter;
use Fleetbase\FleetOps\Http\Filter\FleetFilter;
use Fleetbase\FleetOps\Http\Filter\FuelProviderConnectionFilter;
use Fleetbase\FleetOps\Http\Filter\FuelProviderSyncRunFilter;
use Fleetbase\FleetOps\Http\Filter\FuelProviderTransactionFilter;
use Fleetbase\FleetOps\Http\Filter\FuelReportFilter;
use Fleetbase\FleetOps\Http\Filter\IssueFilter;
use Fleetbase\FleetOps\Http\Filter\OrderConfigFilter;
use Fleetbase\FleetOps\Http\Filter\PartFilter;
use Fleetbase\FleetOps\Http\Filter\PayloadFilter;
use Fleetbase\FleetOps\Http\Filter\PlaceFilter;
use Fleetbase\FleetOps\Http\Filter\PositionFilter;
use Fleetbase\FleetOps\Http\Filter\PurchaseRateFilter;
use Fleetbase\FleetOps\Http\Filter\SensorFilter;
use Fleetbase\FleetOps\Http\Filter\ServiceAreaFilter;
use Fleetbase\FleetOps\Http\Filter\ServiceRateFilter;
use Fleetbase\FleetOps\Http\Filter\TrackingNumberFilter;
use Fleetbase\FleetOps\Http\Filter\TrackingStatusFilter;
use Fleetbase\FleetOps\Http\Filter\VehicleFilter;
use Fleetbase\FleetOps\Http\Filter\VendorFilter;
use Fleetbase\FleetOps\Http\Filter\WorkOrderFilter;
use Fleetbase\FleetOps\Http\Filter\ZoneFilter;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
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

    public function whereHas(string $relation, callable $callback): self
    {
        $nested = new self();
        $callback($nested);
        $this->calls[] = ['whereHas', $relation, $nested->calls];

        return $this;
    }

    public function orWhereHas(string $relation, callable $callback): self
    {
        $nested = new self();
        $callback($nested);
        $this->calls[] = ['orWhereHas', $relation, $nested->calls];

        return $this;
    }

    public function whereDoesntHave(string $relation): self
    {
        $this->calls[] = ['whereDoesntHave', $relation];

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->calls[] = ['whereNotNull', $column];

        return $this;
    }

    public function orWhereNotNull(string $column): self
    {
        $this->calls[] = ['orWhereNotNull', $column];

        return $this;
    }

    public function search(?string $query): self
    {
        $this->calls[] = ['search', $query];

        return $this;
    }

    public function searchWhere(string|array $columns, ?string $query): self
    {
        $this->calls[] = ['searchWhere', $columns, $query];

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

    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->calls[] = ['whereRaw', $sql, $bindings];

        return $this;
    }

    public function within(string $column, mixed $geometry): self
    {
        $this->calls[] = ['within', $column, $geometry];

        return $this;
    }
}

class FleetOpsCustomerOrdersBuilderRecorder extends Illuminate\Database\Eloquent\Builder
{
    public array $calls = [];

    public function __construct()
    {
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_callable($column)) {
            $nested = new self();
            $column($nested);
            $this->calls[] = ['whereNested', $nested->calls];

            return $this;
        }

        $arguments = func_get_args();
        if (count($arguments) > 2 && $value === null) {
            $arguments = array_slice($arguments, 0, 2);
        }

        $this->calls[] = ['where', $arguments];

        return $this;
    }

    public function orWhereHas($relation, ?Closure $callback = null, $operator = '>=', $count = 1)
    {
        $nested = new self();
        $callback($nested);
        $this->calls[] = ['orWhereHas', $relation, $nested->calls];

        return $this;
    }
}

class FleetOpsSensorFilterProbe extends SensorFilter
{
    public array $resolvedRelations = [];

    protected function resolvePublicRelationUuids(string $modelClass, string $identifier, bool $allowUuid = false)
    {
        $this->resolvedRelations[] = [$modelClass, $identifier, $allowUuid];

        return [$identifier . '-uuid'];
    }
}

class FleetOpsFuelProviderTransactionFilterProbe extends FuelProviderTransactionFilter
{
    public array $resolvedRelations = [];

    protected function resolvePublicRelationUuids(string $modelClass, string $identifier, bool $allowUuid = false)
    {
        $this->resolvedRelations[] = [$modelClass, $identifier, $allowUuid];

        return [$identifier . '-uuid'];
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

test('device event filter records event relation processed and date filters', function () {
    $query  = new FleetOpsControllerFilterQuery();
    $filter = fleetopsFilterWithBuilder(DeviceEventFilter::class, $query);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->query('ignition');
    $filter->eventType('harsh');
    $filter->provider('samsara');
    $filter->code('ALERT');
    $filter->severity('warning,critical');
    $filter->processed(['processed', 'unprocessed', 'ignored']);
    $filter->telematic('telematic-public');
    $filter->deviceUuid('device-public');
    $filter->occurredAt(['2026-01-01', '2026-01-31']);
    $filter->createdAt('2026-02-01');
    $filter->updatedAt(['2026-03-01', '2026-03-31']);

    expect($query->calls[0][0])->toBe('whereNested')
        ->and($query->calls[0][1])->toContain(['where', ['company_uuid', 'company-uuid']])
        ->and($query->calls)->toContain(['where', ['company_uuid', 'company-uuid']])
        ->and($query->calls)->toContain(['search', 'ignition'])
        ->and($query->calls)->toContain(['where', ['event_type', 'like', '%harsh%']])
        ->and($query->calls)->toContain(['where', ['provider', 'like', '%samsara%']])
        ->and($query->calls)->toContain(['where', ['code', 'like', '%ALERT%']])
        ->and($query->calls)->toContain(['whereIn', 'severity', ['warning', 'critical']]);

    $processed = collect($query->calls)->first(fn ($call) => $call[0] === 'whereNested' && collect($call[1])->contains(fn ($nested) => $nested[0] === 'orWhereNotNull'));
    expect($processed[1])->toContain(['orWhereNotNull', 'processed_at'])
        ->and($processed[1])->toContain(['orWhereNull', 'processed_at']);

    $telematic = collect($query->calls)->first(fn ($call) => $call[0] === 'whereHas' && $call[1] === 'device');
    expect($telematic[2][0])->toBe(['whereHas', 'telematic', [
        ['where', ['uuid', 'telematic-public']],
        ['orWhere', ['public_id', 'telematic-public']],
    ]]);

    $device = collect($query->calls)->filter(fn ($call) => $call[0] === 'whereHas' && $call[1] === 'device')->values()[1];
    expect($device[2])->toBe([
        ['where', ['uuid', 'device-public']],
        ['orWhere', ['public_id', 'device-public']],
    ]);

    expect(collect($query->calls)->where(0, 'whereBetween')->values())->toHaveCount(2)
        ->and(collect($query->calls)->where(0, 'whereDate')->values())->toHaveCount(1);
});

test('device event controller query scopes device relation telematic and processed states', function () {
    $query = new FleetOpsControllerFilterQuery();

    DeviceEventController::onQueryRecord($query, Request::create('/device-events', 'GET', [
        'telematic' => 'telematic-uuid',
        'processed' => 'processed,unprocessed,ignored',
    ]));

    expect($query->calls[0])->toBe(['with', ['device.telematic']]);

    $telematic = collect($query->calls)->first(fn ($call) => $call[0] === 'whereHas' && $call[1] === 'device');
    expect($telematic[2])->toBe([
        ['where', ['telematic_uuid', 'telematic-uuid']],
    ]);

    $processed = collect($query->calls)->first(fn ($call) => $call[0] === 'whereNested');
    expect($processed[1])->toBe([
        ['orWhereNotNull', 'processed_at'],
        ['orWhereNull', 'processed_at'],
    ]);
});

test('device event controller skips processed scope when states are empty', function () {
    $query = new FleetOpsControllerFilterQuery();

    DeviceEventController::onQueryRecord($query, Request::create('/device-events', 'GET', [
        'processed' => [],
    ]));

    expect($query->calls)->toBe([
        ['with', ['device.telematic']],
    ]);
});

test('vehicle filter records identity relationship fleet and telematic filters', function () {
    $query  = new FleetOpsControllerFilterQuery();
    $filter = fleetopsFilterWithBuilder(VehicleFilter::class, $query);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->query('van');
    $filter->display_name('sprinter');
    $filter->vin('vin-123');
    $filter->publicId('vehicle-public');
    $filter->plateNumber('ABC-123');
    $filter->vehicleMake('Mercedes');
    $filter->vehicleModel('Sprinter');
    $filter->vehicleYear('2026');
    $filter->driver('unassigned');
    $filter->vendor('vendor-uuid');
    $filter->driverUuid('driver-uuid');
    $filter->fleet('fleet-uuid');
    $filter->assignedFleet('false');
    $filter->telematicUuid('telematic-uuid');
    $filter->createdAt(['2026-01-01', '2026-01-31']);
    $filter->updatedAt('2026-02-01');

    expect($query->calls)->toContain(['where', ['company_uuid', 'company-uuid']])
        ->and($query->calls)->toContain(['search', 'van'])
        ->and($query->calls)->toContain(['searchWhere', ['year', 'make', 'model', 'plate_number'], 'sprinter'])
        ->and($query->calls)->toContain(['searchWhere', 'vin', 'vin-123'])
        ->and($query->calls)->toContain(['searchWhere', 'public_id', 'vehicle-public'])
        ->and($query->calls)->toContain(['searchWhere', 'plate_number', 'ABC-123'])
        ->and($query->calls)->toContain(['searchWhere', 'make', 'Mercedes'])
        ->and($query->calls)->toContain(['searchWhere', 'model', 'Sprinter'])
        ->and($query->calls)->toContain(['searchWhere', 'year', '2026'])
        ->and($query->calls)->toContain(['whereDoesntHave', 'driver'])
        ->and($query->calls)->toContain(['whereDoesntHave', 'fleets']);

    expect(collect($query->calls)->where(0, 'whereHas')->pluck(1)->all())->toContain('vendor', 'driver', 'fleets', 'devices')
        ->and(collect($query->calls)->where(0, 'whereBetween')->values())->toHaveCount(1)
        ->and(collect($query->calls)->where(0, 'whereDate')->values())->toHaveCount(1);
});

test('fleet filter records hierarchy relationship scalar status and date filters', function () {
    $query  = new FleetOpsControllerFilterQuery();
    $filter = fleetopsFilterWithBuilder(FleetFilter::class, $query);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->query('dispatch');
    $filter->parentsOnly(true);
    $filter->parentsOnly(false);
    $filter->serviceArea('service-area-uuid');
    $filter->zone('zone-uuid');
    $filter->parentFleet('parent-fleet-uuid');
    $filter->vendor('vendor-uuid');
    $filter->publicId('fleet-public');
    $filter->task('delivery');
    $filter->name('North Fleet');
    $filter->status(['active', 'inactive']);
    $filter->createdAt('2026-01-01');
    $filter->updatedAt(['2026-02-01', '2026-02-28']);

    expect($query->calls)->toContain(['where', ['company_uuid', 'company-uuid']])
        ->and($query->calls)->toContain(['with', ['serviceArea', 'zone']])
        ->and($query->calls)->toContain(['whereNull', 'parent_fleet_uuid'])
        ->and($query->calls)->toContain(['searchWhere', 'parent_fleet_uuid', 'parent-fleet-uuid'])
        ->and($query->calls)->toContain(['searchWhere', 'public_id', 'fleet-public'])
        ->and($query->calls)->toContain(['searchWhere', 'task', 'delivery'])
        ->and($query->calls)->toContain(['searchWhere', 'name', 'North Fleet'])
        ->and($query->calls)->toContain(['whereIn', 'status', ['active', 'inactive']]);

    expect(collect($query->calls)->where(0, 'whereHas')->pluck(1)->all())->toContain('serviceArea', 'zone', 'parent_fleet', 'vendor')
        ->and(collect($query->calls)->where(0, 'whereDate')->values())->toHaveCount(1)
        ->and(collect($query->calls)->where(0, 'whereBetween')->values())->toHaveCount(1);
});

test('service rate and zone filters scope company service area and zone relationships', function () {
    $serviceRateQuery  = new FleetOpsControllerFilterQuery();
    $serviceRateFilter = fleetopsFilterWithBuilder(ServiceRateFilter::class, $serviceRateQuery);

    $serviceRateFilter->queryForInternal();
    $serviceRateFilter->queryForPublic();
    $serviceRateFilter->serviceArea('service-area-uuid');
    $serviceRateFilter->zone('zone-uuid');

    expect($serviceRateQuery->calls)->toContain(['where', ['company_uuid', 'company-uuid']]);

    $serviceRateRelations = collect($serviceRateQuery->calls)->where(0, 'whereHas')->values();
    expect($serviceRateRelations)->toHaveCount(2)
        ->and($serviceRateRelations[0])->toBe(['whereHas', 'serviceArea', [
            ['where', ['uuid', 'service-area-uuid']],
        ]])
        ->and($serviceRateRelations[1])->toBe(['whereHas', 'zone', [
            ['where', ['uuid', 'zone-uuid']],
        ]]);

    $zoneQuery  = new FleetOpsControllerFilterQuery();
    $zoneFilter = fleetopsFilterWithBuilder(ZoneFilter::class, $zoneQuery);

    $zoneFilter->queryForInternal();
    $zoneFilter->queryForPublic();
    $zoneFilter->serviceArea('service-area-public');

    expect($zoneQuery->calls)->toContain(['where', ['company_uuid', 'company-uuid']]);

    $serviceAreaScope = collect($zoneQuery->calls)->first(fn ($call) => $call[0] === 'whereNested');
    expect($serviceAreaScope[1])->toBe([
        ['where', ['service_area_uuid', 'service-area-public']],
        ['orWhereHas', 'serviceArea', [
            ['where', ['public_id', 'service-area-public']],
        ]],
    ]);
});

test('position filter records company query and created date scopes', function () {
    $query  = new FleetOpsControllerFilterQuery();
    $filter = fleetopsFilterWithBuilder(PositionFilter::class, $query);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->query('vehicle');
    $filter->createdAt(['2026-01-01', '2026-01-31']);
    $filter->createdAt('2026-02-01');

    expect($query->calls)->toContain(['where', ['company_uuid', 'company-uuid']])
        ->and($query->calls)->toContain(['search', 'vehicle'])
        ->and(collect($query->calls)->where(0, 'whereBetween')->values())->toHaveCount(1)
        ->and(collect($query->calls)->where(0, 'whereDate')->values())->toHaveCount(1);
});

test('fuel provider and work order filters record company search and status scopes', function () {
    $connectionQuery  = new FleetOpsControllerFilterQuery();
    $connectionFilter = fleetopsFilterWithBuilder(FuelProviderConnectionFilter::class, $connectionQuery);

    $connectionFilter->queryForInternal();
    $connectionFilter->query('fuelx');
    $connectionFilter->provider('petro-app');
    $connectionFilter->status('active');
    $connectionFilter->environment('sandbox');

    expect($connectionQuery->calls)->toBe([
        ['where', ['company_uuid', 'company-uuid']],
        ['search', 'fuelx'],
        ['where', ['provider', 'petro-app']],
        ['where', ['status', 'active']],
        ['where', ['environment', 'sandbox']],
    ]);

    $syncQuery  = new FleetOpsControllerFilterQuery();
    $syncFilter = fleetopsFilterWithBuilder(FuelProviderSyncRunFilter::class, $syncQuery);

    $syncFilter->queryForInternal();
    $syncFilter->provider('petro-app');
    $syncFilter->status('completed');
    $syncFilter->connection('connection-uuid');

    expect($syncQuery->calls)->toBe([
        ['where', ['company_uuid', 'company-uuid']],
        ['where', ['provider', 'petro-app']],
        ['where', ['status', 'completed']],
        ['where', ['fuel_provider_connection_uuid', 'connection-uuid']],
    ]);

    $workOrderQuery  = new FleetOpsControllerFilterQuery();
    $workOrderFilter = fleetopsFilterWithBuilder(WorkOrderFilter::class, $workOrderQuery);

    $workOrderFilter->queryForInternal();
    $workOrderFilter->queryForPublic();
    $workOrderFilter->query('repair');

    expect($workOrderQuery->calls)->toBe([
        ['where', ['company_uuid', 'company-uuid']],
        ['where', ['company_uuid', 'company-uuid']],
        ['search', 'repair'],
    ]);
});

test('simple company scoped filters record internal and public company constraints', function () {
    foreach ([EntityFilter::class, OrderConfigFilter::class, PayloadFilter::class, PurchaseRateFilter::class, ServiceAreaFilter::class] as $filterClass) {
        $query  = new FleetOpsControllerFilterQuery();
        $filter = fleetopsFilterWithBuilder($filterClass, $query);

        $filter->queryForInternal();
        $filter->queryForPublic();

        expect($query->calls)->toBe([
            ['where', ['company_uuid', 'company-uuid']],
            ['where', ['company_uuid', 'company-uuid']],
        ]);
    }
});

test('customer directives scope user contacts and places by session or request identity', function () {
    app('session.store')->forget('user');
    session(['user' => null]);
    app()->instance('request', Request::create('/customer-directives', 'GET', ['customer' => 'request-user-uuid']));

    $contacts = new FleetOpsCustomerOrdersBuilderRecorder();
    expect((new CustomerContacts())->apply($contacts))->toBe($contacts)
        ->and($contacts->calls)->toBe([
            ['where', [['type' => 'customer', 'user_uuid' => 'request-user-uuid']]],
        ]);

    $user = new FleetOpsCustomerOrdersBuilderRecorder();
    expect((new CustomerUser())->apply($user))->toBe($user)
        ->and($user->calls)->toBe([
            ['where', [['uuid' => 'request-user-uuid']]],
        ]);

    foreach ([CustomerPlaces::class, CustomerListPlaces::class] as $directive) {
        $builder = new FleetOpsCustomerOrdersBuilderRecorder();
        expect((new $directive())->apply($builder))->toBe($builder)
            ->and($builder->calls)->toBe([
                ['whereNested', [
                    ['where', ['owner_uuid', 'request-user-uuid']],
                ]],
            ]);
    }

    session(['user' => 'session-user-uuid']);

    $sessionBuilder = new FleetOpsCustomerOrdersBuilderRecorder();
    (new CustomerUser())->apply($sessionBuilder);

    expect($sessionBuilder->calls)->toBe([
        ['where', [['uuid' => 'session-user-uuid']]],
    ]);

    session(['user' => null]);
});

test('customer orders directive scopes direct customer and authenticatable user matches', function () {
    app('session.store')->forget('user');
    session(['user' => null]);

    app()->instance('request', Request::create('/customer-orders', 'GET', ['customer' => 'customer-user-uuid']));

    $builder = new FleetOpsCustomerOrdersBuilderRecorder();
    $result  = (new CustomerOrders())->apply($builder);

    expect($result)->toBe($builder)
        ->and($builder->calls)->toBe([
            ['whereNested', [
                ['where', ['customer_uuid', 'customer-user-uuid']],
                ['orWhereHas', 'authenticatableCustomer', [
                    ['where', ['user_uuid', 'customer-user-uuid']],
                ]],
            ]],
        ]);

    session(['user' => 'session-user-uuid']);

    $sessionBuilder = new FleetOpsCustomerOrdersBuilderRecorder();
    (new CustomerOrders())->apply($sessionBuilder);

    expect($sessionBuilder->calls[0][1][0])->toBe(['where', ['customer_uuid', 'session-user-uuid']]);

    session(['user' => null]);
});

test('issue filter records identity relationship priority status and date filters', function () {
    $query  = new FleetOpsControllerFilterQuery();
    $filter = fleetopsFilterWithBuilder(IssueFilter::class, $query);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->query('flat tire');
    $filter->publicId('issue-public');
    $filter->priority('high,urgent');
    $filter->status('open,assigned');
    $filter->assignee('11111111-1111-4111-8111-111111111111');
    $filter->reporter('contact_abc1234');
    $filter->driver('Driver Name');
    $filter->vehicle('22222222-2222-4222-8222-222222222222');
    $filter->createdAt(['2026-01-01', '2026-01-31']);
    $filter->updatedAt('2026-02-01');

    expect($query->calls)->toContain(['where', ['company_uuid', 'company-uuid']])
        ->and($query->calls)->toContain(['search', 'flat tire'])
        ->and($query->calls)->toContain(['searchWhere', 'public_id', 'issue-public'])
        ->and($query->calls)->toContain(['whereIn', 'priority', ['high', 'urgent']])
        ->and($query->calls)->toContain(['whereIn', 'status', ['open', 'assigned']]);

    $relations = collect($query->calls)->where(0, 'whereHas')->values();

    expect($relations->pluck(1)->all())->toBe(['assignedTo', 'reportedBy', 'driver', 'vehicle'])
        ->and($relations[0][2])->toBe([['where', ['uuid', '11111111-1111-4111-8111-111111111111']]])
        ->and($relations[1][2])->toBe([['where', ['public_id', 'contact_abc1234']]])
        ->and($relations[2][2])->toBe([['search', 'Driver Name']])
        ->and($relations[3][2])->toBe([['where', ['uuid', '22222222-2222-4222-8222-222222222222']]])
        ->and(collect($query->calls)->where(0, 'whereBetween')->values())->toHaveCount(1)
        ->and(collect($query->calls)->where(0, 'whereDate')->values())->toHaveCount(1);

    $scalarQuery  = new FleetOpsControllerFilterQuery();
    $scalarFilter = fleetopsFilterWithBuilder(IssueFilter::class, $scalarQuery);

    $scalarFilter->priority('low');
    $scalarFilter->status([]);

    expect($scalarQuery->calls)->toBe([
        ['where', ['priority', 'low']],
    ]);
});

test('place filter records scalar address date and coordinate filters', function () {
    $query  = new FleetOpsControllerFilterQuery();
    $filter = fleetopsFilterWithBuilder(PlaceFilter::class, $query);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->query('warehouse');
    $filter->publicId('place-public');
    $filter->id('place-id');
    $filter->postalCode('12345');
    $filter->phone('+6555550000');
    $filter->city('Singapore');
    $filter->neighborhood('Central');
    $filter->state('SG');
    $filter->name('Depot');
    $filter->address('1 Test Street');
    $filter->country('SG');
    $filter->createdAt(['2026-01-01', '2026-01-31']);
    $filter->updatedAt('2026-02-01');
    $filter->within(['latitude' => 1.3521, 'longitude' => 103.8198, 'radius' => 11.132]);
    $filter->nearby(['latitude' => 1.3, 'longitude' => 103.8]);

    expect($query->calls)->toContain(['where', ['company_uuid', 'company-uuid']])
        ->and($query->calls)->toContain(['search', 'warehouse'])
        ->and($query->calls)->toContain(['searchWhere', 'public_id', 'place-public'])
        ->and($query->calls)->toContain(['searchWhere', 'public_id', 'place-id'])
        ->and($query->calls)->toContain(['orWhere', ['uuid', 'place-id']])
        ->and($query->calls)->toContain(['searchWhere', 'postal_code', '12345'])
        ->and($query->calls)->toContain(['searchWhere', 'phone', '+6555550000'])
        ->and($query->calls)->toContain(['searchWhere', 'city', 'Singapore'])
        ->and($query->calls)->toContain(['searchWhere', 'neighborhood', 'Central'])
        ->and($query->calls)->toContain(['searchWhere', 'province', 'SG'])
        ->and($query->calls)->toContain(['searchWhere', 'name', 'Depot'])
        ->and($query->calls)->toContain(['searchWhere', ['street1', 'street2'], '1 Test Street'])
        ->and($query->calls)->toContain(['where', ['country', 'SG']])
        ->and(collect($query->calls)->where(0, 'whereBetween')->values())->toHaveCount(1)
        ->and(collect($query->calls)->where(0, 'whereDate')->values())->toHaveCount(1);

    $spatialCalls = collect($query->calls)->where(0, 'whereRaw')->values();

    expect($spatialCalls)->toHaveCount(2)
        ->and($spatialCalls[0][1])->toBe('ST_Within(location, ST_Buffer(ST_GeomFromText(?), ?))')
        ->and($spatialCalls[0][2][0])->toBe('POINT(103.8198 1.3521)')
        ->and($spatialCalls[0][2][1])->toBe(0.1)
        ->and($spatialCalls[1][2][1])->toBe(5 / 111.32);
});

test('vendor filter records company scalar status and date filters', function () {
    $query  = new FleetOpsControllerFilterQuery();
    $filter = fleetopsFilterWithBuilder(VendorFilter::class, $query);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->query('repair shop');
    $filter->internalId('vendor-internal');
    $filter->publicId('vendor_public');
    $filter->type('maintenance');
    $filter->phone('+15551234567');
    $filter->email('vendor@example.test');
    $filter->websiteUrl('https://vendor.example.test');
    $filter->country('US');
    $filter->status('active,inactive');
    $filter->address('place-uuid');
    $filter->createdAt('2026-01-01');
    $filter->updatedAt(['2026-02-01', '2026-02-28']);

    expect($query->calls)->toContain(['where', ['company_uuid', 'company-uuid']])
        ->and($query->calls)->toContain(['search', 'repair shop'])
        ->and($query->calls)->toContain(['searchWhere', 'internal_id', 'vendor-internal'])
        ->and($query->calls)->toContain(['searchWhere', 'public_id', 'vendor_public'])
        ->and($query->calls)->toContain(['searchWhere', 'type', 'maintenance'])
        ->and($query->calls)->toContain(['searchWhere', 'phone', '+15551234567'])
        ->and($query->calls)->toContain(['searchWhere', 'email', 'vendor@example.test'])
        ->and($query->calls)->toContain(['searchWhere', 'website_url', 'https://vendor.example.test'])
        ->and($query->calls)->toContain(['searchWhere', 'country', 'US'])
        ->and($query->calls)->toContain(['whereIn', 'status', ['active', 'inactive']])
        ->and($query->calls)->toContain(['searchWhere', 'place_uuid', 'place-uuid'])
        ->and(collect($query->calls)->where(0, 'whereDate')->values())->toHaveCount(1)
        ->and(collect($query->calls)->where(0, 'whereBetween')->values())->toHaveCount(1);
});

test('sensor filter records relation scalar status and date filters', function () {
    $query  = new FleetOpsControllerFilterQuery();
    $filter = fleetopsFilterWithBuilder(FleetOpsSensorFilterProbe::class, $query);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->query('temperature');
    $filter->type('temperature,humidity');
    $filter->sensorType(['door']);
    $filter->status('online,offline');
    $filter->device('device-public');
    $filter->deviceUuid('device-other');
    $filter->telematic('telematic-public');
    $filter->telematicUuid('telematic-other');
    $filter->warrantyUuid('warranty-uuid');
    $filter->sensorableType('fleet-ops:vehicle');
    $filter->serialNumber('serial-1');
    $filter->imei('imei-1');
    $filter->lastReadingAt(['2026-01-01', '2026-01-31']);
    $filter->createdAt('2026-02-01');
    $filter->updatedAt(['2026-03-01', '2026-03-31']);

    expect($query->calls)->toContain(['where', ['company_uuid', 'company-uuid']])
        ->and($query->calls)->toContain(['search', 'temperature'])
        ->and($query->calls)->toContain(['whereIn', 'type', ['temperature', 'humidity']])
        ->and($query->calls)->toContain(['whereIn', 'type', ['door']])
        ->and($query->calls)->toContain(['whereIn', 'status', ['online', 'offline']])
        ->and($query->calls)->toContain(['whereIn', 'device_uuid', ['device-public-uuid']])
        ->and($query->calls)->toContain(['whereIn', 'device_uuid', ['device-other-uuid']])
        ->and($query->calls)->toContain(['whereIn', 'telematic_uuid', ['telematic-public-uuid']])
        ->and($query->calls)->toContain(['whereIn', 'telematic_uuid', ['telematic-other-uuid']])
        ->and($query->calls)->toContain(['where', ['warranty_uuid', 'warranty-uuid']])
        ->and($query->calls)->toContain(['where', ['sensorable_type', 'fleet-ops:vehicle']])
        ->and($query->calls)->toContain(['where', ['serial_number', 'like', '%serial-1%']])
        ->and($query->calls)->toContain(['where', ['imei', 'like', '%imei-1%']])
        ->and(collect($query->calls)->where(0, 'whereBetween')->values())->toHaveCount(2)
        ->and(collect($query->calls)->where(0, 'whereDate')->values())->toHaveCount(1)
        ->and($filter->resolvedRelations)->toHaveCount(4);
});

test('fuel report filter records identity status metadata and date filters', function () {
    $query  = new FleetOpsControllerFilterQuery();
    $filter = fleetopsFilterWithBuilder(FuelReportFilter::class, $query);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->query('fuel');
    $filter->publicId('fuel-report-public');
    $filter->volume('10');
    $filter->odometer('1000');
    $filter->reporter('11111111-1111-4111-8111-111111111111');
    $filter->driver('driver_abc1234');
    $filter->vehicle('Van Name');
    $filter->createdAt(['2026-01-01', '2026-01-31']);
    $filter->updatedAt('2026-02-01');
    $filter->status('draft,submitted');
    $filter->source('mobile');
    $filter->provider('shell');

    expect($query->calls)->toContain(['where', ['company_uuid', 'company-uuid']])
        ->and($query->calls)->toContain(['search', 'fuel'])
        ->and($query->calls)->toContain(['searchWhere', 'public_id', 'fuel-report-public'])
        ->and($query->calls)->toContain(['searchWhere', 'volume', '10'])
        ->and($query->calls)->toContain(['searchWhere', 'odometer', '1000'])
        ->and($query->calls)->toContain(['whereIn', 'status', ['draft', 'submitted']])
        ->and($query->calls)->toContain(['where', ['meta->source', 'mobile']])
        ->and($query->calls)->toContain(['where', ['meta->provider', 'shell']])
        ->and(collect($query->calls)->where(0, 'whereBetween')->values())->toHaveCount(1)
        ->and(collect($query->calls)->where(0, 'whereDate')->values())->toHaveCount(1);

    $relations = collect($query->calls)->where(0, 'whereHas')->values();

    expect($relations->pluck(1)->all())->toBe(['reportedBy', 'driver', 'vehicle'])
        ->and($relations[0][2])->toBe([['where', ['uuid', '11111111-1111-4111-8111-111111111111']]])
        ->and($relations[1][2])->toBe([['where', ['public_id', 'driver_abc1234']]])
        ->and($relations[2][2])->toBe([['search', 'Van Name']]);

    $scalarQuery  = new FleetOpsControllerFilterQuery();
    $scalarFilter = fleetopsFilterWithBuilder(FuelReportFilter::class, $scalarQuery);
    $scalarFilter->status('submitted');

    expect($scalarQuery->calls)->toBe([
        ['where', ['status', 'submitted']],
    ]);
});

test('tracking status filter records tracking number order and entity relations', function () {
    $query  = new FleetOpsControllerFilterQuery();
    $filter = fleetopsFilterWithBuilder(TrackingStatusFilter::class, $query);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->trackingNumber('tracking-public');
    $filter->trackingNumberUuid('tracking-uuid');
    $filter->order('order-public');
    $filter->entity('entity-public');

    expect($query->calls)->toContain(['where', ['company_uuid', 'company-uuid']]);

    $relations = collect($query->calls)->where(0, 'whereHas')->values();

    expect($relations->pluck(1)->all())->toBe(['trackingNumber', 'trackingNumber', 'trackingNumber', 'trackingNumber'])
        ->and($relations[0][2])->toBe([
            ['whereNested', [
                ['where', ['public_id', 'tracking-public']],
                ['orWhere', ['uuid', 'tracking-public']],
            ]],
        ])
        ->and($relations[1][2])->toBe([
            ['whereNested', [
                ['where', ['public_id', 'tracking-uuid']],
                ['orWhere', ['uuid', 'tracking-uuid']],
            ]],
        ])
        ->and($relations[2][2])->toBe([
            ['whereHas', 'order', [
                ['where', ['public_id', 'order-public']],
            ]],
        ])
        ->and($relations[3][2])->toBe([
            ['whereHas', 'entity', [
                ['where', ['public_id', 'entity-public']],
            ]],
        ]);
});

test('contact filter records identity address and date filters', function () {
    $query  = new FleetOpsControllerFilterQuery();
    $filter = fleetopsFilterWithBuilder(ContactFilter::class, $query);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->query('Ada');
    $filter->internalId('internal-1');
    $filter->publicId('contact-public');
    $filter->name('Ada Lovelace');
    $filter->title('Dispatcher');
    $filter->type('customer');
    $filter->email('ada@example.test');
    $filter->phone('+6555552222');
    $filter->address('1 Test Street');
    $filter->createdAt(['2026-01-01', '2026-01-31']);
    $filter->updatedAt('2026-02-01');

    expect($query->calls[0])->toBe(['whereNested', [
        ['where', ['company_uuid', 'company-uuid']],
    ]])
        ->and($query->calls)->toContain(['where', ['company_uuid', 'company-uuid']])
        ->and($query->calls)->toContain(['search', 'Ada'])
        ->and($query->calls)->toContain(['searchWhere', 'internal_id', 'internal-1'])
        ->and($query->calls)->toContain(['searchWhere', 'public_id', 'contact-public'])
        ->and($query->calls)->toContain(['searchWhere', 'name', 'Ada Lovelace'])
        ->and($query->calls)->toContain(['searchWhere', 'title', 'Dispatcher'])
        ->and($query->calls)->toContain(['searchWhere', 'type', 'customer'])
        ->and($query->calls)->toContain(['searchWhere', 'email', 'ada@example.test'])
        ->and($query->calls)->toContain(['searchWhere', 'phone', '+6555552222'])
        ->and(collect($query->calls)->where(0, 'whereBetween')->values())->toHaveCount(1)
        ->and(collect($query->calls)->where(0, 'whereDate')->values())->toHaveCount(1);

    $address = collect($query->calls)->first(fn ($call) => $call[0] === 'whereHas' && $call[1] === 'addresses');

    expect($address[2])->toBe([
        ['search', '1 Test Street'],
    ]);
});

test('fuel provider transaction filter records relation status provider and date filters', function () {
    $query  = new FleetOpsControllerFilterQuery();
    $filter = fleetopsFilterWithBuilder(FleetOpsFuelProviderTransactionFilterProbe::class, $query);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->query('receipt');
    $filter->provider('shell');
    $filter->syncStatus('pending,matched');
    $filter->vehicle('vehicle-public');
    $filter->connection('connection-public');
    $filter->driver('driver-public');
    $filter->order('order-public');
    $filter->fuelReport('fuel-report-public');
    $filter->transactionAt(['2026-01-01', '2026-01-31']);

    expect($query->calls)->toContain(['where', ['company_uuid', 'company-uuid']])
        ->and($query->calls)->toContain(['search', 'receipt'])
        ->and($query->calls)->toContain(['where', ['provider', 'shell']])
        ->and($query->calls)->toContain(['whereIn', 'sync_status', ['pending', 'matched']])
        ->and($query->calls)->toContain(['whereIn', 'vehicle_uuid', ['vehicle-public-uuid']])
        ->and($query->calls)->toContain(['whereIn', 'fuel_provider_connection_uuid', ['connection-public-uuid']])
        ->and($query->calls)->toContain(['whereIn', 'driver_uuid', ['driver-public-uuid']])
        ->and($query->calls)->toContain(['whereIn', 'order_uuid', ['order-public-uuid']])
        ->and($query->calls)->toContain(['whereIn', 'fuel_report_uuid', ['fuel-report-public-uuid']])
        ->and(collect($query->calls)->where(0, 'whereBetween')->values())->toHaveCount(1)
        ->and($filter->resolvedRelations)->toBe([
            [Vehicle::class, 'vehicle-public', false],
            [FuelProviderConnection::class, 'connection-public', false],
            [Driver::class, 'driver-public', false],
            [Order::class, 'order-public', false],
            [FuelReport::class, 'fuel-report-public', false],
        ]);

    $scalarQuery  = new FleetOpsControllerFilterQuery();
    $scalarFilter = fleetopsFilterWithBuilder(FleetOpsFuelProviderTransactionFilterProbe::class, $scalarQuery);
    $scalarFilter->syncStatus('synced');
    $scalarFilter->transactionAt('2026-02-01');

    expect($scalarQuery->calls)->toContain(['where', ['sync_status', 'synced']])
        ->and(collect($scalarQuery->calls)->where(0, 'whereDate')->first()[1])->toBe('transaction_at');
});

test('tracking number equipment and part filters apply tenant and search scopes', function () {
    $trackingQuery  = new FleetOpsControllerFilterQuery();
    $trackingFilter = fleetopsFilterWithBuilder(TrackingNumberFilter::class, $trackingQuery);

    $trackingFilter->queryForInternal();
    $trackingFilter->queryForPublic();

    $equipmentQuery  = new FleetOpsControllerFilterQuery();
    $equipmentFilter = fleetopsFilterWithBuilder(EquipmentFilter::class, $equipmentQuery);

    $equipmentFilter->queryForInternal();
    $equipmentFilter->queryForPublic();
    $equipmentFilter->query('forklift');

    $partQuery  = new FleetOpsControllerFilterQuery();
    $partFilter = fleetopsFilterWithBuilder(PartFilter::class, $partQuery);

    $partFilter->queryForInternal();
    $partFilter->queryForPublic();
    $partFilter->query('brake pad');
    $partFilter->vendor(null);

    expect($trackingQuery->calls)->toBe([
        ['where', ['company_uuid', 'company-uuid']],
        ['where', ['company_uuid', 'company-uuid']],
    ])
        ->and($equipmentQuery->calls)->toBe([
            ['where', ['company_uuid', 'company-uuid']],
            ['where', ['company_uuid', 'company-uuid']],
            ['search', 'forklift'],
        ])
        ->and($partQuery->calls)->toBe([
            ['where', ['company_uuid', 'company-uuid']],
            ['where', ['company_uuid', 'company-uuid']],
            ['search', 'brake pad'],
        ]);
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
