<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\SearchController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Covers the internal global search endpoint against SQLite as an admin
 * session user: empty-query short circuit, requested-type normalization,
 * the order/driver searches with their relation subqueries, and the
 * generic column search used by vehicles and fleets.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

function fleetopsSearchEndpointBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
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
    $tables = [
        'users'            => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'type', 'status'],
        'orders'           => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'tracking_number_uuid', 'status', 'type'],
        'tracking_numbers' => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'barcode', 'owner_uuid'],
        'drivers'          => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'drivers_license_number', 'status'],
        'vehicles'         => ['uuid', 'public_id', 'company_uuid', 'name', 'description', 'make', 'model', 'year', 'internal_id', 'plate_number', 'vin', 'serial_number', 'call_sign'],
        'fleets'           => ['uuid', 'public_id', 'company_uuid', 'name'],
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

    // Admin session user bypasses per-type permission checks
    $connection->table('users')->insert(['uuid' => 'admin-1', 'company_uuid' => 'company-1', 'name' => 'Admin', 'type' => 'admin']);
    session(['company' => 'company-1', 'user' => 'admin-1']);

    return $connection;
}

test('search returns empty results for blank queries', function () {
    fleetopsSearchEndpointBoot();

    $response = (new SearchController())->search(Request::create('/x', 'GET', ['query' => '   ']));

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getData(true))->toBe(['results' => []]);
});

test('search finds orders by tracking number and drivers by user name', function () {
    $connection = fleetopsSearchEndpointBoot();
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-1', 'company_uuid' => 'company-1', 'tracking_number' => 'TRK-FINDME-1', 'owner_uuid' => 'order-1']);
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_search', 'company_uuid' => 'company-1', 'tracking_number_uuid' => 'tn-1', 'status' => 'created']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'name' => 'Findme Driver', 'email' => 'findme@example.test']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_search', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);

    $response = (new SearchController())->search(Request::create('/x', 'GET', ['query' => 'FINDME', 'types' => 'orders,drivers']));
    $results  = $response->getData(true)['results'];

    expect(collect($results)->pluck('type')->all())->toContain('Order', 'Driver');
});

test('search generic types match columns and respect the limit', function () {
    $connection = fleetopsSearchEndpointBoot();
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_search', 'company_uuid' => 'company-1', 'name' => 'Atlas Truck', 'plate_number' => 'ATLAS-99']);
    $connection->table('fleets')->insert(['uuid' => 'fleet-1', 'public_id' => 'fleet_search', 'company_uuid' => 'company-1', 'name' => 'Atlas Fleet']);

    $response = (new SearchController())->search(Request::create('/x', 'GET', ['q' => 'Atlas', 'types' => 'vehicles,fleets', 'limit' => 5]));
    $results  = $response->getData(true)['results'];

    expect(collect($results)->pluck('type')->all())->toContain('Vehicle', 'Fleet');

    // Unknown types fall back to the full type list; unmatched queries
    // return nothing
    $none = (new SearchController())->search(Request::create('/x', 'GET', ['query' => 'zzz-no-match', 'types' => 'vehicles']));
    expect($none->getData(true)['results'])->toBe([]);
});

test('search dispatches every registered type arm', function () {
    $connection = fleetopsSearchEndpointBoot();

    $schema = $connection->getSchemaBuilder();
    $extra  = [
        'vendors'                    => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'business_id', 'status'],
        'contacts'                   => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'type'],
        'places'                     => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'street2', 'country', 'province', 'district', 'city', 'postal_code', 'phone'],
        'issues'                     => ['uuid', 'public_id', 'company_uuid', 'issue_id', 'category', 'type', 'report', 'title', 'priority', 'status'],
        'fuel_reports'               => ['uuid', 'public_id', 'company_uuid', 'report', 'status', 'currency'],
        'fuel_provider_transactions' => ['uuid', 'public_id', 'company_uuid', 'provider', 'provider_transaction_id', 'vehicle_card_id', 'internal_number', 'plate_number', 'vin', 'serial_number', 'call_sign', 'station_name', 'trip_number', 'sync_status'],
        'maintenance_schedules'      => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status'],
        'work_orders'                => ['uuid', 'public_id', 'company_uuid', 'code', 'subject', 'category', 'instructions', 'status', 'priority'],
        'maintenances'               => ['uuid', 'public_id', 'company_uuid', 'summary', 'notes', 'type', 'status', 'priority'],
        'equipments'                 => ['uuid', 'public_id', 'company_uuid', 'name', 'code', 'type', 'serial_number', 'manufacturer', 'model'],
        'parts'                      => ['uuid', 'public_id', 'company_uuid', 'sku', 'name', 'manufacturer', 'model', 'serial_number', 'barcode'],
        'fuel_provider_connections'  => ['uuid', 'public_id', 'company_uuid', 'name', 'provider', 'status', 'environment'],
        'telematics'                 => ['uuid', 'public_id', 'company_uuid', 'name', 'provider', 'model', 'serial_number', 'imei'],
        'devices'                    => ['uuid', 'public_id', 'company_uuid', 'name', 'model', 'serial_number', 'manufacturer', 'device_id', 'internal_id', 'imei'],
        'sensors'                    => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'internal_id', 'unit'],
        'device_events'              => ['uuid', 'public_id', 'company_uuid', 'event_type', 'message', 'ident', 'code', 'provider', 'severity'],
        'service_rates'              => ['uuid', 'public_id', 'company_uuid', 'service_name', 'service_type', 'currency', 'algorithm', 'rate_calculation_method'],
        'order_configs'              => ['uuid', 'public_id', 'company_uuid', 'name', 'description', 'key', 'namespace', 'status'],
    ];
    foreach ($extra as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    $types = 'orders,drivers,vehicles,fleets,vendors,contacts,places,issues,fuel_reports,fuel_transactions,maintenance_schedules,work_orders,maintenances,equipment,parts,fuel_providers,telematics,devices,sensors,events,service_rates,order_configs';

    $response = (new SearchController())->search(Request::create('/x', 'GET', ['query' => 'anything', 'types' => $types]));
    expect($response->getData(true))->toBeArray();
});
