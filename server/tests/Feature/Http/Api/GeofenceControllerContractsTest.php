<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\GeofenceController;
use Fleetbase\FleetOps\Models\GeofenceEventLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FleetOpsGeofenceQueryFake
{
    public array $calls                              = [];
    public ?FleetOpsGeofencePaginatorFake $paginator = null;
    public Collection $results;

    public function __construct(public ?string $companyUuid = null)
    {
        $this->results = collect();
    }

    public function with(array $relations): self
    {
        $this->calls[] = ['with', $relations];

        return $this;
    }

    public function orderBy(string $column, string $direction): self
    {
        $this->calls[] = ['orderBy', $column, $direction];

        return $this;
    }

    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        $this->calls[] = func_num_args() === 2
            ? ['where', $column, $operator]
            : ['where', $column, $operator, $value];

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->calls[] = ['whereNotNull', $column];

        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        $this->calls[] = ['groupBy', $columns];

        return $this;
    }

    public function select(array $columns): self
    {
        $this->calls[] = ['select', $columns];

        return $this;
    }

    public function paginate(int $perPage): FleetOpsGeofencePaginatorFake
    {
        $this->calls[] = ['paginate', $perPage];

        return $this->paginator ?? new FleetOpsGeofencePaginatorFake(collect());
    }

    public function get(): Collection
    {
        $this->calls[] = ['get'];

        return $this->results;
    }
}

class FleetOpsGeofencePaginatorFake implements JsonSerializable
{
    public function __construct(public Collection $collection)
    {
    }

    public function getCollection(): Collection
    {
        return $this->collection;
    }

    public function jsonSerialize(): array
    {
        return [
            'data' => $this->collection->values()->all(),
        ];
    }
}

class FleetOpsGeofenceTableQueryFake
{
    public array $calls = [];

    public function __construct(public string $table, public Collection $results)
    {
    }

    public function join(string $table, string $first, string $operator, string $second): self
    {
        $this->calls[] = ['join', $table, $first, $operator, $second];

        return $this;
    }

    public function leftJoin(string $table, Closure $callback): self
    {
        $join = new FleetOpsGeofenceJoinFake();
        $callback($join);
        $this->calls[] = ['leftJoin', $table, $join->calls];

        return $this;
    }

    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        $this->calls[] = func_num_args() === 2
            ? ['where', $column, $operator]
            : ['where', $column, $operator, $value];

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->calls[] = ['whereNull', $column];

        return $this;
    }

    public function select(array $columns): self
    {
        $this->calls[] = ['select', $columns];

        return $this;
    }

    public function get(): Collection
    {
        $this->calls[] = ['get'];

        return $this->results;
    }
}

class FleetOpsGeofenceJoinFake
{
    public array $calls = [];

    public function on(string $first, string $operator, string $second): self
    {
        $this->calls[] = ['on', $first, $operator, $second];

        return $this;
    }

    public function where(string $column, string $operator, string $value): self
    {
        $this->calls[] = ['where', $column, $operator, $value];

        return $this;
    }
}

class FleetOpsGeofenceControllerFake extends GeofenceController
{
    public array $eventQueries                        = [];
    public array $tableQueries                        = [];
    public ?FleetOpsGeofenceQueryFake $nextEventQuery = null;
    public array $serializedEvents                    = [];

    protected function geofenceEventLogQuery(?string $companyUuid): mixed
    {
        $query                = $this->nextEventQuery ?? new FleetOpsGeofenceQueryFake($companyUuid);
        $query->companyUuid   = $companyUuid;
        $this->nextEventQuery = null;

        return $this->eventQueries[] = $query;
    }

    protected function table(string $table): mixed
    {
        return $this->tableQueries[$table];
    }

    protected function raw(string $expression): mixed
    {
        return 'raw:' . $expression;
    }

    protected function serializeEvent(GeofenceEventLog $event): array
    {
        $this->serializedEvents[] = $event->uuid;

        return [
            'id'         => $event->uuid,
            'event_type' => 'geofence.' . $event->event_type,
            'subject'    => [
                'type' => $event->subject_type,
                'uuid' => $event->subject_uuid,
                'name' => $event->subject_name,
            ],
        ];
    }
}

function fleetopsGeofenceEvent(array $attributes): GeofenceEventLog
{
    $event = new GeofenceEventLog();
    $event->setRawAttributes($attributes, true);

    return $event;
}

