<?php

use Fleetbase\FleetOps\Console\Commands\FixCustomerCompanies;
use Fleetbase\FleetOps\Console\Commands\FixInvalidPolymorphicRelationTypeNamespaces;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\MaintenanceController;
use Fleetbase\FleetOps\Models\Maintenance;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the FixCustomerCompanies command helpers, the polymorphic
 * namespace fixer traversal, and the internal MaintenanceController
 * export/import/cost helpers against SQLite with an excel fake.
 */
if (!class_exists('Fleetbase\Http\Requests\ExportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ExportRequest extends \Illuminate\Http\Request {}');
}

if (!class_exists('Fleetbase\Http\Requests\ImportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ImportRequest extends \Illuminate\Http\Request {}');
}

if (!Request::hasMacro('resolveFilesFromIds')) {
    Request::macro('resolveFilesFromIds', fn () => FleetOpsFixCommandsState::$files);
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

class FleetOpsFixCommandsState
{
    public static array $files = [];
}

class FleetOpsFixCommandsExcelFake
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

        if (str_contains((string) $path, 'unreadable')) {
            throw new RuntimeException('Unable to read the spreadsheet.');
        }

        $import->imported++;

        return true;
    }
}

class FleetOpsFixCustomerCompaniesProbe extends FixCustomerCompanies
{
    public array $messages = [];

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function error($string, $verbosity = null)
    {
        $this->messages[] = ['error', $string];
    }

    public function line($string, $style = null, $verbosity = null)
    {
        $this->messages[] = ['line', $string];
    }

    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

class FleetOpsPolymorphicFixerProbe extends FixInvalidPolymorphicRelationTypeNamespaces
{
    public array $fixed = [];

    public function info($string, $verbosity = null)
    {
    }

    public function line($string, $style = null, $verbosity = null)
    {
    }

    protected function fixModelRelations(string $model, array $columns): void
    {
        $this->fixed[] = [$model, $columns];
    }
}

class FleetOpsMaintenanceControllerProbe extends MaintenanceController
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsFixCommandsBoot(): SQLiteConnection
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

    $excelFake = new FleetOpsFixCommandsExcelFake();
    app()->instance('excel', $excelFake);
    Maatwebsite\Excel\Facades\Excel::clearResolvedInstance('excel');
    $GLOBALS['fleetopsFixCommandsExcelFake'] = $excelFake;

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'contacts'      => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'email', 'phone', 'type', 'meta'],
        'users'         => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'status', 'type'],
        'companies'     => ['uuid', 'public_id', 'name'],
        'company_users' => ['uuid', 'company_uuid', 'user_uuid', 'status'],
        'maintenances'  => ['uuid', 'public_id', 'company_uuid', 'maintainable_type', 'maintainable_uuid', 'maintainable_id', 'type', 'status', 'line_items', 'labor_cost', 'tax', 'parts_cost', 'total_cost', 'meta'],
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

test('fix customer companies helpers resolve users companies and memberships', function () {
    $connection = fleetopsFixCommandsBoot();
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'email' => 'known@example.test']);
    $connection->table('contacts')->insert(['uuid' => 'contact-1', 'company_uuid' => 'company-1', 'name' => 'Customer', 'email' => 'known@example.test', 'type' => 'customer']);

    $probe = new FleetOpsFixCustomerCompaniesProbe();

    expect($probe->callHelper('customers'))->toHaveCount(1)
        ->and($probe->callHelper('userByEmail', 'known@example.test')?->uuid)->toBe('user-1')
        ->and($probe->callHelper('userByEmail', 'unknown@example.test'))->toBeNull()
        ->and($probe->callHelper('companyByUuid', 'company-1')?->name)->toBe('Acme')
        ->and($probe->callHelper('missingCompanyUser', 'user-1', 'company-1'))->toBeTrue();

    $connection->table('company_users')->insert(['uuid' => 'cu-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);
    expect($probe->callHelper('missingCompanyUser', 'user-1', 'company-1'))->toBeFalse();

    // Assigning an existing user links and persists the relation
    $customer = Fleetbase\FleetOps\Models\Contact::withoutGlobalScopes()->where('uuid', 'contact-1')->first();
    $user     = Fleetbase\Models\User::where('uuid', 'user-1')->first();
    $probe->callHelper('assignExistingUserToCustomer', $customer, $user);
    expect($connection->table('contacts')->value('user_uuid'))->toBe('user-1')
        ->and($probe->callHelper('customerUser', $customer))->not->toBeNull();
});

test('polymorphic namespace fixer traverses every configured model', function () {
    fleetopsFixCommandsBoot();

    $probe = new FleetOpsPolymorphicFixerProbe();
    $probe->handle();

    expect($probe->fixed)->toHaveCount(5)
        ->and(collect($probe->fixed)->pluck(0)->all())->toContain(Fleetbase\FleetOps\Models\Order::class, Fleetbase\FleetOps\Models\Device::class);
});

test('maintenance export import and cost recalculation execute', function () {
    $connection = fleetopsFixCommandsBoot();
    $controller = new MaintenanceController();

    $request  = Fleetbase\Http\Requests\ExportRequest::create('/int/v1/maintenances/export', 'POST', ['format' => 'csv', 'selections' => []]);
    $response = $controller->export($request);
    expect($response)->toStartWith('downloaded:maintenances-');

    FleetOpsFixCommandsState::$files = [(object) ['path' => 'uploads/maintenances.xlsx']];
    $imported                        = $controller->import(Fleetbase\Http\Requests\ImportRequest::create('/int/v1/maintenances/import', 'POST', ['disk' => 'local']));
    expect($imported->getData(true)['imported'])->toBe(1);

    // Cost recalculation derives parts and total costs from line items
    $connection->table('maintenances')->insert([
        'uuid'         => 'mnt-1',
        'public_id'    => 'maintenance_costs',
        'company_uuid' => 'company-1',
        'line_items'   => json_encode([
            ['quantity' => 2, 'unit_cost' => 500],
            ['quantity' => 1, 'unit_cost' => 250],
        ]),
        'labor_cost'   => '1000',
        'tax'          => '100',
    ]);

    $probe       = new FleetOpsMaintenanceControllerProbe();
    $maintenance = $probe->callHelper('findMaintenanceForLineItem', 'maintenance_costs');
    expect($maintenance)->toBeInstanceOf(Maintenance::class);

    $probe->callHelper('recalculateCosts', $maintenance);
    expect((int) $connection->table('maintenances')->value('parts_cost'))->toBe(1250)
        ->and((int) $connection->table('maintenances')->value('total_cost'))->toBe(2350);
});

test('maintenance import reports an unreadable file instead of surfacing the reader error', function () {
    fleetopsFixCommandsBoot();
    $controller = new MaintenanceController();

    FleetOpsFixCommandsState::$files = [
        (object) ['path' => 'uploads/maintenances.xlsx'],
        (object) ['path' => 'uploads/unreadable.xlsx'],
    ];

    $response = $controller->import(Fleetbase\Http\Requests\ImportRequest::create('/int/v1/maintenances/import', 'POST', ['disk' => 'local']));

    // The reader throws on the second file, so the whole import fails rather than
    // reporting a partial count for the first
    expect($response->getData(true)['errors'] ?? [$response->getData(true)['error'] ?? null])
        ->toContain('Invalid file, unable to process.')
        ->and($GLOBALS['fleetopsFixCommandsExcelFake']->imports)->toHaveCount(2);
});
