<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\PayloadController;
use Fleetbase\FleetOps\Models\Payload;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the PayloadController protected helper bodies against SQLite:
 * payload construction and lookup, input whitelisting, resource wrappers
 * and the json response helper.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

function fleetopsPayloadHelpersBoot(): SQLiteConnection
{
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
        'payloads'  => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'type', 'provider', 'cod_amount', 'cod_currency', 'cod_payment_method', 'meta', '_key'],
        'places'    => ['uuid', 'public_id', 'company_uuid', 'name', 'location', '_key'],
        'waypoints' => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'order', 'type', 'status', '_key'],
        'entities'  => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'name', 'type', 'meta', '_key'],
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

test('payload helpers whitelist input build lookup and wrap resources', function () {
    $connection = fleetopsPayloadHelpersBoot();
    $connection->table('payloads')->insert(['uuid' => '66666666-6666-4666-8666-666666666801', 'public_id' => 'payload_helperone', 'company_uuid' => 'company-1', 'type' => 'transport']);

    $controller = new PayloadController();
    $helper     = function (string $method, ...$arguments) use ($controller) {
        $reflection = new ReflectionMethod(PayloadController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$arguments);
    };

    // Fill input whitelisting keeps only payload columns
    $filled = $helper('payloadFillInputFromInput', ['type' => 'transport', 'provider' => 'internal', 'cod_amount' => '25', 'rogue' => 'value']);
    expect($filled)->toBe(['type' => 'transport', 'provider' => 'internal', 'cod_amount' => '25']);

    // Payload construction and lookups
    expect($helper('newPayload', ['type' => 'transport']))->toBeInstanceOf(Payload::class);
    $found = $helper('findPayloadOrFail', 'payload_helperone');
    expect($found)->toBeInstanceOf(Payload::class)
        ->and($found->uuid)->toBe('66666666-6666-4666-8666-666666666801');

    // Resource wrappers and the json helper
    expect($helper('payloadResource', $found))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Payload::class)
        ->and($helper('payloadResourceCollection', collect([$found])))->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class)
        ->and($helper('jsonResponse', ['ok' => true], 200))->toBeInstanceOf(Illuminate\Http\JsonResponse::class);
    $deleted = $helper('deletedPayloadResource', $found);
    expect($deleted)->not->toBeNull();
});
