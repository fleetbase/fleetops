<?php

use Fleetbase\FleetOps\Exports\WorkOrderExport;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderConfigController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\WorkOrderController;
use Fleetbase\FleetOps\Http\Filter\SensorFilter;
use Fleetbase\FleetOps\Imports\WorkOrderImport;
use Fleetbase\Http\Filter\Filter;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the internal WorkOrderController email/export/import helpers, the
 * OrderConfigController delete flow with the core-service guard, and the
 * SensorFilter public-relation resolution against SQLite.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

class FleetOpsWorkOrderSurfacesExcelFake
{
    public array $downloads = [];
    public array $imports   = [];

    public function download($export, string $fileName): string
    {
        $this->downloads[] = [$export, $fileName];

        return 'downloaded:' . $fileName;
    }

    public function import($import, $path, $disk = null): bool
    {
        $this->imports[] = [$import, $path, $disk];

        return true;
    }
}

class FleetOpsWorkOrderSurfacesMailerFake
{
    public array $sent = [];

    public function to($users)
    {
        return $this;
    }

    public function send($mailable)
    {
        $this->sent[] = $mailable;

        return null;
    }
}

class FleetOpsWorkOrderSurfacesProbe extends WorkOrderController
{
    public function callProtected(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(WorkOrderController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsSensorFilterQueryFake
{
    public array $calls = [];

    public function __call($method, $arguments)
    {
        $this->calls[] = [$method, $arguments];

        return $this;
    }
}

function fleetopsWorkOrderSurfacesBoot(): SQLiteConnection
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

    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    config()->set('auth.defaults.guard', 'web');
    config()->set('auth.guards.web.driver', 'token');
    config()->set('auth.guards.web.provider', 'users');
    config()->set('auth.providers.users.driver', 'eloquent');
    config()->set('auth.providers.users.model', Fleetbase\Models\User::class);
    app()->instance('hash', new class implements Illuminate\Contracts\Hashing\Hasher {
        public function info($hashedValue): array
        {
            return [];
        }

        public function make($value, array $options = []): string
        {
            return md5((string) $value);
        }

        public function check($value, $hashedValue, array $options = []): bool
        {
            return md5((string) $value) === $hashedValue;
        }

        public function needsRehash($hashedValue, array $options = []): bool
        {
            return false;
        }

        public function verifyConfiguration($value): bool
        {
            return true;
        }
    });
    Illuminate\Support\Facades\Hash::clearResolvedInstance('hash');
    $authManager = new Illuminate\Auth\AuthManager(app());
    app()->instance('auth', $authManager);
    app()->instance(Illuminate\Auth\AuthManager::class, $authManager);
    app()->instance('request', Request::create('/int/v1'));

    $excelFake = new FleetOpsWorkOrderSurfacesExcelFake();
    app()->instance('excel', $excelFake);
    Maatwebsite\Excel\Facades\Excel::clearResolvedInstance('excel');
    $GLOBALS['fleetopsWorkOrderExcelFake'] = $excelFake;

    $mailerFake = new FleetOpsWorkOrderSurfacesMailerFake();
    app()->instance('mail.manager', $mailerFake);
    Illuminate\Support\Facades\Mail::clearResolvedInstance('mail.manager');
    $GLOBALS['fleetopsWorkOrderMailerFake'] = $mailerFake;

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'work_orders'         => ['uuid', 'public_id', 'company_uuid', 'schedule_uuid', 'target_uuid', 'target_type', 'assignee_uuid', 'assignee_type', 'code', 'subject', 'category', 'status', 'priority', 'meta'],
        'vendors'             => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone'],
        'order_configs'       => ['uuid', 'public_id', 'company_uuid', 'name', 'key', 'namespace', 'flow', 'core_service', 'status', 'version', 'meta', '_key'],
        'sensors'             => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'device_uuid', 'telematic_uuid', 'type', 'name', 'status'],
        'devices'             => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'device_id', 'name', 'status'],
        'custom_field_values' => ['uuid', 'subject_uuid', 'subject_type', 'custom_field_uuid', 'value', 'value_type'],
        'users'               => ['uuid', 'public_id', 'company_uuid', 'name'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                if ($column === 'core_service') {
                    $blueprint->integer($column)->nullable();
                    continue;
                }
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);

    return $connection;
}

