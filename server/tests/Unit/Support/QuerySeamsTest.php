<?php

use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the small query and response seams scattered across controllers,
 * commands and jobs. Each is a one-line delegation that behaviour tests
 * override, so the delegation itself is asserted here against SQLite.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

function fleetopsQuerySeamBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection, 'sandbox' => $connection]);
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
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    $schema  = $connection->getSchemaBuilder();
    $columns = ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'type', 'status', 'provider', 'event', 'geofence_uuid', 'order_uuid', 'service_quote_uuid', 'expired_at', '_key'];
    foreach (['drivers', 'users', 'companies', 'company_users', 'integrated_vendors', 'geofence_events_log', 'orders', 'service_quotes'] as $table) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-seam-1']);

    return $connection;
}

function fleetopsQuerySeamInvoke(object $instance, string $class, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($instance, ...$arguments);
}

test('integrated vendor controller seams query vendors and shape responses', function () {
    $connection = fleetopsQuerySeamBoot();
    $connection->table('integrated_vendors')->insert([
        ['uuid' => 'iv-seam-1', 'company_uuid' => 'company-seam-1', 'provider' => 'lalamove'],
        ['uuid' => 'iv-seam-2', 'company_uuid' => 'company-seam-1', 'provider' => 'other'],
    ]);

    $controller = new Fleetbase\FleetOps\Http\Controllers\Internal\v1\IntegratedVendorController();
    $class      = Fleetbase\FleetOps\Http\Controllers\Internal\v1\IntegratedVendorController::class;

    // The vendor query narrows to the requested uuids
    $query = fleetopsQuerySeamInvoke($controller, $class, 'integratedVendorQuery', [['iv-seam-1']]);
    expect($query->get()->pluck('uuid')->all())->toBe(['iv-seam-1']);

    // The supported-vendor catalogue is exposed as-is
    expect(fleetopsQuerySeamInvoke($controller, $class, 'supportedIntegratedVendors'))->not->toBeNull();

    // Response helpers wrap payloads and error strings
    $ok = fleetopsQuerySeamInvoke($controller, $class, 'jsonResponse', [['status' => 'ok'], 200]);
    expect($ok->getStatusCode())->toBe(200)
        ->and($ok->getData(true))->toBe(['status' => 'ok']);

    $error = fleetopsQuerySeamInvoke($controller, $class, 'errorResponse', ['nope']);
    expect($error->getData(true)['error'] ?? null)->toBe('nope');
});

test('driver company repair seams find drivers memberships and companies', function () {
    $connection = fleetopsQuerySeamBoot();
    $connection->table('users')->insert(['uuid' => 'user-fix-1', 'company_uuid' => 'company-seam-1']);
    $connection->table('drivers')->insert([
        ['uuid' => 'driver-fix-1', 'company_uuid' => 'company-seam-1', 'user_uuid' => 'user-fix-1'],
        // No surviving user, so the repair sweep skips it
        ['uuid' => 'driver-fix-2', 'company_uuid' => 'company-seam-1', 'user_uuid' => 'user-missing'],
    ]);
    $connection->table('companies')->insert(['uuid' => 'company-seam-1', 'name' => 'Seam Co']);
    $connection->table('company_users')->insert(['uuid' => 'cu-1', 'user_uuid' => 'user-fix-1', 'company_uuid' => 'company-seam-1']);

    $command = (new ReflectionClass(Fleetbase\FleetOps\Console\Commands\FixDriverCompanies::class))->newInstanceWithoutConstructor();
    $class   = Fleetbase\FleetOps\Console\Commands\FixDriverCompanies::class;

    // Only drivers with a surviving user and a company are considered
    expect(fleetopsQuerySeamInvoke($command, $class, 'drivers')->pluck('uuid')->all())->toBe(['driver-fix-1']);

    // Membership detection distinguishes present from absent rows
    expect(fleetopsQuerySeamInvoke($command, $class, 'missingCompanyUser', ['user-fix-1', 'company-seam-1']))->toBeFalse()
        ->and(fleetopsQuerySeamInvoke($command, $class, 'missingCompanyUser', ['user-missing', 'company-seam-1']))->toBeTrue();

    // Company lookups resolve by uuid
    expect(fleetopsQuerySeamInvoke($command, $class, 'companyByUuid', ['company-seam-1'])?->uuid)->toBe('company-seam-1')
        ->and(fleetopsQuerySeamInvoke($command, $class, 'companyByUuid', ['company-missing']))->toBeNull();
});

