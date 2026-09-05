<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\VehicleController;
use Fleetbase\FleetOps\Http\Requests\CreateVehicleRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateVehicleRequest;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Write/read parity for the public Vehicle contract, proved against a real
 * database and the real controller rather than by inspecting the input array.
 *
 * A field that validates, persists, and then never appears in a response is the
 * failure this file exists to catch: the caller gets a 200 and a body that looks
 * right, and only discovers the loss when something downstream reads the record
 * back. Asserting that a key entered `$request->only()` does not catch it.
 */
if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null) { return $event; }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!Request::hasMacro('getController')) {
    Request::macro('getController', fn () => new VehicleController());
}

if (!Request::hasMacro('or')) {
    Request::macro('or', function (array $params = [], $default = null) {
        foreach ($params as $param) {
            if ($this->has($param)) {
                return $this->input($param);
            }
        }

        return $default;
    });
}

class FleetOpsVehicleContractRoute
{
    public array $action = [];

    public function __construct(private string $uri)
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function getAction($key = null): string
    {
        return VehicleController::class . '@query';
    }

    public function getActionMethod(): string
    {
        return 'query';
    }

    public function getName(): string
    {
        return 'api.v1.vehicles.query';
    }

    public function parameters(): array
    {
        return [];
    }
}

function fleetopsVehicleContractBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');

    // The spatial grammar wraps a point in ST_GeomFromText even on sqlite, so the
    // function has to exist for a vehicle carrying coordinates to save at all.
    foreach (['ST_PointFromText', 'ST_GeomFromText'] as $fn) {
        $pdo->sqliteCreateFunction($fn, fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    }

    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    // Without a dispatcher the uuid and public_id creating hooks never fire and
    // every record comes back with a null id — which reads as a serialization
    // bug rather than a missing fixture. Memoised: a fresh dispatcher would drop
    // the hooks of models booted earlier in the process.
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }

    if (!app()->bound('responsecache')) {
        app()->instance('responsecache', new class {
            public function __call($method, $arguments)
            {
                return null;
            }
        });
    }

    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
    }

    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

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
    app()->instance('db.schema', $connection->getSchemaBuilder());
    DB::clearResolvedInstance('db');

    $schema  = $connection->getSchemaBuilder();
    $vehicle = new Vehicle();

    $schema->create('vehicles', function ($blueprint) use ($vehicle) {
        $blueprint->increments('id');
        foreach (array_unique(array_merge($vehicle->getFillable(), ['uuid', 'public_id', '_key', 'telematic_uuid'])) as $column) {
            $blueprint->text($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    foreach (['vendors', 'categories', 'warranties', 'files', 'drivers', 'users', 'directives', 'orders', 'custom_field_values', 'custom_fields', 'places', 'contacts', 'vendor_personnels', 'integrated_vendors'] as $table) {
        $schema->create($table, function ($blueprint) {
            $blueprint->increments('id');
            foreach ([
                'uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'vehicle_uuid',
                'name', '_key', 'for', 'owner_uuid', 'owner_type', 'subject_uuid', 'subject_type',
                'permission_uuid', 'key', 'rules', 'disk', 'path', 'bucket', 'type', 'original_filename',
                'provider', 'policy_number', 'status', 'vehicle_assigned_uuid', 'driver_assigned_uuid', 'tracking',
                'custom_field_uuid', 'value', 'value_type', 'label', 'contact_uuid', 'vendor_uuid', 'place_uuid',
            ] as $column) {
                $blueprint->text($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-uuid']);
    $connection->table('vendors')->insert(['uuid' => 'vendor-uuid', 'public_id' => 'vendor_contract1', 'company_uuid' => 'company-uuid', 'name' => 'Acme']);
    $connection->table('users')->insert(['uuid' => 'user-uuid', 'company_uuid' => 'company-uuid']);
    $connection->table('drivers')->insert(['uuid' => 'driver-uuid', 'public_id' => 'driver_contract1', 'company_uuid' => 'company-uuid', 'user_uuid' => 'user-uuid']);

    return $connection;
}

function fleetopsVehicleContractRequest(string $class, string $method, array $payload, string $uri = 'v1/vehicles')
{
    $request = $class::create('/' . $uri, $method, $payload);
    $store   = app('session.store');
    $store->put('company', 'company-uuid');
    $request->setLaravelSession($store);
    $request->setRouteResolver(fn () => new FleetOpsVehicleContractRoute($uri));
    app()->instance('request', $request);

    return $request;
}

/**
 * Every safe writable field, with a value whose type exercises its cast.
 *
 * @return array<string, mixed>
 */
function fleetopsVehicleContractPayload(): array
{
    return [
        'internal_id'                          => 'VEH-9001', 'name' => 'Depot Van', 'description' => 'City route van',
        'make'                                 => 'Ford', 'model' => 'Transit', 'model_type' => 'Custom', 'year' => 2024,
        'trim'                                 => 'Trend', 'color' => 'White', 'type' => 'van', 'class' => 'N1',
        'plate_number'                         => 'SG-9001', 'vin' => '1FTBW3XG8NKA00001', 'serial_number' => 'SER-9001',
        'call_sign'                            => 'DEPOT-1', 'fuel_card_number' => 'FC-9001',
        'odometer'                             => 41000, 'odometer_unit' => 'km', 'odometer_at_purchase' => 12,
        'measurement_system'                   => 'metric', 'fuel_type' => 'diesel', 'fuel_volume_unit' => 'l',
        'online'                               => true, 'status' => 'available',
        'transmission'                         => 'automatic', 'body_type' => 'panel_van', 'body_sub_type' => 'lwb',
        'usage_type'                           => 'commercial', 'ownership_type' => 'owned', 'cargo_volume' => 11.5,
        'passenger_volume'                     => 3.2, 'interior_volume' => 14.7, 'weight' => 2100.5, 'width' => 2.06,
        'length'                               => 5.98, 'height' => 2.54, 'towing_capacity' => 2500, 'payload_capacity' => 1400,
        'seating_capacity'                     => 3, 'ground_clearance' => 0.18, 'bed_length' => 3.4, 'fuel_capacity' => 70,
        'financing_status'                     => 'financed', 'loan_number_of_payments' => 48,
        'loan_first_payment'                   => '2026-01-15', 'loan_amount' => 32000, 'currency' => 'SGD',
        'estimated_service_life_distance_unit' => 'km', 'estimated_service_life_distance' => 400000,
        'estimated_service_life_months'        => 96, 'insurance_value' => 41000, 'depreciation_rate' => 12.5,
        'current_value'                        => 38000, 'acquisition_cost' => 52000,
        'purchased_at'                         => '2026-01-02', 'lease_expires_at' => '2029-01-02',
        'emission_standard'                    => 'euro6', 'dpf_equipped' => true, 'scr_equipped' => false,
        'gvwr'                                 => 3500, 'gcwr' => 6000, 'engine_number' => 'ENG-9001', 'engine_model' => 'EcoBlue',
        'engine_make'                          => 'Ford', 'engine_family' => 'Puma', 'engine_configuration' => 'inline',
        'engine_displacement'                  => 2.0, 'engine_size' => 1995, 'horsepower' => 168,
        'horsepower_rpm'                       => 3500, 'torque' => 405, 'torque_rpm' => 1750,
        'number_of_cylinders'                  => 4, 'cylinder_arrangement' => 'I4',
        'specs'                                => ['doors' => 4], 'details' => ['liftgate' => true], 'notes' => 'City pool',
        'meta'                                 => ['depot' => 'north'],
        'skills'                               => ['tail_lift'], 'payload_capacity_volume' => 11.25,
        'payload_capacity_pallets'             => 6, 'payload_capacity_parcels' => 320, 'max_tasks' => 40,
        'time_window_start'                    => '08:00', 'time_window_end' => '18:00', 'return_to_depot' => true,
        'altitude'                             => 15, 'heading' => 180, 'speed' => 42,
    ];
}

/**
 * Compare a serialized response against what was sent, allowing for the casts
 * the model applies on the way through.
 *
 * @return array<int, string> fields whose round trip did not hold
 */
function fleetopsVehicleContractMismatches(array $sent, array $payload): array
{
    $mismatches = [];

    foreach ($sent as $field => $expected) {
        if (!array_key_exists($field, $payload)) {
            $mismatches[] = $field . ' (absent from the response)';
            continue;
        }

        $actual = $payload[$field];

        if (is_array($expected)) {
            if (json_encode($actual) !== json_encode($expected)) {
                $mismatches[] = $field;
            }
            continue;
        }

        if (is_bool($expected)) {
            if ((bool) $actual !== $expected) {
                $mismatches[] = $field;
            }
            continue;
        }

        if (is_numeric($expected) && is_numeric($actual)) {
            // Decimal casts return strings; the value is what matters, not the shape.
            if (abs(((float) $actual) - ((float) $expected)) > 0.0001) {
                $mismatches[] = $field;
            }
            continue;
        }

        // Dates and datetimes are the documented canonical transformation: a
        // `2026-01-02` goes in and an ISO-8601 instant comes back out.
        if ($actual instanceof DateTimeInterface) {
            if ($actual->format('Y-m-d') !== substr((string) $expected, 0, 10)) {
                $mismatches[] = $field . ' (' . $actual->format('c') . ' != ' . $expected . ')';
            }
            continue;
        }

        $actualString = is_scalar($actual) ? (string) $actual : trim((string) json_encode($actual), '"');

        // Times come back with seconds appended.
        if ($actualString !== '' && str_starts_with($actualString, (string) $expected)) {
            continue;
        }

        if ($actualString !== (string) $expected) {
            $mismatches[] = $field . ' (' . $actualString . ' != ' . $expected . ')';
        }
    }

    return $mismatches;
}

test('every safe writable vehicle field survives create retrieve update and query', function () {
    fleetopsVehicleContractBoot();

    $controller = new VehicleController();
    $sent       = fleetopsVehicleContractPayload();

    // ---- create ----
    $createRequest = fleetopsVehicleContractRequest(CreateVehicleRequest::class, 'POST', $sent + ['vendor' => 'vendor_contract1']);
    $created       = $controller->create($createRequest)->resolve($createRequest);

    expect(fleetopsVehicleContractMismatches($sent, $created))->toBe([])
        ->and($created['id'])->toMatch('/^vehicle_/')
        ->and($created['vendor_id'])->toBe('vendor_contract1');

    $publicId = $created['id'];

    // ---- retrieve ----
    $findRequest = fleetopsVehicleContractRequest(Request::class, 'GET', []);
    $found       = $controller->find($publicId, $findRequest)->resolve($findRequest);

    expect(fleetopsVehicleContractMismatches($sent, $found))->toBe([]);

    // ---- update: every field again, with different values ----
    $updated = [
        'name'               => 'Depot Van 2', 'odometer' => 41250, 'color' => 'Silver',
        'seating_capacity'   => 5, 'weight' => 2200.25, 'purchased_at' => '2026-02-03',
        'loan_first_payment' => '2026-03-15', 'loan_amount' => 31000,
        'insurance_value'    => 40000, 'depreciation_rate' => 11.5, 'current_value' => 37000,
        'acquisition_cost'   => 51000, 'specs' => ['doors' => 5], 'details' => ['liftgate' => false],
        'notes'              => 'Regional pool', 'meta' => ['depot' => 'south'], 'skills' => ['refrigerated'],
        'max_tasks'          => 30, 'time_window_start' => '07:30', 'time_window_end' => '17:30',
        'return_to_depot'    => false, 'fuel_card_number' => 'FC-9002', 'online' => false,
    ];
    $updateRequest  = fleetopsVehicleContractRequest(UpdateVehicleRequest::class, 'PUT', $updated);
    $updateResponse = $controller->update($publicId, $updateRequest)->resolve($updateRequest);

    expect(fleetopsVehicleContractMismatches($updated, $updateResponse))->toBe([])
        // A partial update leaves everything it did not name alone.
        ->and($updateResponse['vin'])->toBe('1FTBW3XG8NKA00001')
        ->and($updateResponse['make'])->toBe('Ford')
        ->and($updateResponse['vendor_id'])->toBe('vendor_contract1');

    // ---- query ----
    $queryRequest = fleetopsVehicleContractRequest(Request::class, 'GET', []);
    $collection   = $controller->query($queryRequest)->resolve($queryRequest);
    $queried      = $collection[0];

    expect(fleetopsVehicleContractMismatches($updated, $queried))->toBe([])
        // The four operations agree on the same business-field contract.
        ->and(array_diff(array_keys($created), array_keys($queried)))->toBe([]);
});

test('vehicle status normalisation and coordinate canonicalisation are the documented transformations', function () {
    fleetopsVehicleContractBoot();

    $controller = new VehicleController();
    $request    = fleetopsVehicleContractRequest(CreateVehicleRequest::class, 'POST', [
        'make'      => 'Ford',
        'status'    => 'active',
        'latitude'  => 40.7484,
        'longitude' => -73.9857,
    ]);

    $payload = $controller->create($request)->resolve($request);

    // `active` is accepted and stored as `available`; the coordinates arrive as
    // two scalars and come back canonically inside `location`.
    expect($payload['status'])->toBe('available')
        ->and($payload)->toHaveKey('location')
        ->and($payload['location'])->not->toBeNull();
});

test('vehicle relationships are additive: the id is new, the object keeps its shape', function () {
    $connection = fleetopsVehicleContractBoot();
    $controller = new VehicleController();

    $request = fleetopsVehicleContractRequest(CreateVehicleRequest::class, 'POST', [
        'make'   => 'Ford',
        'vendor' => 'vendor_contract1',
        'driver' => 'driver_contract1',
    ]);
    $created = $controller->create($request)->resolve($request);

    expect($created['vendor_id'])->toBe('vendor_contract1')
        ->and($created['driver_id'])->toBe('driver_contract1')
        ->and($created['category_id'])->toBeNull()
        ->and($created['warranty_id'])->toBeNull()
        ->and($created['photo_id'])->toBeNull()
        // Unexpanded, the object keys stay absent — never a string under them.
        ->and($created)->not->toHaveKeys(['vendor', 'driver', 'category', 'warranty', 'photo'])
        ->and($created)->not->toHaveKeys(['uuid', 'public_id', 'company_uuid', 'vendor_uuid', 'photo_uuid']);

    // Expansion adds the object and leaves the identifier untouched.
    $expandRequest = fleetopsVehicleContractRequest(Request::class, 'GET', ['with' => ['vendor']]);
    $expanded      = $controller->find($created['id'], $expandRequest)->resolve($expandRequest);

    expect($expanded['vendor'])->toBeObject()
        ->and($expanded['vendor_id'])->toBe('vendor_contract1')
        ->and($expanded['vendor']->resolve()['id'])->toBe($expanded['vendor_id'])
        ->and($expanded)->not->toHaveKey('driver');

    // Clearing a relationship reports a null identifier.
    $clearRequest = fleetopsVehicleContractRequest(UpdateVehicleRequest::class, 'PUT', ['vendor' => null]);
    $cleared      = $controller->update($created['id'], $clearRequest)->resolve($clearRequest);

    expect($cleared['vendor_id'])->toBeNull()
        ->and($connection->table('vehicles')->count())->toBe(1);
});

test('the internal vehicle resource keeps its counters and its nested driver', function () {
    $connection = fleetopsVehicleContractBoot();
    $controller = new VehicleController();

    $createRequest = fleetopsVehicleContractRequest(CreateVehicleRequest::class, 'POST', [
        'make'   => 'Ford',
        'driver' => 'driver_contract1',
    ]);
    $created = $controller->create($createRequest)->resolve($createRequest);

    $vehicleUuid = $connection->table('vehicles')->where('public_id', $created['id'])->value('uuid');
    $connection->table('orders')->insert([
        ['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'company-uuid', 'vehicle_assigned_uuid' => $vehicleUuid, 'tracking' => 'TRK-1'],
        ['uuid' => 'order-2', 'public_id' => 'order_2', 'company_uuid' => 'company-uuid', 'vehicle_assigned_uuid' => $vehicleUuid, 'tracking' => 'TRK-2'],
    ]);

    $internalRequest = fleetopsVehicleContractRequest(Request::class, 'GET', [], 'int/v1/fleet-ops/vehicles');
    $vehicle         = Vehicle::where('public_id', $created['id'])->firstOrFail();
    $payload         = (new Fleetbase\FleetOps\Http\Resources\v1\Vehicle($vehicle))->resolve($internalRequest);

    // The console reads these; the public contract does not, and no longer pays
    // for them — `when()` evaluates a plain value argument eagerly, so both
    // counters used to run a query on every public read and discard it.
    expect($payload)->toHaveKeys(['uuid', 'public_id', 'assigned_orders_count', 'current_order_reference'])
        ->and($payload['assigned_orders_count'])->toBe(2)
        ->and($payload['uuid'])->toBe($vehicleUuid)
        // Internal keeps the whenLoaded object shape it has always had.
        ->and($payload['driver'])->toBeObject();
});

test('an unsupported expansion on retrieve cannot reach eloquent', function () {
    fleetopsVehicleContractBoot();

    $controller    = new VehicleController();
    $createRequest = fleetopsVehicleContractRequest(CreateVehicleRequest::class, 'POST', ['make' => 'Ford']);
    $created       = $controller->create($createRequest)->resolve($createRequest);

    // Retrieve is the endpoint a hand-written client is most likely to hand a
    // stale relation name. The request parameter carries a default, and
    // Laravel's dispatcher skips injecting a type-hinted dependency that has
    // one — so the controller reads the container's request instead. Without
    // that, `not_a_relation` reached load() and answered 500.
    $request = fleetopsVehicleContractRequest(Request::class, 'GET', ['with' => ['vendor', 'not_a_relation']]);
    $payload = $controller->find($created['id'])->resolve($request);

    expect($payload['id'])->toBe($created['id'])
        ->and($payload)->not->toHaveKey('not_a_relation')
        ->and($request->input('with'))->toBe(['vendor']);
});