test('work order email requires an assignee with an email address', function () {
    $connection = fleetopsWorkOrderSurfacesBoot();
    $controller = new WorkOrderController();

    $connection->table('work_orders')->insert([
        'uuid'         => 'wo-1',
        'public_id'    => 'work_order_send',
        'company_uuid' => 'company-1',
        'code'         => 'WO-1',
        'subject'      => 'Repair',
    ]);

    // No assignee at all
    $noAssignee = $controller->sendEmail('wo-1');
    expect($noAssignee->getStatusCode())->toBe(422)
        ->and($noAssignee->getData(true)['error'])->toContain('no assigned vendor');

    // Assignee without an email address
    $connection->table('vendors')->insert(['uuid' => 'vendor-1', 'company_uuid' => 'company-1', 'name' => 'Shop']);
    $connection->table('work_orders')->where('uuid', 'wo-1')->update([
        'assignee_uuid' => 'vendor-1',
        'assignee_type' => Fleetbase\FleetOps\Models\Vendor::class,
    ]);
    $noEmail = $controller->sendEmail('wo-1');
    expect($noEmail->getStatusCode())->toBe(422)
        ->and($noEmail->getData(true)['error'])->toContain('no email address');

    // Assignee with an email receives the dispatch mail
    $connection->table('vendors')->where('uuid', 'vendor-1')->update(['email' => 'shop@example.test']);
    $sent = $controller->sendEmail('wo-1');
    expect($sent->getData(true)['status'])->toBe('ok')
        ->and($GLOBALS['fleetopsWorkOrderMailerFake']->sent)->toHaveCount(1);
});

test('work order export and import helpers delegate to excel', function () {
    fleetopsWorkOrderSurfacesBoot();
    $probe = new FleetOpsWorkOrderSurfacesProbe();

    $download = $probe->callProtected('downloadExport', [new WorkOrderExport([]), 'work-orders.csv']);
    expect($download)->toBe('downloaded:work-orders.csv');

    $import = $probe->callProtected('createImport');
    expect($import)->toBeInstanceOf(WorkOrderImport::class);

    $probe->callProtected('importFile', [$import, 'uploads/work-orders.xlsx', 'local']);
    expect($GLOBALS['fleetopsWorkOrderExcelFake']->imports)->toHaveCount(1);
});

test('order config deletion guards core services and deletes others', function () {
    $connection = fleetopsWorkOrderSurfacesBoot();
    $controller = new OrderConfigController();

    $missing = $controller->deleteRecord('config-missing', Request::create('/x', 'DELETE'));
    expect($missing->getData(true)['error'])->toContain('No order config found');

    $connection->table('order_configs')->insert([
        ['uuid' => 'config-core', 'company_uuid' => 'company-1', 'name' => 'Transport', 'key' => 'transport', 'core_service' => 1, 'flow' => '[]'],
        ['uuid' => 'config-custom', 'company_uuid' => 'company-1', 'name' => 'Custom', 'key' => 'custom', 'core_service' => 0, 'flow' => '[]'],
    ]);

    $core = $controller->deleteRecord('config-core', Request::create('/x', 'DELETE'));
    expect($core->getData(true)['error'])->toContain('cannot be deleted');

    $controller->deleteRecord('config-custom', Request::create('/x', 'DELETE'));
    expect($connection->table('order_configs')->where('uuid', 'config-custom')->whereNull('deleted_at')->count())->toBe(0);
});

test('sensor filter resolves public relations with company scoping', function () {
    $connection = fleetopsWorkOrderSurfacesBoot();
    $connection->table('devices')->insert(['uuid' => 'device-1', 'public_id' => 'device_sensor', 'company_uuid' => 'company-1', 'device_id' => 'unit-1']);

    $filter = (new ReflectionClass(SensorFilter::class))->newInstanceWithoutConstructor();
    $query  = new FleetOpsSensorFilterQueryFake();
    foreach ([
        'builder' => $query,
        'session' => new class {
            public function get(string $key): ?string
            {
                return $key === 'company' ? 'company-1' : null;
            }
        },
        'request' => new Request(),
    ] as $property => $value) {
        $reflection = new ReflectionProperty(Filter::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($filter, $value);
    }

    // Missing identifiers short-circuit
    $wherePublicRelation = new ReflectionMethod(SensorFilter::class, 'wherePublicRelation');
    $wherePublicRelation->setAccessible(true);
    $wherePublicRelation->invoke($filter, 'device_uuid', Fleetbase\FleetOps\Models\Device::class, null);
    expect($query->calls)->toBe([]);

    // Public ids resolve to uuids with company scoping
    $wherePublicRelation->invoke($filter, 'device_uuid', Fleetbase\FleetOps\Models\Device::class, 'device_sensor');
    $whereIn = collect($query->calls)->firstWhere(0, 'whereIn');
    expect($whereIn)->not->toBeNull()
        ->and($whereIn[1][1]->all())->toBe(['device-1']);
});
