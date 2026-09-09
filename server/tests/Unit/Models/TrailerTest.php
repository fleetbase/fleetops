<?php

use Fleetbase\FleetOps\Events\TrailerLocationChanged;
use Fleetbase\FleetOps\Models\AssetConnection;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\WorkOrder;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

// Model identifier hooks bind to the dispatcher present when the class boots.
if (!EloquentModel::getEventDispatcher()) {
    EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
}

function fleetOpsTrailerUseInMemoryConnection(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
    $connection->statement('create table assets (
        id integer primary key autoincrement,
        uuid varchar(64),
        public_id varchar(64) null unique,
        company_uuid varchar(64),
        name varchar(255),
        code varchar(64) null,
        type varchar(64) null,
        asset_class varchar(64) null,
        status varchar(64) null,
        slug varchar(255) null,
        last_online_at datetime null,
        created_at datetime null,
        updated_at datetime null,
        deleted_at datetime null
    )');
    $connection->statement('create table positions (
        id integer primary key autoincrement,
        uuid varchar(64), public_id varchar(64), company_uuid varchar(64),
        subject_uuid varchar(64), subject_type varchar(255), destination_uuid varchar(64),
        coordinates text, heading varchar(64), bearing varchar(64), speed varchar(64), altitude varchar(64),
        created_at datetime null, updated_at datetime null, deleted_at datetime null
    )');

    $resolver = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });

    return $connection;
}

test('trailer creation enforces its discriminator defaults and public id namespace', function () {
    fleetOpsTrailerUseInMemoryConnection();

    $trailer = new Trailer([
        'company_uuid'  => 'company-a',
        'name'          => 'Reefer 12',
        'asset_class'   => 'vehicle',
    ]);
    Trailer::getEventDispatcher()->until('eloquent.creating: ' . Trailer::class, $trailer);

    expect($trailer->asset_class)->toBe(Trailer::ASSET_CLASS)
        ->and($trailer->status)->toBe('available')
        ->and($trailer->public_id)->toStartWith('trailer_')
        ->and($trailer->uuid)->not->toBeEmpty();

    EloquentModel::unsetEventDispatcher();
});

test('trailer global scope never returns another asset class', function () {
    $connection = fleetOpsTrailerUseInMemoryConnection();
    $connection->table('assets')->insert([
        ['uuid' => 'trailer-uuid', 'public_id' => 'trailer_public', 'company_uuid' => 'company-a', 'name' => 'Trailer', 'asset_class' => 'trailer', 'status' => 'available'],
        ['uuid' => 'vehicle-uuid', 'public_id' => 'asset_public', 'company_uuid' => 'company-a', 'name' => 'Vehicle-like asset', 'asset_class' => 'vehicle', 'status' => 'available'],
    ]);

    expect(Trailer::query()->pluck('uuid')->all())->toBe(['trailer-uuid'])
        ->and(Trailer::query()->toSql())->toContain('asset_class');
});

test('trailer exposes first class connection device equipment maintenance and position relations', function () {
    fleetOpsTrailerUseInMemoryConnection();
    $trailer = new Trailer();

    expect($trailer->currentConnection())->toBeInstanceOf(HasOne::class)
        ->and($trailer->currentConnection()->getRelated())->toBeInstanceOf(AssetConnection::class)
        ->and($trailer->connections())->toBeInstanceOf(HasMany::class)
        ->and($trailer->devices()->getRelated())->toBeInstanceOf(Device::class)
        ->and($trailer->equipments()->getRelated())->toBeInstanceOf(Equipment::class)
        ->and($trailer->positions()->getRelated())->toBeInstanceOf(Position::class)
        ->and($trailer->maintenances()->getRelated())->toBeInstanceOf(Maintenance::class)
        ->and($trailer->maintenanceSchedules()->getRelated())->toBeInstanceOf(MaintenanceSchedule::class)
        ->and($trailer->workOrders()->getRelated())->toBeInstanceOf(WorkOrder::class);
});