test('api geofence events applies request filters and serializes paginated events', function () {
    session(['company' => 'company-1']);

    $controller = new FleetOpsGeofenceControllerFake();
    $event      = fleetopsGeofenceEvent([
        'uuid'                   => 'event-1',
        'event_type'             => 'entered',
        'occurred_at'            => Carbon::parse('2026-07-26 10:00:00', 'UTC'),
        'subject_type'           => 'vehicle',
        'subject_uuid'           => 'vehicle-1',
        'subject_name'           => 'Truck 1',
        'geofence_uuid'          => 'zone-1',
        'geofence_name'          => 'Zone 1',
        'geofence_type'          => 'zone',
        'latitude'               => 1.23,
        'longitude'              => 4.56,
        'dwell_duration_minutes' => null,
    ]);
    $controller->nextEventQuery            = new FleetOpsGeofenceQueryFake();
    $controller->nextEventQuery->paginator = new FleetOpsGeofencePaginatorFake(collect([$event]));

    $request = Request::create('/geofences/events', 'GET', [
        'driver_uuid'   => 'driver-1',
        'geofence_uuid' => 'zone-1',
        'vehicle_uuid'  => 'vehicle-1',
        'subject_type'  => 'vehicle',
        'event_type'    => 'geofence.entered',
        'from'          => '2026-07-01T00:00:00Z',
        'to'            => '2026-07-31T23:59:59Z',
        'per_page'      => 500,
    ]);

    $response = $controller->events($request);
    $query    = $controller->eventQueries[0];
    $payload  = $response->getData(true);

    expect($query->companyUuid)->toBe('company-1')
        ->and($query->calls)->toContain(
            ['with', ['driver.vehicle', 'vehicle', 'order']],
            ['orderBy', 'occurred_at', 'desc'],
            ['where', 'driver_uuid', 'driver-1'],
            ['where', 'geofence_uuid', 'zone-1'],
            ['where', 'vehicle_uuid', 'vehicle-1'],
            ['where', 'subject_type', 'vehicle'],
            ['where', 'event_type', 'entered'],
            ['where', 'occurred_at', '>=', '2026-07-01T00:00:00Z'],
            ['where', 'occurred_at', '<=', '2026-07-31T23:59:59Z'],
            ['paginate', 200],
        )
        ->and($controller->serializedEvents)->toBe(['event-1'])
        ->and($payload['data'][0]['event_type'])->toBe('geofence.entered')
        ->and($payload['data'][0]['subject'])->toMatchArray([
            'type' => 'vehicle',
            'uuid' => 'vehicle-1',
            'name' => 'Truck 1',
        ]);
});

test('api geofence inventory merges driver and vehicle states', function () {
    session(['company' => 'company-2']);

    $controller = new FleetOpsGeofenceControllerFake();

    $driverState  = (object) ['subject_type' => 'driver', 'entered_at' => '2026-07-26 09:00:00'];
    $vehicleState = (object) ['subject_type' => 'vehicle', 'entered_at' => '2026-07-26 08:00:00'];

    $controller->tableQueries = [
        'driver_geofence_states as dgs'  => new FleetOpsGeofenceTableQueryFake('driver_geofence_states as dgs', collect([$driverState])),
        'vehicle_geofence_states as vgs' => new FleetOpsGeofenceTableQueryFake('vehicle_geofence_states as vgs', collect([$vehicleState])),
    ];

    $payload = $controller->inventory()->getData(true);
    $driver  = $controller->tableQueries['driver_geofence_states as dgs'];
    $vehicle = $controller->tableQueries['vehicle_geofence_states as vgs'];

    expect($payload['total'])->toBe(2)
        ->and(array_column($payload['data'], 'subject_type'))->toBe(['vehicle', 'driver'])
        ->and($driver->calls)->toContain(
            ['join', 'drivers as d', 'd.uuid', '=', 'dgs.driver_uuid'],
            ['where', 'd.company_uuid', 'company-2'],
            ['where', 'dgs.is_inside', true],
            ['whereNull', 'd.deleted_at'],
            ['get'],
        )
        ->and($vehicle->calls)->toContain(
            ['join', 'vehicles as v', 'v.uuid', '=', 'vgs.vehicle_uuid'],
            ['where', 'v.company_uuid', 'company-2'],
            ['where', 'vgs.is_inside', true],
            ['whereNull', 'v.deleted_at'],
            ['get'],
        );
});

test('api geofence dwell report applies date filters and aggregate projection', function () {
    session(['company' => 'company-3']);

    $controller = new FleetOpsGeofenceControllerFake();
    $request    = Request::create('/geofences/dwell-report', 'GET', [
        'from' => '2026-07-01T00:00:00Z',
        'to'   => '2026-07-31T23:59:59Z',
    ]);

    $controller->dwellReport($request);
    $query = $controller->eventQueries[0];

    expect($query->companyUuid)->toBe('company-3')
        ->and($query->calls)->toContain(
            ['where', 'event_type', 'exited'],
            ['whereNotNull', 'dwell_duration_minutes'],
            ['where', 'occurred_at', '>=', '2026-07-01T00:00:00Z'],
            ['where', 'occurred_at', '<=', '2026-07-31T23:59:59Z'],
            ['groupBy', ['geofence_uuid', 'geofence_name', 'geofence_type']],
            ['orderBy', 'visit_count', 'desc'],
            ['get'],
        );
});

test('api geofence driver history applies driver filter and caps pagination', function () {
    session(['company' => 'company-4']);

    $controller = new FleetOpsGeofenceControllerFake();
    $request    = Request::create('/geofences/driver/driver-9/history', 'GET', [
        'per_page' => 75,
    ]);

    $controller->driverHistory($request, 'driver-9');
    $query = $controller->eventQueries[0];

    expect($query->companyUuid)->toBe('company-4')
        ->and($query->calls)->toContain(
            ['where', 'driver_uuid', 'driver-9'],
            ['with', ['driver.vehicle', 'vehicle', 'order']],
            ['orderBy', 'occurred_at', 'desc'],
            ['paginate', 75],
        );
});
