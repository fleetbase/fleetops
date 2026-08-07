<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\MorphController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the MorphController protected helper bodies: query builders for
 * contacts, vendors and integrated vendors, paginator construction, input
 * normalization, url/json helpers and the contact/vendor resource wrappers.
 */
if (!class_exists('Illuminate\\Pagination\\LengthAwarePaginator', false)) {
    eval('namespace Illuminate\\Pagination; class LengthAwarePaginator { public function __construct(public $items = [], public int $total = 0, public int $perPage = 15, public int $currentPage = 1, public array $options = []) {} public function total(): int { return $this->total; } public function perPage(): int { return $this->perPage; } public function __call($method, $arguments) { return $this; } }');
}

function fleetopsMorphHelpersBoot(): SQLiteConnection
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
        'contacts'           => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'email', 'phone', 'type', 'meta', '_key'],
        'vendors'            => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'type', 'meta', '_key'],
        'integrated_vendors' => ['uuid', 'public_id', 'company_uuid', 'provider', 'credentials', 'options', 'sandbox', 'status', '_key'],
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
    app()->instance('request', Request::create('/int/v1/morph-search', 'GET'));
    if (!app()->bound('url')) {
        app()->instance('url', new class {
            public function current()
            {
                return 'https://fleetops.test/int/v1/morph-search';
            }

            public function __call($method, $arguments)
            {
                return 'https://fleetops.test/int/v1/morph-search';
            }
        });
    }
    Illuminate\Support\Facades\URL::clearResolvedInstance('url');

    return $connection;
}

test('morph controller helpers build queries paginators inputs and resources', function () {
    $connection = fleetopsMorphHelpersBoot();
    $connection->table('contacts')->insert(['uuid' => 'contact-morph-1', 'company_uuid' => 'company-1', 'name' => 'Morph Contact']);
    $connection->table('vendors')->insert(['uuid' => 'vendor-morph-1', 'company_uuid' => 'company-1', 'name' => 'Morph Vendor']);
    $connection->table('integrated_vendors')->insert(['uuid' => 'iv-morph-1', 'company_uuid' => 'company-1', 'provider' => 'lalamove']);

    $controller = new MorphController();
    $helper     = function (string $method, ...$arguments) use ($controller) {
        $reflection = new ReflectionMethod(MorphController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$arguments);
    };

    // Query builders hit their base tables
    expect($helper('newContactQuery')->count())->toBe(1)
        ->and($helper('newVendorQuery')->count())->toBe(1)
        ->and($helper('newIntegratedVendorQuery', 'company-1')->count())->toBe(1)
        ->and($helper('newIntegratedVendorQuery', 'company-2')->count())->toBe(0);

    // Paginator construction keeps totals and page geometry
    $paginator = $helper('newLengthAwarePaginator', collect(['a', 'b']), 10, 2, 1, ['path' => '/int/v1/morph-search']);
    expect($paginator)->toBeInstanceOf('Illuminate\\Pagination\\LengthAwarePaginator')
        ->and($paginator->total())->toBe(10)
        ->and($paginator->perPage())->toBe(2);

    // Input normalization coerces scalars and null into arrays
    $request = Request::create('/int/v1/morph-search', 'GET', ['one' => 'value', 'many' => ['a', 'b'], 'blank' => null, 'filled' => 'yes']);
    expect($helper('arrayInput', $request, 'one'))->toBe(['value'])
        ->and($helper('arrayInput', $request, 'many'))->toBe(['a', 'b'])
        ->and($helper('arrayInput', $request, 'blank'))->toBe([])
        ->and($helper('firstInput', $request, ['missing', 'filled']))->toBe('yes')
        ->and($helper('firstInput', $request, ['missing', 'blank']))->toBeNull();

    // Url + json helpers proxy the framework services
    expect($helper('currentUrl'))->toContain('morph-search')
        ->and($helper('jsonResponse', ['ok' => true]))->toBeInstanceOf(Illuminate\Http\JsonResponse::class);

    // Resource wrappers accept models and collections
    $contact = Contact::where('uuid', 'contact-morph-1')->first();
    $vendor  = Vendor::where('uuid', 'vendor-morph-1')->first();
    expect($helper('contactResource', $contact))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Contact::class)
        ->and($helper('vendorResource', $vendor))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Vendor::class)
        ->and($helper('contactResourceCollection', collect([$contact])))->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class)
        ->and($helper('vendorResourceCollection', collect([$vendor])))->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class);
});
