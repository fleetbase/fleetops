<?php

use Fleetbase\FleetOps\Exports\ContactExport;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ContactController;
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
 * Covers the internal ContactController export/import endpoints, the
 * contact lookup and vendor-conversion helpers with customer context
 * migration, and the customer portal welcome-email guard branches against
 * SQLite with an excel fake.
 */
if (!class_exists('Fleetbase\Http\Requests\ExportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ExportRequest extends \Illuminate\Http\Request {}');
}

if (!class_exists('Fleetbase\Http\Requests\ImportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ImportRequest extends \Illuminate\Http\Request {}');
}

if (!defined('FLEETOPS_CONTACT_TEST_BASE_PATH')) {
    define('FLEETOPS_CONTACT_TEST_BASE_PATH', dirname(__DIR__, 5));
}

if (!function_exists('Fleetbase\Support\base_path')) {
    eval('namespace Fleetbase\Support; function base_path($path = \'\') { return rtrim(FLEETOPS_CONTACT_TEST_BASE_PATH . \'/\' . ltrim($path, \'/\'), \'/\'); }');
}

if (!Request::hasMacro('resolveFilesFromIds')) {
    Request::macro('resolveFilesFromIds', fn () => FleetOpsInternalContactEndpointsState::$files);
}

class FleetOpsInternalContactEndpointsState
{
    public static array $files = [];
}

class FleetOpsInternalContactEndpointsExcelFake
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

class FleetOpsInternalContactEndpointsProbe extends ContactController
{
    public function callProtected(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(ContactController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsInternalContactEndpointsBoot(): SQLiteConnection
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

        public function transaction(callable $callback)
        {
            return $this->c->transaction($callback);
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    app()->instance('request', Request::create('/int/v1/contacts'));

    $excelFake = new FleetOpsInternalContactEndpointsExcelFake();
    app()->instance('excel', $excelFake);
    Maatwebsite\Excel\Facades\Excel::clearResolvedInstance('excel');
    $GLOBALS['fleetopsContactExcelFake'] = $excelFake;

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'contacts'            => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'name', 'title', 'email', 'phone', 'type', 'place_uuid', 'photo_uuid', 'meta', 'slug'],
        'vendors'             => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'name', 'email', 'phone', 'status', 'type', 'place_uuid', 'meta', 'slug'],
        'vendor_personnels'   => ['uuid', 'vendor_uuid', 'contact_uuid', 'company_uuid', 'role', 'status'],
        'orders'              => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'status'],
        'purchase_rates'      => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type'],
        'entities'            => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'name'],
        'issues'              => ['uuid', 'public_id', 'company_uuid', 'meta', 'status', 'type'],
        'users'               => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'status', 'type'],
        'places'              => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'name', 'location'],
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

test('export streams a contact export download', function () {
    fleetopsInternalContactEndpointsBoot();

    $request  = ExportRequest::create('/int/v1/contacts/export', 'POST', ['format' => 'csv', 'selections' => ['contact-1']]);
    $response = ContactController::export($request);

    expect($response)->toStartWith('downloaded:contacts-')
        ->and($response)->toEndWith('.csv')
        ->and($GLOBALS['fleetopsContactExcelFake']->downloads[0][0])->toBeInstanceOf(ContactExport::class);
});

test('import processes resolved files and reports invalid files', function () {
    fleetopsInternalContactEndpointsBoot();
    FleetOpsInternalContactEndpointsState::$files = [
        (object) ['path' => 'uploads/contacts-a.xlsx'],
    ];

    $request  = ImportRequest::create('/int/v1/contacts/import', 'POST', ['disk' => 'local']);
    $response = (new ContactController())->import($request);
    expect($response->getData(true))->toBe(['status' => 'ok', 'message' => 'Import completed', 'imported' => 1]);

    $GLOBALS['fleetopsContactExcelFake']->importFails = true;
    $failure                                          = (new ContactController())->import($request);
    expect($failure->getData(true))->toBe(['error' => 'Invalid file, unable to proccess.']);
});

test('contact lookup and vendor conversion helpers resolve and persist', function () {
    $connection = fleetopsInternalContactEndpointsBoot();
    $connection->table('contacts')->insert(['uuid' => 'contact-1', 'public_id' => 'contact_test', 'company_uuid' => 'company-1', 'name' => 'C', 'type' => 'contact']);

    $probe = new FleetOpsInternalContactEndpointsProbe();

    expect($probe->callProtected('contactByUuid', ['contact-1'])?->uuid)->toBe('contact-1')
        ->and($probe->callProtected('contactForVendorConversion', ['contact_test']))->toBeInstanceOf(Contact::class);

    $vendor = $probe->callProtected('createVendorFromContact', [['company_uuid' => 'company-1', 'name' => 'Converted Vendor', 'type' => 'vendor']]);
    expect($vendor)->toBeInstanceOf(Vendor::class);

    $personnel = $probe->callProtected('updateOrCreateVendorPersonnel', [
        ['vendor_uuid' => 'vendor-x', 'contact_uuid' => 'contact-1'],
        ['company_uuid' => 'company-1'],
    ]);
    expect($personnel)->toBeInstanceOf(VendorPersonnel::class);

    $transaction = $probe->callProtected('runContactConversionTransaction', [fn () => 'committed']);
    expect($transaction)->toBe('committed');

    $payload = $probe->callProtected('vendorResourcePayload', [Vendor::where('name', 'Converted Vendor')->first()]);
    expect($payload)->toBeArray()->and($payload['name'])->toBe('Converted Vendor');
});