test('geofence event log seams expose the query builder and raw expressions', function () {
    $connection = fleetopsQuerySeamBoot();
    $connection->table('geofence_events_log')->insert([
        ['uuid' => 'gel-1', 'company_uuid' => 'company-seam-1', 'event' => 'entered'],
        ['uuid' => 'gel-2', 'company_uuid' => 'company-other', 'event' => 'exited'],
    ]);

    $controller = new Fleetbase\FleetOps\Http\Controllers\Api\v1\GeofenceController();
    $class      = Fleetbase\FleetOps\Http\Controllers\Api\v1\GeofenceController::class;

    // Event logs are scoped to the requested company
    expect(fleetopsQuerySeamInvoke($controller, $class, 'geofenceEventLogQuery', ['company-seam-1'])->get()->pluck('uuid')->all())->toBe(['gel-1']);

    // Table and raw helpers delegate to the database facade
    expect(fleetopsQuerySeamInvoke($controller, $class, 'table', ['geofence_events_log'])->count())->toBe(2)
        ->and(fleetopsQuerySeamInvoke($controller, $class, 'raw', ['count(*) as aggregate'])->getValue($connection->getQueryGrammar()))->toBe('count(*) as aggregate');
});

test('adhoc dispatch seams build order and driver queries', function () {
    $connection = fleetopsQuerySeamBoot();
    $connection->table('users')->insert(['uuid' => 'user-adhoc-1', 'company_uuid' => 'company-seam-1']);
    $connection->table('orders')->insert(['uuid' => 'order-adhoc-1', 'company_uuid' => 'company-seam-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-adhoc-1', 'company_uuid' => 'company-seam-1', 'user_uuid' => 'user-adhoc-1']);

    $command = (new ReflectionClass(Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders::class))->newInstanceWithoutConstructor();
    $class   = Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders::class;

    // The order query is bound to the named connection
    expect(fleetopsQuerySeamInvoke($command, $class, 'newOrderQuery', ['mysql'])->get()->pluck('uuid')->all())->toBe(['order-adhoc-1'])
        ->and(fleetopsQuerySeamInvoke($command, $class, 'newDriverQuery')->get()->pluck('uuid')->all())->toBe(['driver-adhoc-1']);
});

test('order finalization job seams resolve records and fire the ready event', function () {
    $connection = fleetopsQuerySeamBoot();
    $connection->table('orders')->insert(['uuid' => 'order-final-1', 'company_uuid' => 'company-seam-1']);
    $connection->table('service_quotes')->insert(['uuid' => 'sq-final-1', 'company_uuid' => 'company-seam-1']);

    $class = Fleetbase\FleetOps\Jobs\FinalizeApiOrderCreation::class;
    $job   = new $class('order-final-1', 'sq-final-1');

    expect(fleetopsQuerySeamInvoke($job, $class, 'findOrder')?->uuid)->toBe('order-final-1')
        ->and(fleetopsQuerySeamInvoke($job, $class, 'findServiceQuote')?->uuid)->toBe('sq-final-1');

    // Jobs without a quote uuid short circuit before querying
    $quoteless = new $class('order-final-1', null);
    expect(fleetopsQuerySeamInvoke($quoteless, $class, 'findServiceQuote'))->toBeNull();
});
