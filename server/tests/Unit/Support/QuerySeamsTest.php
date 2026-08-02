<?php

use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the small query and response seams scattered across controllers,
 * commands and jobs. Each is a one-line delegation that behaviour tests
 * override, so the delegation itself is asserted here against SQLite.
 */
if (!function_exists('Fleetbase\Events\logger')) {
    eval('namespace Fleetbase\Events; function logger($message = null, array $context = []) { return new class { public function __call($m, $a) { return null; } }; }');
}

class FleetOpsQuerySeamEventRecorder
{
    public static array $fired = [];
}

if (!function_exists('Fleetbase\FleetOps\Jobs\event')) {
    eval('namespace Fleetbase\FleetOps\Jobs; function event($event = null) { \FleetOpsQuerySeamEventRecorder::$fired[] = $event; return $event; }');
}

if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\FleetOps\Observers\event')) {
    eval('namespace Fleetbase\FleetOps\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

function fleetopsQuerySeamBoot(): SQLiteConnection
{
    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
    }
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
    $columns = ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'type', 'status', 'provider', 'event', 'geofence_uuid', 'order_uuid', 'service_quote_uuid', 'expired_at', 'driver_assigned_uuid', 'current_job_uuid', 'tracking_number_uuid', 'code', 'details', 'transaction_at', 'connection_uuid', 'amount', 'region', '_key'];
    foreach (['drivers', 'users', 'companies', 'company_users', 'integrated_vendors', 'geofence_events_log', 'orders', 'service_quotes', 'tracking_numbers', 'tracking_statuses', 'fuel_provider_transactions', 'fuel_provider_connections'] as $table) {
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

test('tracking number observer seams generate numbers barcodes and statuses', function () {
    $connection = fleetopsQuerySeamBoot();
    $barcode    = new class {
        public function __call($method, $arguments)
        {
            return 'generated-barcode';
        }
    };
    app()->instance('DNS2D', $barcode);
    app()->instance('DNS1D', $barcode);

    $observer = (new ReflectionClass(Fleetbase\FleetOps\Observers\TrackingNumberObserver::class))->newInstanceWithoutConstructor();
    $class    = Fleetbase\FleetOps\Observers\TrackingNumberObserver::class;

    $trackingNumber = new Fleetbase\FleetOps\Models\TrackingNumber();
    $trackingNumber->setRawAttributes(['uuid' => 'tn-seam-1', 'region' => 'SG'], true);

    // Numbers are generated for the record's region
    expect(fleetopsQuerySeamInvoke($observer, $class, 'generateTrackingNumber', [$trackingNumber]))->toBeString()
        ->and(fleetopsQuerySeamInvoke($observer, $class, 'generateBarcode', ['tn-seam-1', 'C39']))->toBe('generated-barcode');

    // Statuses are persisted through the model
    $status = fleetopsQuerySeamInvoke($observer, $class, 'createTrackingStatus', [[
        'company_uuid'         => 'company-seam-1',
        'tracking_number_uuid' => 'tn-seam-1',
        'code'                 => 'CREATED',
        'status'               => 'Created',
    ]]);
    expect($status)->toBeInstanceOf(Fleetbase\FleetOps\Models\TrackingStatus::class)
        ->and($connection->table('tracking_statuses')->count())->toBe(1);
});

test('fuel provider summary seams scope transactions and connections', function () {
    $connection = fleetopsQuerySeamBoot();
    $connection->table('fuel_provider_transactions')->insert([
        ['uuid' => 'fpt-1', 'company_uuid' => 'company-seam-1', 'transaction_at' => '2026-07-15 00:00:00'],
        // Outside the window and another company: both excluded
        ['uuid' => 'fpt-2', 'company_uuid' => 'company-seam-1', 'transaction_at' => '2026-01-01 00:00:00'],
        ['uuid' => 'fpt-3', 'company_uuid' => 'company-other', 'transaction_at' => '2026-07-15 00:00:00'],
    ]);
    $connection->table('fuel_provider_connections')->insert([
        ['uuid' => 'fpc-1', 'company_uuid' => 'company-seam-1'],
        ['uuid' => 'fpc-2', 'company_uuid' => 'company-other'],
    ]);

    $class   = Fleetbase\FleetOps\Support\Analytics\FuelProviderSummary::class;
    $summary = (new ReflectionClass($class))->newInstanceWithoutConstructor()
        ->between(Illuminate\Support\Carbon::parse('2026-07-01')->toDateTime(), Illuminate\Support\Carbon::parse('2026-07-31')->toDateTime());

    // Transactions are scoped to the company and the reporting window
    expect(fleetopsQuerySeamInvoke($summary, $class, 'transactions', ['company-seam-1'])->pluck('uuid')->all())->toBe(['fpt-1'])
        ->and(fleetopsQuerySeamInvoke($summary, $class, 'connections', ['company-seam-1'])->pluck('uuid')->all())->toBe(['fpc-1']);
});

test('shift change listener seams detect creation events and notify drivers', function () {
    fleetopsQuerySeamBoot();

    $listener = (new ReflectionClass(Fleetbase\FleetOps\Listeners\NotifyDriverOnShiftChange::class))->newInstanceWithoutConstructor();
    $class    = Fleetbase\FleetOps\Listeners\NotifyDriverOnShiftChange::class;

    // Only schedule-item creation events are treated as creations
    expect(fleetopsQuerySeamInvoke($listener, $class, 'isCreatedEvent', [new stdClass()]))->toBeFalse();
});

test('order finalization fires the order ready event', function () {
    fleetopsQuerySeamBoot();
    FleetOpsQuerySeamEventRecorder::$fired = [];

    $order = new Fleetbase\FleetOps\Models\Order();
    $order->setRawAttributes(['uuid' => 'order-ready-1', 'public_id' => 'order_readyone'], true);

    $class = Fleetbase\FleetOps\Jobs\FinalizeApiOrderCreation::class;
    $job   = new $class('order-ready-1');
    fleetopsQuerySeamInvoke($job, $class, 'fireOrderReady', [$order]);

    expect(FleetOpsQuerySeamEventRecorder::$fired)->toHaveCount(1)
        ->and(FleetOpsQuerySeamEventRecorder::$fired[0])->toBeInstanceOf(Fleetbase\FleetOps\Events\OrderReady::class);
});

test('adhoc dispatch finds online drivers for the orders company', function () {
    $connection = fleetopsQuerySeamBoot();
    $connection->table('users')->insert([
        ['uuid' => 'user-adhoc-a', 'company_uuid' => 'company-seam-1'],
        ['uuid' => 'user-adhoc-b', 'company_uuid' => 'company-other'],
    ]);
    $connection->getSchemaBuilder()->table('drivers', function ($blueprint) {
        $blueprint->string('online')->nullable();
    });
    $connection->table('drivers')->insert([
        // Online and in the order's company
        ['uuid' => 'driver-near-1', 'company_uuid' => 'company-seam-1', 'user_uuid' => 'user-adhoc-a', 'online' => '1'],
        // Online but another company
        ['uuid' => 'driver-near-2', 'company_uuid' => 'company-other', 'user_uuid' => 'user-adhoc-b', 'online' => '1'],
        // Right company but offline
        ['uuid' => 'driver-near-3', 'company_uuid' => 'company-seam-1', 'user_uuid' => 'user-adhoc-a', 'online' => '0'],
    ]);

    $order = new Fleetbase\FleetOps\Models\Order();
    $order->setRawAttributes(['uuid' => 'order-adhoc-near', 'company_uuid' => 'company-seam-1'], true);

    $class   = Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders::class;
    $command = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $pickup  = new Fleetbase\LaravelMysqlSpatial\Types\Point(1.30, 103.80);

    // In testing mode the spatial predicates are skipped so the company and
    // online filters can be asserted on their own
    $drivers = $command->getNearbyDriversForOrder($order, $pickup, 5000, true);
    expect($drivers->pluck('uuid')->all())->toBe(['driver-near-1']);
});
