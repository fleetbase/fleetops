<?php

use Fleetbase\FleetOps\Exports\PlaceExport;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\PlaceController;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Covers the internal PlaceController geocode/export/import endpoints and the
 * real bodies of its protected helper methods. The existing contracts test
 * overrides every helper on its probe, so the genuine implementations
 * (Place::query(), PlaceSearch::search/geocode, Place::getAvatarOptions and
 * the custom-field sync delegation) were never executed.
 */
if (!class_exists('Fleetbase\Http\Requests\ExportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ExportRequest extends \Illuminate\Http\Request {}');
}

if (!class_exists('Fleetbase\Http\Requests\ImportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ImportRequest extends \Illuminate\Http\Request {}');
}

if (!Request::hasMacro('searchQuery')) {
    Request::macro('searchQuery', fn () => $this->input('query', $this->input('search', $this->input('searchQuery'))));
}

if (!Request::hasMacro('resolveFilesFromIds')) {
    Request::macro('resolveFilesFromIds', fn () => FleetOpsInternalPlaceEndpointsState::$files);
}

if (!Request::hasMacro('or')) {
    Request::macro('or', function (array $params = [], $default = null) {
        foreach ($params as $param) {
            if ($this->has($param)) {
                return $this->input($param);
            }
        }

        return $default;
    });
}

class FleetOpsInternalPlaceEndpointsState
{
    public static array $files = [];
}

class FleetOpsInternalPlaceEndpointsExcelFake
{
    public array $downloads    = [];
    public array $imports      = [];
    public bool $importFails   = false;

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

class FleetOpsInternalPlaceEndpointsPlaceFake extends Place
{
    protected $guarded         = [];
    public $exists             = true;
    public array $syncedValues = [];

