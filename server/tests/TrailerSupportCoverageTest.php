<?php

use Fleetbase\FleetOps\Exports\TrailerExport;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\SearchController;
use Fleetbase\FleetOps\Http\Filter\EquipmentFilter;
use Fleetbase\FleetOps\Http\Filter\TrailerFilter;
use Fleetbase\FleetOps\Http\Requests\CreateTrailerRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateTrailerRequest;
use Fleetbase\FleetOps\Http\Resources\v1\AssetConnection as AssetConnectionResource;
use Fleetbase\FleetOps\Http\Resources\v1\Trailer as TrailerResource;
use Fleetbase\FleetOps\Imports\TrailerImport;
use Fleetbase\FleetOps\Models\AssetConnection;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceInstallation;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

if (!class_exists('Illuminate\\Validation\\Rule')) {
    eval('namespace Illuminate\\Validation; class Rule { public function __construct(private string $rule = "") {} public static function requiredIf($condition): string { return (is_callable($condition) ? $condition() : $condition) ? "required" : "nullable"; } public static function in(array $values): self { return new self("in:" . implode(",", $values)); } public function __toString(): string { return $this->rule; } }');
}

class FleetOpsTrailerFilterBuilder
{
    public array $calls = [];

    public function __call(string $method, array $arguments): static
    {
        $this->calls[] = [$method, ...$arguments];
        foreach ($arguments as $argument) {
            if ($argument instanceof Closure) {
                $argument($this);
            }
        }

        return $this;
    }

    public function called(string $method): bool
    {
        return collect($this->calls)->contains(fn ($call) => $call[0] === $method);
    }
}

class FleetOpsTrailerHistoryDevice extends Device
{
    public function update(array $attributes = [], array $options = []): bool
    {
        $this->forceFill($attributes);

        return false;
    }
}