test('asset connections preserve effective dated vehicle and trailer identities', function () {
    fleetOpsTrailerUseInMemoryConnection();
    $connection = new AssetConnection([
        'relationship_type' => 'towing',
        'position'          => 2,
        'connected_at'      => '2026-09-03 12:00:00',
        'disconnected_at'   => null,
    ]);

    expect($connection->vehicle())->toBeInstanceOf(BelongsTo::class)
        ->and($connection->vehicle()->getRelated())->toBeInstanceOf(Vehicle::class)
        ->and($connection->trailer())->toBeInstanceOf(BelongsTo::class)
        ->and($connection->trailer()->getRelated())->toBeInstanceOf(Trailer::class)
        ->and($connection->relationship_type)->toBe('towing')
        ->and($connection->position)->toBe(2)
        ->and($connection->disconnected_at)->toBeNull();
});

test('vehicles expose current and historical trailer relationships', function () {
    fleetOpsTrailerUseInMemoryConnection();
    $vehicle = new Vehicle();

    expect($vehicle->trailerConnections())->toBeInstanceOf(HasMany::class)
        ->and($vehicle->trailerConnections()->getRelated())->toBeInstanceOf(AssetConnection::class)
        ->and($vehicle->currentTrailers())->toBeInstanceOf(HasManyThrough::class)
        ->and($vehicle->currentTrailers()->getRelated())->toBeInstanceOf(Trailer::class);
});

test('trailer connectivity is distinct from lifecycle and attachment state', function () {
    Carbon::setTestNow('2026-09-03 12:00:00');

    $trailer = new Trailer(['status' => 'maintenance']);
    expect($trailer->connectivity_status)->toBe('never_connected')
        ->and($trailer->status)->toBe('maintenance')
        ->and($trailer->attachment_state)->toBe('detached');

    $vehicle    = new Vehicle();
    $connection = new AssetConnection();
    $connection->setRelation('vehicle', $vehicle);
    $trailer->setRelation('currentConnection', $connection);
    expect($trailer->current_vehicle)->toBe($vehicle)
        ->and($trailer->attachment_state)->toBe('attached');

    $trailer->last_online_at = Carbon::now()->subMinutes(5);
    expect($trailer->connectivity_status)->toBe('online');

    $trailer->last_online_at = Carbon::now()->subHours(2);
    expect($trailer->connectivity_status)->toBe('recently_offline');

    $trailer->last_online_at = Carbon::now()->subDays(2);
    expect($trailer->connectivity_status)->toBe('offline');

    Carbon::setTestNow();
});

test('trailer positions require coordinates and preserve model or scalar destinations', function () {
    fleetOpsTrailerUseInMemoryConnection();
    $trailer = new Trailer();
    $trailer->forceFill(['uuid' => 'trailer-position', 'company_uuid' => 'company-a']);
    expect($trailer->createPosition())->toBeNull();

    $vehicle = new Vehicle();
    $vehicle->forceFill(['uuid' => 'vehicle-destination']);
    $fromModel  = $trailer->createPosition(['latitude' => 1.3, 'longitude' => 103.8, 'heading' => 90, 'bearing' => 91, 'speed' => 40, 'altitude' => 12], $vehicle);
    $fromScalar = $trailer->createPosition(['latitude' => 1.4, 'longitude' => 103.9], 'place-destination');
    expect($fromModel)->toBeInstanceOf(Position::class)
        ->and($fromModel->destination_uuid)->toBe('vehicle-destination')
        ->and($fromScalar->destination_uuid)->toBe('place-destination');
});

test('trailer location broadcasts use tenant and trailer channels without exposing internal ids', function () {
    Carbon::setTestNow('2026-09-03 12:00:00');

    $trailer = new Trailer();
    $trailer->forceFill([
        'uuid'         => 'internal-trailer-uuid',
        'public_id'    => 'trailer_public',
        'company_uuid' => 'company-a',
        'speed'        => 42,
        'heading'      => 180,
    ]);
    $event = new TrailerLocationChanged($trailer, ['source' => 'telematics']);

    expect(array_map(fn ($channel) => $channel->name, $event->broadcastOn()))
        ->toBe(['company.company-a', 'trailer.trailer_public', 'trailer.internal-trailer-uuid'])
        ->and($event->broadcastWith()['data']['id'])->toBe('trailer_public')
        ->and($event->broadcastWith()['data'])->not->toHaveKey('uuid')
        ->and($event->broadcastWith()['data']['additionalData'])->toBe(['source' => 'telematics']);

    Carbon::setTestNow();
});
