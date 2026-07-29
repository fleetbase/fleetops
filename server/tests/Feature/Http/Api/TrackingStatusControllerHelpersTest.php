<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\TrackingStatusController;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the TrackingStatusController protected helper bodies against
 * SQLite: status code preparation, tracking-number lookups by table and
 * order, status creation and lookup, and the resource wrappers.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

function fleetopsTrackingStatusHelpersBoot(): SQLiteConnection
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
        'tracking_statuses' => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'code', 'status', 'details', 'location', 'city', 'province', 'country', 'proof_uuid', '_key'],
        'tracking_numbers'  => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', 'region', 'barcode', 'qr_code', 'status_uuid', 'type', '_key'],
        'orders'            => ['uuid', 'public_id', 'company_uuid', 'internal_id', 'payload_uuid', 'tracking_number_uuid', 'status', 'type', 'meta', '_key'],
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

    return $connection;
}

test('tracking status helpers prepare codes look up numbers and wrap resources', function () {
    $connection = fleetopsTrackingStatusHelpersBoot();
    $connection->table('tracking_numbers')->insert(['uuid' => '88888888-8888-4888-8888-888888888801', 'public_id' => 'tracking_number_tshelper', 'company_uuid' => 'company-1', 'tracking_number' => 'FLB-TS-1']);
    $connection->table('orders')->insert(['uuid' => '88888888-8888-4888-8888-888888888802', 'public_id' => 'order_tshelper1', 'company_uuid' => 'company-1', 'tracking_number_uuid' => '88888888-8888-4888-8888-888888888801', 'status' => 'created']);

    $controller = new TrackingStatusController();
    $helper     = function (string $method, ...$arguments) use ($controller) {
        $reflection = new ReflectionMethod(TrackingStatusController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$arguments);
    };

    // Status codes normalize through the model helper
    expect($helper('prepareTrackingStatusCode', 'In Transit'))->toBeString()->not->toBeEmpty();

    // Tracking-number lookups by table and by order public id
    expect($helper('getTrackingNumberUuid', 'tracking_numbers', ['public_id' => 'tracking_number_tshelper', 'company_uuid' => 'company-1']))->toBe('88888888-8888-4888-8888-888888888801')
        ->and($helper('getOrderTrackingNumberUuid', 'order_tshelper1'))->toBe('88888888-8888-4888-8888-888888888801');

    // Creation persists a status row and lookups rehydrate it
    $status = $helper('createTrackingStatus', [
        'company_uuid'         => 'company-1',
        'tracking_number_uuid' => '88888888-8888-4888-8888-888888888801',
        'status'               => 'In Transit',
        'code'                 => 'IN_TRANSIT',
        'details'              => 'Package moving',
    ]);
    expect($status)->toBeInstanceOf(TrackingStatus::class)
        ->and($connection->table('tracking_statuses')->count())->toBe(1);

    $publicId = (string) $connection->table('tracking_statuses')->value('public_id');
    $found    = $helper('findTrackingStatus', $publicId);
    expect($found)->toBeInstanceOf(TrackingStatus::class)
        ->and($found->code)->toBe('IN_TRANSIT');

    // Resource wrappers and the json helper
    expect($helper('trackingStatusResource', $found))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\TrackingStatus::class)
        ->and($helper('trackingStatusResourceCollection', collect([$found])))->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class)
        ->and($helper('jsonResponse', ['ok' => true], 200))->toBeInstanceOf(Illuminate\Http\JsonResponse::class);
    $deleted = $helper('deletedTrackingStatusResource', $found);
    expect($deleted)->not->toBeNull();
});
