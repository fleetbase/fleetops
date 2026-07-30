<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\TrackingNumberController;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the TrackingNumberController protected helper bodies against
 * SQLite: owner uuid lookups, tracking number creation and lookup,
 * resource wrappers, qr-model resolution and the json response helper.
 */
if (!function_exists('Fleetbase\\Support\\session')) {
    eval('namespace Fleetbase\\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \\session($k) !== null; } public function get($k, $d = null) { return \\session($k, $d); } }; } return \\session($key, $default); }');
}

if (!function_exists('Fleetbase\\Support\\auth')) {
    eval('namespace Fleetbase\\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\\Observers\\event')) {
    eval('namespace Fleetbase\\Observers; function event($event = null, $payload = []) { return []; }');
}

function fleetopsTrackingNumberHelpersBoot(): SQLiteConnection
{
    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
    }
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
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
        'tracking_numbers'  => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', 'region', 'barcode', 'qr_code', 'status_uuid', 'type', '_key'],
        'entities'          => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'internal_id', 'tracking_number_uuid', 'payload_uuid', 'customer_uuid', 'customer_type', 'meta', '_key'],
        'orders'            => ['uuid', 'public_id', 'company_uuid', 'internal_id', 'payload_uuid', 'status', 'type', 'meta', '_key'],
        'tracking_statuses' => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'code', 'status', 'details', 'location', 'city', 'province', 'country', '_key'],
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

test('tracking number helpers look up owners create records and wrap resources', function () {
    $connection = fleetopsTrackingNumberHelpersBoot();
    $connection->table('orders')->insert(['uuid' => '44444444-4444-4444-8444-444444444401', 'public_id' => 'order_tnhelper1', 'company_uuid' => 'company-1', 'status' => 'created']);
    $connection->table('entities')->insert(['uuid' => '44444444-4444-4444-8444-444444444402', 'public_id' => 'entity_tnhelper1', 'company_uuid' => 'company-1', 'name' => 'Tracked Entity']);

    $controller = new TrackingNumberController();
    $helper     = function (string $method, ...$arguments) use ($controller) {
        $reflection = new ReflectionMethod(TrackingNumberController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$arguments);
    };

    // Owner uuid resolution reads through Utils::getUuid
    $ownerUuid = $helper('getOwnerUuid', ['orders'], ['public_id' => 'order_tnhelper1', 'company_uuid' => 'company-1'], []);
    expect($ownerUuid)->toBe('44444444-4444-4444-8444-444444444401');

    // Creation persists a tracking number row
    $trackingNumber = $helper('createTrackingNumber', [
        'company_uuid'    => 'company-1',
        'tracking_number' => 'FLB-HELPER-1',
        'owner_uuid'      => '44444444-4444-4444-8444-444444444401',
        'owner_type'      => Fleetbase\FleetOps\Models\Order::class,
        'region'          => 'SG',
    ]);
    expect($trackingNumber)->toBeInstanceOf(TrackingNumber::class)
        ->and($connection->table('tracking_numbers')->count())->toBe(1);

    // Lookup finds persisted tracking numbers by uuid
    $uuid  = (string) $connection->table('tracking_numbers')->value('uuid');
    $found = $helper('findTrackingNumber', $uuid);
    expect($found)->toBeInstanceOf(TrackingNumber::class)
        ->and($found->tracking_number)->toBe('FLB-HELPER-1');

    // Resource wrappers and the json helper
    expect($helper('trackingNumberResource', $found))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\TrackingNumber::class)
        ->and($helper('trackingNumberResourceCollection', collect([$found])))->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class)
        ->and($helper('deletedTrackingNumberResource', $found))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\DeletedResource::class)
        ->and($helper('jsonResponse', ['ok' => true], 200))->toBeInstanceOf(Illuminate\Http\JsonResponse::class);

    // Qr model resolution finds models and wraps them in fleet-ops v1 resources
    $qrModel = $helper('findQrModel', ['entities'], ['public_id' => 'entity_tnhelper1']);
    expect($qrModel)->not->toBeNull()
        ->and($helper('qrModelResource', $qrModel))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Entity::class);

    // Tables with no eloquent mapping are skipped and fall back to a raw row
    // lookup, so the record still resolves but arrives unhydrated
    $connection->table('tracking_statuses')->insert([
        'uuid'      => '44444444-4444-4444-8444-444444444403',
        'public_id' => 'tracking_status_tnhelper1',
        'code'      => 'DISPATCHED',
        'status'    => 'Dispatched',
    ]);

    $unmapped = $helper('findQrModel', ['tracking_statuses'], ['public_id' => 'tracking_status_tnhelper1']);
    expect($unmapped)->not->toBeNull()
        ->and($unmapped)->not->toBeInstanceOf(EloquentModel::class)
        ->and($unmapped->uuid)->toBe('44444444-4444-4444-8444-444444444403');
});
