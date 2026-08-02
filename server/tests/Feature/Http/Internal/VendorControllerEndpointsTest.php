<?php

use Fleetbase\FleetOps\Exports\VendorExport;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\VendorController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\VendorPersonnel;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the internal VendorController export/statuses/import endpoints and
 * the protected lookup and personnel helpers against SQLite with an excel
 * fake.
 */
if (!class_exists('Fleetbase\Http\Requests\ExportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ExportRequest extends \Illuminate\Http\Request {}');
}

if (!class_exists('Fleetbase\Http\Requests\ImportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ImportRequest extends \Illuminate\Http\Request {}');
}

if (!Request::hasMacro('resolveFilesFromIds')) {
    Request::macro('resolveFilesFromIds', fn () => FleetOpsInternalVendorEndpointsState::$files);
}

class FleetOpsInternalVendorEndpointsState
{
    public static array $files = [];
}

class FleetOpsInternalVendorEndpointsExcelFake
{
    public array $downloads  = [];
    public array $imports    = [];
    public bool $importFails = false;

    public function download($export, string $fileName): string
    {
        $this->downloads[] = [$export, $fileName];

        return 'downloaded:' . $fileName;
    }

    public function import($import, $path, $disk = null): bool
    {
        if ($this->importFails) {
            throw new RuntimeException('corrupt file');
        }

        $this->imports[] = [$import, $path, $disk];
        $import->imported++;

        return true;
    }
}

class FleetOpsInternalVendorEndpointsProbe extends VendorController
{
    public function callProtected(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(VendorController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsInternalVendorEndpointsBoot(): SQLiteConnection
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
    app()->instance('request', Request::create('/int/v1/vendors'));

    $excelFake = new FleetOpsInternalVendorEndpointsExcelFake();
    app()->instance('excel', $excelFake);
    Maatwebsite\Excel\Facades\Excel::clearResolvedInstance('excel');
    $GLOBALS['fleetopsVendorExcelFake'] = $excelFake;

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'vendors'             => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'name', 'email', 'phone', 'status', 'type', 'place_uuid', 'logo_uuid', 'business_id', 'website_url', 'meta', 'callbacks', 'slug'],
        'contacts'            => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'name', 'title', 'email', 'phone', 'type', 'place_uuid', 'photo_uuid', 'meta', 'slug'],
        'vendor_personnels'   => ['uuid', 'vendor_uuid', 'contact_uuid', 'company_uuid', 'role', 'status'],
        'drivers'             => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'status'],
        'users'               => ['uuid', 'public_id', 'company_uuid', 'name'],
        'places'              => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'name', 'street1', 'location'],
        'custom_field_values' => ['uuid', 'subject_uuid', 'subject_type', 'custom_field_uuid', 'value', 'value_type'],
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

test('export streams a vendor export and statuses list distinct values', function () {
    $connection = fleetopsInternalVendorEndpointsBoot();
    $connection->table('vendors')->insert([
        ['uuid' => 'vendor-1', 'company_uuid' => 'company-1', 'name' => 'A', 'status' => 'active'],
        ['uuid' => 'vendor-2', 'company_uuid' => 'company-1', 'name' => 'B', 'status' => 'pending'],
        ['uuid' => 'vendor-3', 'company_uuid' => 'company-1', 'name' => 'C', 'status' => 'active'],
    ]);

    $request  = ExportRequest::create('/int/v1/vendors/export', 'POST', ['format' => 'csv', 'selections' => ['vendor-1']]);
    $response = VendorController::export($request);
    expect($response)->toStartWith('downloaded:vendors-')
        ->and($response)->toEndWith('.csv')
        ->and($GLOBALS['fleetopsVendorExcelFake']->downloads[0][0])->toBeInstanceOf(VendorExport::class);

    $statuses = (new VendorController())->statuses();
    expect($statuses->getData(true))->toBe(['active', 'pending']);
});

test('import processes resolved files and reports invalid files', function () {
    fleetopsInternalVendorEndpointsBoot();
    FleetOpsInternalVendorEndpointsState::$files = [
        (object) ['path' => 'uploads/vendors-a.xlsx'],
    ];

    $request  = ImportRequest::create('/int/v1/vendors/import', 'POST', ['disk' => 'local']);
    $response = (new VendorController())->import($request);
    expect($response->getData(true))->toBe(['status' => 'ok', 'message' => 'Import completed', 'imported' => 1]);

    $GLOBALS['fleetopsVendorExcelFake']->importFails = true;
    $failure                                         = (new VendorController())->import($request);
    expect($failure->getData(true))->toBe(['error' => 'Invalid file, unable to proccess.']);
});

test('vendor and contact lookup helpers resolve records', function () {
    $connection = fleetopsInternalVendorEndpointsBoot();
    $connection->table('vendors')->insert(['uuid' => 'vendor-1', 'public_id' => 'vendor_test', 'company_uuid' => 'company-1', 'name' => 'A']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);
    $connection->table('contacts')->insert(['uuid' => 'contact-1', 'public_id' => 'contact_test', 'company_uuid' => 'company-1', 'name' => 'C', 'type' => 'contact']);

    $probe = new FleetOpsInternalVendorEndpointsProbe();

    expect($probe->callProtected('findVendorByUuid', ['vendor-1'])?->uuid)->toBe('vendor-1')
        ->and($probe->callProtected('findVendorWithTrashedByUuid', ['vendor-1'])?->uuid)->toBe('vendor-1')
        ->and($probe->callProtected('findDriverByUuid', ['driver-1'])?->uuid)->toBe('driver-1')
        ->and($probe->callProtected('findVendorByIdOrFail', ['vendor_test']))->toBeInstanceOf(Vendor::class)
        ->and($probe->callProtected('findContactByIdOrFail', ['contact_test']))->toBeInstanceOf(Contact::class)
        ->and($probe->callProtected('findPersonnelContact', ['contact-1']))->toBeInstanceOf(Contact::class);

    $payload = $probe->callProtected('contactResourcePayload', [Contact::where('uuid', 'contact-1')->first()]);
    expect($payload)->toBeArray()->and($payload['name'])->toBe('C');
});

test('vendor personnel helpers persist update and delete assignments', function () {
    $connection = fleetopsInternalVendorEndpointsBoot();
    $connection->table('contacts')->insert(['uuid' => 'contact-1', 'public_id' => 'contact_test', 'company_uuid' => 'company-1', 'name' => 'C', 'type' => 'contact']);

    $probe = new FleetOpsInternalVendorEndpointsProbe();

    $personnel = $probe->callProtected('updateOrCreateVendorPersonnel', [
        ['vendor_uuid' => 'vendor-1', 'contact_uuid' => 'contact-1'],
        ['company_uuid' => 'company-1', 'role' => 'manager'],
    ]);
    expect($personnel)->toBeInstanceOf(VendorPersonnel::class)
        ->and($connection->table('vendor_personnels')->count())->toBe(1);

    $listed = $probe->callProtected('queryVendorPersonnel', ['vendor-1']);
    expect($listed)->toHaveCount(1);

    $created = $probe->callProtected('createPersonnelContact', [['company_uuid' => 'company-1', 'name' => 'New Contact', 'type' => 'contact']]);
    expect($created)->toBeInstanceOf(Contact::class);

    $probe->callProtected('deleteVendorPersonnel', ['vendor-1', 'contact-1']);
    expect($connection->table('vendor_personnels')->whereNull('deleted_at')->count())->toBe(0);
});
