<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\ContactController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\PositionController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\TestSupport\DispatchRecorder;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the internal PositionController replay and metrics endpoints and
 * the API ContactController protected helpers against SQLite: replay input
 * guards with job dispatch, metric calculation over stored positions, and
 * contact input normalization, persistence, lookup, and resource wrappers.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Internal\v1\dispatch')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1; function dispatch($job = null) { \Fleetbase\TestSupport\DispatchRecorder::$dispatched[] = $job; return new \Fleetbase\TestSupport\PendingDispatch(); }');
}

class FleetOpsApiContactHelpersProbe extends ContactController
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsPositionReplayBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
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

    $schema = $connection->getSchemaBuilder();
    app()->instance('db.schema', $schema);
    $tables = [
        'positions' => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'destination_uuid', 'coordinates', 'heading', 'bearing', 'speed', 'altitude', 'order_uuid', '_key'],
        'contacts'  => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'place_uuid', 'name', 'title', 'email', 'phone', 'type', 'meta', 'slug', '_key'],
        'users'     => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'type', 'status'],
        'places'    => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'name'],
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
    DispatchRecorder::$dispatched = [];

    return $connection;
}

function fleetopsPositionReplayWkb(float $latitude, float $longitude): string
{
    return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $longitude) . pack('d', $latitude);
}

test('replay validates inputs and dispatches the replay job', function () {
    $connection = fleetopsPositionReplayBoot();
    $controller = new PositionController();

    expect($controller->replay(Request::create('/x', 'POST', []))->getData(true)['error'])->toContain('Channel ID');
    expect($controller->replay(Request::create('/x', 'POST', ['channel_id' => 'chan-1']))->getData(true)['error'])->toContain('Position IDs');
    expect($controller->replay(Request::create('/x', 'POST', ['channel_id' => 'chan-1', 'position_ids' => ['missing']]))->getData(true)['error'])->toContain('No positions found');

    $connection->table('positions')->insert([
        ['uuid' => 'pos-1', 'company_uuid' => 'company-1', 'subject_uuid' => 'driver-1', 'coordinates' => fleetopsPositionReplayWkb(1.30, 103.80), 'speed' => '10', 'created_at' => '2026-07-28 08:00:00'],
        ['uuid' => 'pos-2', 'company_uuid' => 'company-1', 'subject_uuid' => 'driver-1', 'coordinates' => fleetopsPositionReplayWkb(1.31, 103.81), 'speed' => '12', 'created_at' => '2026-07-28 08:00:10'],
    ]);

    $response = $controller->replay(Request::create('/x', 'POST', ['channel_id' => 'chan-1', 'position_ids' => ['pos-1', 'pos-2'], 'speed' => 2]));

    expect($response->getData(true)['status'])->toBe('ok')
        ->and($response->getData(true)['total_positions'])->toBe(2)
        ->and(DispatchRecorder::$dispatched)->toHaveCount(1);
});

test('metrics validates inputs and calculates over stored positions', function () {
    $connection = fleetopsPositionReplayBoot();
    $controller = new PositionController();

    expect($controller->metrics(Request::create('/x', 'POST', []))->getData(true)['error'])->toContain('Position IDs');
    expect($controller->metrics(Request::create('/x', 'POST', ['position_ids' => ['missing']]))->getData(true)['metrics'])->toBe([]);

    $connection->table('positions')->insert([
        ['uuid' => 'pos-1', 'company_uuid' => 'company-1', 'coordinates' => fleetopsPositionReplayWkb(1.30, 103.80), 'speed' => '10', 'altitude' => '5', 'created_at' => '2026-07-28 08:00:00'],
        ['uuid' => 'pos-2', 'company_uuid' => 'company-1', 'coordinates' => fleetopsPositionReplayWkb(1.31, 103.81), 'speed' => '20', 'altitude' => '15', 'created_at' => '2026-07-28 08:10:00'],
    ]);

    $metrics = $controller->metrics(Request::create('/x', 'POST', ['position_ids' => ['pos-1', 'pos-2']]))->getData(true)['metrics'];

    expect($metrics)->toBeArray()->not->toBeEmpty();
});

test('contact helpers normalize input persist and resolve records', function () {
    $connection = fleetopsPositionReplayBoot();
    $probe      = new FleetOpsApiContactHelpersProbe();

    $input = $probe->callHelper('contactCreateInputFromRequest', Request::create('/x', 'POST', ['name' => 'Api Contact', 'phone' => '+65 9123 4567']));
    expect($input['type'])->toBe('contact')
        ->and($input['name'])->toBe('Api Contact');

    $updateInput = $probe->callHelper('contactUpdateInputFromRequest', Request::create('/x', 'POST', ['name' => 'Renamed', 'title' => 'Ops']));
    expect($updateInput)->toBe(['name' => 'Renamed', 'title' => 'Ops']);

    expect($probe->callHelper('newContact', [['name' => 'Fresh']]))->toBeInstanceOf(Contact::class);

    $contact = $probe->callHelper('updateOrCreateContact', ['email' => 'api@example.test'], ['company_uuid' => 'company-1', 'name' => 'Upserted', 'type' => 'contact']);
    expect($contact)->toBeInstanceOf(Contact::class)
        ->and($connection->table('contacts')->count())->toBe(1);

    $connection->table('contacts')->update(['public_id' => 'contact_api1234']);
    $found = $probe->callHelper('findContact', 'contact_api1234');
    expect($found)->toBeInstanceOf(Contact::class);

    expect($probe->callHelper('getPlaceUuid', 'places', ['uuid' => 'missing']))->toBeNull()
        ->and($probe->callHelper('findRelatedUser', $contact))->toBeNull()
        ->and($probe->callHelper('contactResource', $contact))->not->toBeNull()
        ->and($probe->callHelper('contactResourceCollection', collect([$contact])))->not->toBeNull()
        ->and($probe->callHelper('deletedContactResource', $contact))->not->toBeNull();

    $json = $probe->callHelper('jsonResponse', ['ok' => true], 201);
    expect($json->getStatusCode())->toBe(201);

    $error = $probe->callHelper('apiError', 'nope', 422);
    expect($error->getStatusCode())->toBe(422);
});
