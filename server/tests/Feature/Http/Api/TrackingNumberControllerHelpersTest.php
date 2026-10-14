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

    // Qr model resolution goes through the shared resolver and wraps the owner in its
    // fleet-ops v1 resource.
    $connection->table('tracking_numbers')->insert([
        'uuid'            => '44444444-4444-4444-8444-444444444404',
        'public_id'       => 'track_tnhelper2',
        'company_uuid'    => 'company-1',
        'tracking_number' => 'FLB-HELPER-2',
        'owner_uuid'      => '44444444-4444-4444-8444-444444444402',
        'owner_type'      => Fleetbase\FleetOps\Models\Entity::class,
    ]);

    $qrModel = $helper('findQrModel', 'https://console.fleetbase.test/track-order?order=FLB-HELPER-2&r=entity_tnhelper1&v=1');
    expect($qrModel)->toBeInstanceOf(Fleetbase\FleetOps\Models\Entity::class)
        ->and($helper('qrModelResource', $qrModel))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Entity::class)
        ->and($helper('findQrModel', '{{qr_code}}'))->toBeNull();
});

test('tracking number owners resolve from every code format within the company', function () {
    $connection = fleetopsTrackingNumberHelpersBoot();
    $connection->table('orders')->insert(['uuid' => '55555555-5555-4555-8555-555555555501', 'public_id' => 'order_codes1', 'company_uuid' => 'company-1', 'status' => 'created']);
    $connection->table('entities')->insert(['uuid' => '55555555-5555-4555-8555-555555555502', 'public_id' => 'entity_codes1', 'company_uuid' => 'company-1', 'name' => 'Parcel']);
    $connection->table('orders')->insert(['uuid' => '55555555-5555-4555-8555-555555555503', 'public_id' => 'order_codes2', 'company_uuid' => 'company-2', 'status' => 'created']);
    $connection->table('tracking_numbers')->insert([
        ['uuid' => 'tn-codes-1', 'public_id' => 'track_codes1', 'company_uuid' => 'company-1', 'tracking_number' => 'ACM1111111111SG', 'owner_uuid' => '55555555-5555-4555-8555-555555555501', 'owner_type' => Fleetbase\FleetOps\Models\Order::class],
        // Entity::insertGetUuid() inserts raw, storing the owner class with a leading slash.
        ['uuid' => 'tn-codes-2', 'public_id' => 'track_codes2', 'company_uuid' => 'company-1', 'tracking_number' => 'ACM2222222222SG', 'owner_uuid' => '55555555-5555-4555-8555-555555555502', 'owner_type' => '\\' . Fleetbase\FleetOps\Models\Entity::class],
        ['uuid' => 'tn-codes-3', 'public_id' => 'track_codes3', 'company_uuid' => 'company-2', 'tracking_number' => 'OTH3333333333SG', 'owner_uuid' => '55555555-5555-4555-8555-555555555503', 'owner_type' => Fleetbase\FleetOps\Models\Order::class],
    ]);

    $owner = fn (?string $code, ?string $company = 'company-1') => TrackingNumber::findOwnerByCode($code, $company)?->public_id;

    // Labels in circulation carry the owner's bare uuid.
    expect($owner('55555555-5555-4555-8555-555555555501'))->toBe('order_codes1')
        // Current labels carry a tracking url; the host is irrelevant.
        ->and($owner('https://console.fleetbase.test/track-order?order=ACM1111111111SG&r=order_codes1&v=1'))->toBe('order_codes1')
        ->and($owner('https://old-host.example/track-order?order=ACM2222222222SG&v=1'))->toBe('entity_codes1')
        // The Code 128 barcode, or a value typed by hand: a tracking number or public id.
        ->and($owner('ACM2222222222SG'))->toBe('entity_codes1')
        ->and($owner('entity_codes1'))->toBe('entity_codes1')
        ->and($owner('https://console.fleetbase.test/track-order?r=order_codes1'))->toBe('order_codes1');

    // A url whose two identifiers disagree, or that names an unknown tracking number, is
    // refused rather than resolved through the half that matches.
    expect($owner('https://console.fleetbase.test/track-order?order=ACM1111111111SG&r=entity_codes1&v=1'))->toBeNull()
        ->and($owner('https://console.fleetbase.test/track-order?order=ACM9999999999SG&r=order_codes1&v=1'))->toBeNull()
        // Unknown values, unmapped prefixes, and values that are no public id at all.
        ->and($owner('55555555-5555-4555-8555-555555555599'))->toBeNull()
        ->and($owner('ACM9999999999SG'))->toBeNull()
        ->and($owner('vehicle_codes1'))->toBeNull()
        ->and($owner('order_missing'))->toBeNull()
        ->and($owner(''))->toBeNull();

    // Another company's parcel never resolves, in any format, and without a company
    // nothing does.
    expect($owner('55555555-5555-4555-8555-555555555503'))->toBeNull()
        ->and($owner('OTH3333333333SG'))->toBeNull()
        ->and($owner('order_codes2'))->toBeNull()
        ->and($owner('order_codes2', 'company-2'))->toBe('order_codes2')
        ->and($owner('55555555-5555-4555-8555-555555555501', null))->toBeNull();
});