function fleetOpsTrailerSupportDatabase(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    $config = new Repository(['activitylog' => ['enabled' => false, 'default_auth_driver' => null, 'default_log_name' => 'default']]);
    app()->instance('config', $config);
    app()->instance(Illuminate\Contracts\Config\Repository::class, $config);
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $connection)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->connection;
        }

        public function __call($method, $arguments)
        {
            return $this->connection->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $schema->create('assets', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'name', 'code', 'description', 'type', 'asset_class', 'status', 'vin', 'plate_number', 'serial_number', 'make', 'model', 'year', 'length', 'width', 'height', 'tare_weight', 'gvwr', 'payload_capacity', 'cargo_volume', 'axle_count', 'tire_count', 'coupling_type', 'brake_type', 'refrigerated', 'measurement_system', 'odometer', 'odometer_unit', 'ownership_type', 'purchased_at', 'lease_expires_at', 'notes', 'slug', '_key'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    foreach (['vehicles', 'devices', 'equipments', 'asset_connections'] as $name) {
        $schema->create($name, function ($table) {
            $table->increments('id');
            foreach (['uuid', 'public_id', 'company_uuid', 'name', 'display_name', 'plate_number', 'device_uuid', 'attachable_type', 'attachable_uuid', 'connector_type', 'connector_uuid', 'connected_type', 'connected_uuid'] as $column) {
                $table->string($column)->nullable();
            }
            $table->timestamps();
            $table->softDeletes();
        });
    }
    $schema->create('device_installations', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'company_uuid', 'device_uuid', 'attachable_type', 'attachable_uuid', 'active_device_uuid', 'installed_at', 'removed_at', 'source', 'metadata'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('custom_field_values', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'company_uuid', 'custom_field_uuid', 'subject_uuid', 'subject_type', 'value', 'value_type'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('custom_fields', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'company_uuid', 'label', 'name', 'type'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    session(['company' => 'company-trailer-support']);

    return $connection;
}

function fleetOpsTrailerFilter(FleetOpsTrailerFilterBuilder $builder, array $query = []): TrailerFilter
{
    $request = Request::create('/int/v1/trailers', 'GET', $query);
    $session = app('session.store');
    $session->put('company', 'company-trailer-support');
    $request->setLaravelSession($session);
    $filter     = new TrailerFilter($request);
    $reflection = new ReflectionClass($filter);
    $property   = $reflection->getParentClass()->getProperty('builder');
    $property->setAccessible(true);
    $property->setValue($filter, $builder);

    return $filter;
}

test('trailer request authorization and validation expose the complete contract', function () {
    $request = CreateTrailerRequest::create('/v1/trailers', 'POST');
    $request->setLaravelSession(app('session.store'));
    app()->instance('request', $request);
    expect($request->authorize())->toBeFalse();

    $request->session()->put('api_credential', 'credential');
    expect($request->authorize())->toBeTrue()
        ->and($request->rules())->toHaveKeys(['name', 'type', 'status', 'latitude', 'longitude', 'temperature_max', 'ownership_type', 'capacity', 'attributes']);

    $update = UpdateTrailerRequest::create('/v1/trailers/trailer_one', 'PATCH');
    $update->setLaravelSession($request->session());
    app()->instance('request', $update);
    expect($update->authorize())->toBeTrue()
        ->and($update->rules())->toHaveKey('name');
});

test('trailer filter executes text numeric state relationship and date branches', function () {
    $connection = fleetOpsTrailerSupportDatabase();
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-filter', 'public_id' => 'vehicle_public', 'company_uuid' => 'company-trailer-support']);
    $connection->getSchemaBuilder()->create('vendors', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'name', '_key'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    $connection->table('vendors')->insert(['uuid' => 'vendor-filter', 'public_id' => 'vendor_public', 'company_uuid' => 'company-trailer-support']);

    Carbon::setTestNow('2026-09-03 12:00:00');
    $builder = new FleetOpsTrailerFilterBuilder();
    $filter  = fleetOpsTrailerFilter($builder);
    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->query('needle');
    $filter->publicId('trailer_public');
    $filter->name('Trailer');
    $filter->code('T-1');
    $filter->trailerType('reefer');
    $filter->status('available');
    $filter->trailerMake('Acme');
    $filter->trailerModel('ColdBox');
    $filter->trailerYear('2026');
    $filter->plateNumber('PLATE');
    $filter->vin('VIN');
    $filter->serialNumber('SERIAL');
    $filter->length('12');
    $filter->axleCount('3');
    $filter->gvwr('30000');
    $filter->payloadCapacity('20000');
    $filter->ownershipType('owned');
    $filter->devicesCount('2');
    $filter->equipmentCount('1');
    foreach (['never_connected', 'online', 'recently_offline', 'offline', 'unknown'] as $state) {
        $filter->connectivityStatus($state);
    }
    $filter->attachmentState('attached');
    $filter->attachmentState('detached');
    $filter->vehicle('vehicle_public');
    $filter->vehicle('missing_vehicle');
    $filter->vendor('vendor_public');
    $filter->createdAt('2026-09-03');
    $filter->updatedAt(['2026-09-01', '2026-09-03']);
    $filter->lastOnlineAt('2026-09-03');

    expect($builder->called('search'))->toBeTrue()
        ->and($builder->called('searchWhere'))->toBeTrue()
        ->and($builder->called('whereNull'))->toBeTrue()
        ->and($builder->called('whereBetween'))->toBeTrue()
        ->and($builder->called('whereHas'))->toBeTrue()
        ->and($builder->called('whereDoesntHave'))->toBeTrue()
        ->and($builder->called('whereIn'))->toBeTrue()
        ->and($builder->called('whereDate'))->toBeTrue();
    Carbon::setTestNow();
});

test('equipment filters cover attachment state type aliases and public targets', function () {
    $builder         = new FleetOpsTrailerFilterBuilder();
    $filter          = fleetOpsTrailerFilter($builder);
    $request         = Request::create('/int/v1/equipment');
    $request->setLaravelSession(app('session.store'));
    $equipmentFilter = new EquipmentFilter($request);
    $reflection      = new ReflectionClass($equipmentFilter);
    $property        = $reflection->getParentClass()->getProperty('builder');
    $property->setAccessible(true);
    $property->setValue($equipmentFilter, $builder);

    $equipmentFilter->attachmentState('attached');
    $equipmentFilter->attachmentState('detached');
    foreach (['vehicle', 'fleet-ops:vehicle', 'trailer', 'fleet-ops:trailer', 'unknown'] as $type) {
        $equipmentFilter->equipableType($type);
    }
    $equipmentFilter->equipable('trailer_public');
    expect($builder->called('whereNotNull'))->toBeTrue()
        ->and($builder->called('whereNull'))->toBeTrue()
        ->and($builder->called('whereRaw'))->toBeTrue()
        ->and($builder->called('whereHas'))->toBeTrue();
});

test('trailer import validates rows and export scopes and maps spreadsheet data', function () {
    $connection = fleetOpsTrailerSupportDatabase();
    $import     = new TrailerImport();
    $import->collection(collect([
        new Collection([]),
        new Collection(['name' => 'Cold Trailer', 'type' => 'reefer', 'status' => 'available', 'ignored' => 'value']),
    ]));
    expect($import->imported)->toBe(1)
        ->and($connection->table('assets')->value('asset_class'))->toBe('trailer');

    expect(fn () => (new TrailerImport())->collection(collect([['type' => 'reefer']])))->toThrow(ValidationException::class)
        ->and(fn () => (new TrailerImport())->collection(collect([['name' => 'Bad', 'type' => 'spaceship']])))->toThrow(ValidationException::class);

    $trailer = Trailer::withoutGlobalScopes()->first();
    $trailer->forceFill(['public_id' => 'trailer_export', 'refrigerated' => true]);
    $export = new TrailerExport([$trailer->uuid]);
    expect($export->headings())->toContain('Plate Number')
        ->and($export->map($trailer))->toContain('Yes')
        ->and($export->collection())->toHaveCount(1)
        ->and((new TrailerExport())->collection())->toHaveCount(1);
});

test('trailer and connection resources serialize loaded public relationships', function () {
    fleetOpsTrailerSupportDatabase();
    $vehicle = new Vehicle();
    $vehicle->forceFill(['uuid' => 'vehicle-resource', 'public_id' => 'vehicle_public', 'name' => 'Road Tractor', 'plate_number' => 'TRUCK']);
    $trailer = new Trailer();
    $trailer->setAppends([]);
    $trailer->forceFill(['uuid' => 'trailer-resource', 'public_id' => 'trailer_public', 'company_uuid' => 'company-trailer-support', 'name' => 'Reefer', 'type' => 'reefer', 'status' => 'available', 'last_online_at' => now()]);
    $connection = new AssetConnection(['relationship_type' => 'towing', 'position' => 1, 'connected_at' => now(), 'source' => 'manual']);
    $connection->forceFill(['uuid' => 'connection-resource', 'public_id' => 'connection_public']);
    $connection->setRelation('vehicle', $vehicle);
    $connection->setRelation('trailer', $trailer);
    $trailer->setRelation('currentConnection', $connection);
    $trailer->setRelation('connections', collect([$connection]));
    $trailer->setRelation('devices', collect([new Device()]));
    $trailer->setRelation('equipments', collect([new Equipment()]));
    $trailer->setRelation('positions', collect());

    $request = Request::create('/v1/trailers/trailer_public');
    app()->instance('request', $request);
    $serialized = (new TrailerResource($trailer))->toArray($request);
    $connected  = (new AssetConnectionResource($connection))->toArray($request);
    expect($serialized['current_vehicle']['id'])->toBe('vehicle_public')
        ->and($serialized['connections'])->not->toBeNull()
        ->and($serialized['devices'])->not->toBeNull()
        ->and($serialized['equipment'])->not->toBeNull()
        ->and($connected['vehicle'])->not->toBeNull()
        ->and($connected['trailer'])->not->toBeNull()
        ->and($connected['active'])->toBeTrue();

    $internalRequest = Request::create('/int/v1/trailers/trailer-resource');
    app()->instance('request', $internalRequest);
    $internal           = (new TrailerResource($trailer))->toArray($internalRequest);
    $internalConnection = (new AssetConnectionResource($connection))->toArray($internalRequest);
    expect($internal['asset_class'])->not->toBeNull()
        ->and($internalConnection['uuid'])->not->toBeNull();

    $emptyConnection = new AssetConnection(['disconnected_at' => now()]);
    $emptyConnection->setRelation('vehicle', null);
    $emptyConnection->setRelation('trailer', null);
    $empty = (new AssetConnectionResource($emptyConnection))->toArray($request);
    expect($empty['vehicle'])->toBeNull()
        ->and($empty['trailer'])->toBeNull()
        ->and($empty['active'])->toBeFalse();
});

test('connection and installation models expose every polymorphic relation', function () {
    fleetOpsTrailerSupportDatabase();
    $connection   = new AssetConnection();
    $device       = new Device();
    $installation = new DeviceInstallation();
    expect($connection->connector())->toBeInstanceOf(MorphTo::class)
        ->and($connection->connected())->toBeInstanceOf(MorphTo::class)
        ->and($connection->vehicle())->toBeInstanceOf(BelongsTo::class)
        ->and($connection->trailer())->toBeInstanceOf(BelongsTo::class)
        ->and($device->installations())->toBeInstanceOf(HasMany::class)
        ->and($installation->device())->toBeInstanceOf(BelongsTo::class)
        ->and($installation->attachable())->toBeInstanceOf(MorphTo::class);
});

test('device installation history closes active rows for attach and detach transitions', function () {
    $connection = fleetOpsTrailerSupportDatabase();
    app()->instance('db.schema', $connection->getSchemaBuilder());
    $connection->table('device_installations')->insert(['uuid' => 'installation-old', 'company_uuid' => 'company-trailer-support', 'device_uuid' => 'device-history', 'active_device_uuid' => 'device-history', 'installed_at' => now()]);
    $device = new FleetOpsTrailerHistoryDevice();
    $device->forceFill(['uuid' => 'device-history', 'company_uuid' => 'company-trailer-support']);
    $trailer = new Trailer();
    $trailer->forceFill(['uuid' => 'trailer-history', 'company_uuid' => 'company-trailer-support', 'name' => 'History Trailer']);

    $idempotent = new FleetOpsTrailerHistoryDevice();
    $idempotent->forceFill(['uuid' => 'device-idempotent', 'company_uuid' => 'company-trailer-support', 'attachable_type' => Trailer::class, 'attachable_uuid' => 'trailer-history']);
    $foreignTrailer = new Trailer();
    $foreignTrailer->forceFill(['uuid' => 'trailer-foreign', 'company_uuid' => 'company-other']);

    expect($idempotent->attachTo($trailer))->toBeTrue()
        ->and(fn () => $device->attachTo($foreignTrailer))->toThrow(DomainException::class)
        ->and($device->attachTo($trailer))->toBeFalse()
        ->and($connection->table('device_installations')->count())->toBe(2)
        ->and($connection->table('device_installations')->where('uuid', 'installation-old')->value('removed_at'))->not->toBeNull()
        ->and($device->detach())->toBeFalse()
        ->and($connection->table('device_installations')->whereNull('removed_at')->count())->toBe(0);
});

test('search controller maps first class trailers into navigation results', function () {
    $connection = fleetOpsTrailerSupportDatabase();
    $connection->table('assets')->insert(['uuid' => 'trailer-search', 'public_id' => 'trailer_search', 'company_uuid' => 'company-trailer-support', 'name' => 'Search Reefer', 'description' => 'Cold', 'type' => 'reefer', 'asset_class' => 'trailer', 'status' => 'available', 'plate_number' => 'COLD-1']);
    $controller = new SearchController();
    $method     = new ReflectionMethod($controller, 'searchTrailers');
    $method->setAccessible(true);
    $results  = $method->invoke($controller, 'Search', 5);
    $dispatch = new ReflectionMethod($controller, 'searchType');
    $dispatch->setAccessible(true);
    expect($results)->toHaveCount(1)
        ->and($results->first())->toMatchArray(['label' => 'Search Reefer', 'type' => 'Trailer', 'route' => 'console.fleet-ops.management.trailers.index.details'])
        ->and($dispatch->invoke($controller, 'trailers', 'Search', 5))->toHaveCount(1);
});