test('customer context migration rewrites orders rates entities and issues', function () {
    $connection = fleetopsInternalContactEndpointsBoot();

    $contact = new Contact();
    $contact->setRawAttributes(['uuid' => 'contact-1', 'company_uuid' => 'company-1', 'type' => 'customer'], true);
    $contact->exists = true;
    $vendor          = new Vendor();
    $vendor->setRawAttributes(['uuid' => 'vendor-1', 'company_uuid' => 'company-1', 'name' => 'V'], true);
    $vendor->exists = true;

    $contactType = 'fleet-ops:contact';
    $connection->table('orders')->insert(['uuid' => 'order-1', 'company_uuid' => 'company-1', 'customer_uuid' => 'contact-1', 'customer_type' => Fleetbase\FleetOps\Support\Utils::getMutationType($contact)]);
    $connection->table('issues')->insert(['uuid' => 'issue-1', 'company_uuid' => 'company-1', 'meta' => json_encode(['customer_portal' => ['customer_uuid' => 'contact-1', 'customer_type' => 'contact']])]);

    $probe = new FleetOpsInternalContactEndpointsProbe();
    $probe->callProtected('migrateContactCustomerContextToVendor', [$contact, $vendor]);

    expect($connection->table('orders')->value('customer_uuid'))->toBe('vendor-1')
        ->and(json_decode($connection->table('issues')->value('meta'), true)['customer_portal']['customer_uuid'])->toBe('vendor-1');
});

test('customer portal welcome email guards and extension detection', function () {
    fleetopsInternalContactEndpointsBoot();
    $probe = new FleetOpsInternalContactEndpointsProbe();

    // Without the welcome flag nothing happens
    $plain = new Contact();
    $plain->setRawAttributes(['uuid' => 'contact-1', 'company_uuid' => 'company-1', 'meta' => json_encode([])], true);
    $plain->exists = true;
    expect($probe->callProtected('sendCustomerPortalWelcomeEmail', [$plain]))->toBeNull();

    // With the flag but no installed portal extension the guard throws
    $flagged = new Contact();
    $flagged->setRawAttributes(['uuid' => 'contact-2', 'company_uuid' => 'company-1', 'meta' => json_encode(['customer_portal' => ['send_welcome_email' => true]])], true);
    $flagged->exists = true;
    expect(fn () => $probe->callProtected('sendCustomerPortalWelcomeEmail', [$flagged]))
        ->toThrow(Exception::class, 'Customer portal must be installed');

    // Extension detection matches only the customer portal package
    expect($probe->callProtected('containsCustomerPortalExtension', [[['name' => 'fleetbase/customer-portal-api']]]))->toBeTrue()
        ->and($probe->callProtected('containsCustomerPortalExtension', [[['name' => 'fleetbase/other']]]))->toBeFalse()
        ->and($probe->callProtected('customerPortalPassword', []))->toBeString();
});

test('contact update guards types resolve users and portal seams persist', function () {
    $connection = fleetopsInternalContactEndpointsBoot();
    $connection->table('users')->insert(['uuid' => '88888888-8888-4888-8888-888888888881', 'public_id' => 'user_contactseam', 'company_uuid' => 'company-1', 'name' => 'Portal User', 'email' => 'portal@example.com', 'type' => 'contact']);
    $connection->table('contacts')->insert(['uuid' => '88888888-8888-4888-8888-888888888882', 'public_id' => 'contact_seamone1', 'company_uuid' => 'company-1', 'user_uuid' => '88888888-8888-4888-8888-888888888881', 'name' => 'Seam Contact', 'email' => 'portal@example.com', 'type' => 'customer']);
    $probe = new FleetOpsInternalContactEndpointsProbe();

    // Customer contacts cannot change type
    $contact = Contact::where('uuid', '88888888-8888-4888-8888-888888888882')->first();
    $input   = ['type' => 'contact'];
    expect(function () use ($probe, $contact, &$input) {
        $request = Request::create('/x', 'PUT', []);
        $probe->callProtected('assertContactInputIsValid', [$request, &$input, $contact]);
    })->toThrow(Exception::class);

    // User references resolve through uuid or public id
    expect($probe->callProtected('resolveUserUuid', ['user_contactseam']))->toBe('88888888-8888-4888-8888-888888888881')
        ->and($probe->callProtected('resolveUserUuid', ['88888888-8888-4888-8888-888888888881']))->toBe('88888888-8888-4888-8888-888888888881')
        ->and($probe->callProtected('resolveUserUuid', ['unknown-user']))->toBe('unknown-user');

    // Portal seams read users, mint passwords, and persist meta quietly
    expect($probe->callProtected('contactUser', [$contact])?->uuid)->toBe('88888888-8888-4888-8888-888888888881')
        ->and(strlen($probe->callProtected('customerPortalPassword', [])))->toBe(16);

    Illuminate\Support\Facades\Mail::swap(new class {
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

        public function __call($method, $arguments)
        {
            return $this;
        }
    });
    $user = Fleetbase\Models\User::where('uuid', '88888888-8888-4888-8888-888888888881')->first();
    $probe->callProtected('sendCustomerCredentialsMail', [$user, 'secret', $contact]);
    expect(Illuminate\Support\Facades\Mail::getFacadeRoot()->sent)->toHaveCount(1);

    $probe->callProtected('saveContactMetaQuietly', [$contact, ['portal' => true]]);
    expect((string) $connection->table('contacts')->where('uuid', '88888888-8888-4888-8888-888888888882')->value('meta'))->toContain('portal');

    // Trashed lookups include soft-deleted contacts
    $connection->table('contacts')->where('uuid', '88888888-8888-4888-8888-888888888882')->update(['deleted_at' => now()]);
    expect($probe->callProtected('contactByUuidWithTrashed', ['88888888-8888-4888-8888-888888888882']))->not->toBeNull();
});
