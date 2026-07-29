<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\EntityController;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Payload;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the EntityController protected helper bodies against SQLite:
 * uuid/value/model-name/singularize utilities, payload lookups, entity
 * creation and lookup, and the resource wrappers.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

function fleetopsEntityHelpersBoot(): SQLiteConnection
{
    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
    }
    $pdo = new PDO('sqlite::memory:');
    foreach (['ST_PointFromText', 'ST_GeomFromText'] as $fn) {
        $pdo->sqliteCreateFunction($fn, fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    }
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $barcodeFake = new class {
        public function __call($method, $arguments)
        {
            return 'barcode';
        }
    };
    app()->instance('DNS2D', $barcodeFake);
    app()->instance('DNS1D', $barcodeFake);
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'entities'          => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'tracking_number_uuid', 'name', 'type', 'status', 'internal_id', 'customer_uuid', 'customer_type', 'destination_uuid', 'photo_uuid', 'meta', 'qr_code', 'barcode', 'price', 'sale_price', 'currency', 'weight', 'weight_unit', 'length', 'width', 'height', 'dimensions_unit', 'declared_value', 'sku', 'description', 'docs', 'slug', '_key'],
        'payloads'          => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'type', 'meta', '_key'],
        'tracking_numbers'  => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', 'region', 'barcode', 'qr_code', 'status_uuid', 'type', '_key'],
        'tracking_statuses' => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'code', 'status', 'details', 'location', 'city', 'province', 'country', 'proof_uuid', '_key'],
        'places'            => ['uuid', 'public_id', 'company_uuid', 'name', 'location', '_key'],
        'waypoints'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'order', 'type', 'status', '_key'],
        'companies'         => ['uuid', 'public_id', 'name', 'country', 'options'],
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
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);

    return $connection;
}

test('entity helpers resolve utilities payloads records and resources', function () {
    $connection = fleetopsEntityHelpersBoot();
    $connection->table('payloads')->insert(['uuid' => '55555555-5555-4555-8555-555555555501', 'public_id' => 'payload_entityhelper', 'company_uuid' => 'company-1']);

    $controller = new EntityController();
    $helper     = function (string $method, ...$arguments) use ($controller) {
        $reflection = new ReflectionMethod(EntityController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$arguments);
    };

    // Utility passthroughs
    expect($helper('getUuid', 'payloads', ['public_id' => 'payload_entityhelper']))->toBe('55555555-5555-4555-8555-555555555501')
        ->and($helper('getValue', ['nested' => ['key' => 'value']], 'nested.key'))->toBe('value')
        ->and($helper('getModelClassName', null))->toBeNull()
        ->and($helper('singularize', 'entities'))->toBe('entity')
        ->and($helper('singularize', null))->toBeNull();

    // Payload lookup by public id
    $payload = $helper('findPayloadByPublicId', 'payload_entityhelper');
    expect($payload)->toBeInstanceOf(Payload::class);
    expect($helper('findPayloadByPublicId', 'payload_missing'))->toBeNull();

    // Entity creation persists rows and lookups rehydrate them
    $entity = $helper('createEntity', [
        'company_uuid' => 'company-1',
        'name'         => 'Helper Entity',
        'type'         => 'parcel',
    ]);
    expect($entity)->toBeInstanceOf(Entity::class)
        ->and($connection->table('entities')->count())->toBe(1);

    $publicId = (string) $connection->table('entities')->value('public_id');
    $found    = $helper('findEntityOrFail', $publicId);
    expect($found)->toBeInstanceOf(Entity::class)
        ->and($found->name)->toBe('Helper Entity');

    // Resource wrappers and the json helper
    expect($helper('entityResource', $found))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Entity::class)
        ->and($helper('entityResourceCollection', collect([$found])))->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class)
        ->and($helper('jsonResponse', ['ok' => true], 200))->toBeInstanceOf(Illuminate\Http\JsonResponse::class);
    $deleted = $helper('deletedEntityResource', $found);
    expect($deleted)->not->toBeNull();
});