    public function syncCustomFieldValues(array $payload, array $options = []): array
    {
        $this->syncedValues[] = $payload;

        return $payload;
    }
}

class FleetOpsInternalPlaceUpdateFake extends Place
{
    public function applyDirectivesToQuery(Request $request, $builder)
    {
        return $builder;
    }
}

class FleetOpsInternalPlaceEndpointsProbe extends PlaceController
{
    public function callProtected(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(PlaceController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsInternalPlaceEndpointsBoot(): SQLiteConnection
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

    $schema = $connection->getSchemaBuilder();
    $schema->create('places', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('street1')->nullable();
        $table->string('street2')->nullable();
        $table->string('city')->nullable();
        $table->string('province')->nullable();
        $table->string('postal_code')->nullable();
        $table->string('location')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('original_filename')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    return $connection;
}

function fleetopsInternalPlaceEndpointsExcel(): FleetOpsInternalPlaceEndpointsExcelFake
{
    $fake = new FleetOpsInternalPlaceEndpointsExcelFake();
    app()->instance('excel', $fake);
    Maatwebsite\Excel\Facades\Excel::clearResolvedInstance('excel');

    return $fake;
}

test('geocode endpoint returns empty results without a query or coordinates', function () {
    $request  = ExportRequest::create('/int/v1/places/geocode', 'GET');
    $response = (new PlaceController())->geocode($request);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->getData(true))->toBe([]);
});

test('export endpoint streams a place export download with a dated filename', function () {
    $excel = fleetopsInternalPlaceEndpointsExcel();

    $request  = ExportRequest::create('/int/v1/places/export', 'POST', ['format' => 'csv', 'selections' => ['place-1']]);
    $response = (new PlaceController())->export($request);

    expect($response)->toStartWith('downloaded:places-')
        ->and($response)->toEndWith('.csv')
        ->and($excel->downloads)->toHaveCount(1)
        ->and($excel->downloads[0][0])->toBeInstanceOf(PlaceExport::class);
});

test('import endpoint imports each resolved file and reports the count', function () {
    $excel                                      = fleetopsInternalPlaceEndpointsExcel();
    FleetOpsInternalPlaceEndpointsState::$files = [
        (object) ['path' => 'uploads/places-a.xlsx'],
        (object) ['path' => 'uploads/places-b.xlsx'],
    ];

    $request  = ImportRequest::create('/int/v1/places/import', 'POST', ['disk' => 'local']);
    $response = (new PlaceController())->import($request);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getData(true))->toBe(['status' => 'ok', 'message' => 'Import completed', 'imported' => 2])
        ->and($excel->imports)->toHaveCount(2);
});

test('import endpoint returns an error response for unreadable files', function () {
    $excel                                      = fleetopsInternalPlaceEndpointsExcel();
    $excel->importFails                         = true;
    FleetOpsInternalPlaceEndpointsState::$files = [(object) ['path' => 'uploads/corrupt.xlsx']];

    $request  = ImportRequest::create('/int/v1/places/import', 'POST', []);
    $response = (new PlaceController())->import($request);

    expect($response->getData(true))->toBe(['error' => 'Invalid file, unable to proccess.']);
});

test('place query helper builds an eloquent builder for places', function () {
    fleetopsInternalPlaceEndpointsBoot();

    $query = (new FleetOpsInternalPlaceEndpointsProbe())->callProtected('newPlaceQuery');

    expect($query)->toBeInstanceOf(EloquentBuilder::class)
        ->and($query->getModel())->toBeInstanceOf(Place::class);
});

test('search places helper runs the place search pipeline against the database', function () {
    $connection = fleetopsInternalPlaceEndpointsBoot();
    $connection->table('places')->insert(['uuid' => 'place-1', 'name' => 'Central Depot', 'company_uuid' => 'company-1']);

    $results = (new FleetOpsInternalPlaceEndpointsProbe())->callProtected('searchPlaces', [
        Place::query(),
        'Depot',
        ['limit' => 5, 'no_query_order' => 'name_desc'],
    ]);

    expect($results)->toBeInstanceOf(Collection::class)
        ->and($results->count())->toBe(1)
        ->and($results->first()->name)->toBe('Central Depot');
});

test('geocode places helper returns an empty collection without inputs', function () {
    $results = (new FleetOpsInternalPlaceEndpointsProbe())->callProtected('geocodePlaces', [null, null, null]);

    expect($results)->toBeInstanceOf(Collection::class)
        ->and($results->isEmpty())->toBeTrue();
});

test('avatar options helper merges custom avatars with fleetbase defaults', function () {
    $connection = fleetopsInternalPlaceEndpointsBoot();
    $connection->table('files')->insert([
        'uuid'              => 'file-1',
        'type'              => 'place-avatar',
        'original_filename' => 'depot.png',
    ]);

    $options = (new FleetOpsInternalPlaceEndpointsProbe())->callProtected('avatarOptions');

    expect($options)->toBeArray()
        ->and($options)->toHaveKey('Custom: depot')
        ->and($options)->toHaveKey('basic-building');
});

test('sync custom field values helper delegates to the place model', function () {
    $place = new FleetOpsInternalPlaceEndpointsPlaceFake();

    (new FleetOpsInternalPlaceEndpointsProbe())->callProtected('syncCustomFieldValues', [$place, ['priority' => 'high']]);

    expect($place->syncedValues)->toBe([['priority' => 'high']]);
});

test('place updates accept serialized display-only attributes', function () {
    $connection = fleetopsInternalPlaceEndpointsBoot();
    $connection->table('places')->insert([
        'uuid'         => '77777777-7777-4777-8777-777777777777',
        'public_id'    => 'place_display1',
        'company_uuid' => 'company-1',
        'name'         => 'Original place',
        'street1'      => '205 Dostyk Avenue',
    ]);

    $place = (new FleetOpsInternalPlaceUpdateFake())->updateRecordFromRequest(Request::create('/int/v1/places/place_display1', 'PUT', [
        'place' => [
            'name'         => 'Updated place',
            'street2'      => 'Entrance 2',
            'avatar_value' => 'basic-building',
            'eta'          => '12 minutes',
        ],
    ]), 'place_display1', options: ['return_object' => true]);

    expect($place->name)->toBe('Updated place')
        ->and($place->street2)->toBe('Entrance 2')
        ->and($connection->table('places')->where('public_id', 'place_display1')->value('name'))->toBe('Updated place');
});
