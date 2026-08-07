<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\env')) {
    eval('namespace Fleetbase\Support; function env($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Covers the internal OrderController importFromFiles endpoint against
 * SQLite with faked ip lookup, excel reader and geocoder: spreadsheet rows
 * imported into places with delimited entity items, empty-row skips,
 * invalid file type rejection, and unreadable spreadsheet errors.
 */
function fleetopsOrderImportFilesBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
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

    app()->instance('request', Request::create('/int/v1/orders/import-from-files', 'POST'));

    app()->instance('geocoder', new class {
        public function geocode($query)
        {
            return $this;
        }

        public function reverse($latitude, $longitude)
        {
            return $this;
        }

        public function get()
        {
            return collect();
        }

        public function __call($method, $arguments)
        {
            return $this;
        }
    });

    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);
    Http::fake(['api.ipdata.co/*' => Http::response(['country_name' => 'Singapore'], 200)]);

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'files'  => ['uuid', 'public_id', 'company_uuid', 'name', 'original_filename', 'extension', 'content_type', 'path', 'bucket', 'disk', 'type', 'size', 'meta', '_key'],
        'places' => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'name', 'street1', 'street2', 'city', 'province', 'postal_code', 'country', 'neighborhood', 'building', 'phone', 'location', 'meta', 'type', '_key', '_import_id'],
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

    session(['company' => 'company-1', 'api_key' => 'console']);

    return $connection;
}

function fleetopsOrderImportFilesExcelFake(array $rows, bool $throws = false): void
{
    app()->instance('excel', new class($rows, $throws) {
        public function __construct(public array $rows, public bool $throws)
        {
        }

        public function toArray($import, $path, $disk = null)
        {
            if ($this->throws) {
                throw new RuntimeException('corrupt spreadsheet');
            }

            return $this->rows;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
}

function fleetopsOrderImportFilesRequest(array $input): Request
{
    $request = Request::create('/int/v1/orders/import-from-files', 'POST', $input);
    $store   = app('session.store');
    $store->put('company', 'company-1');
    $request->setLaravelSession($store);

    return $request;
}

test('spreadsheet rows import places and delimited entities', function () {
    $connection = fleetopsOrderImportFilesBoot();
    $connection->table('files')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'file_ordimport1', 'company_uuid' => 'company-1', 'path' => 'uploads/orders.xlsx', 'disk' => 'local']);
    fleetopsOrderImportFilesExcelFake([[
        ['name' => 'Import Stop', 'street1' => 'Import Rd 1', 'city' => 'Singapore', 'items' => 'Box A|Box B'],
        // populated, but with nothing that resolves to an address — the row is
        // skipped rather than producing a place or its items
        ['items' => 'Orphan Box'],
        [],
    ]]);

    $response = (new OrderController())->importFromFiles(fleetopsOrderImportFilesRequest([
        'files' => ['11111111-1111-4111-8111-111111111111'],
    ]));

    $data = $response->getData(true);
    expect($data['places'])->toHaveCount(1)
        ->and($data['entities'])->toHaveCount(2)
        ->and($data['entities'][0]['name'])->toBe('Box A')
        ->and(collect($data['entities'])->pluck('name')->all())->not->toContain('Orphan Box');
});

test('invalid file types and unreadable spreadsheets return errors', function () {
    $connection = fleetopsOrderImportFilesBoot();
    $connection->table('files')->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'file_ordimport2', 'company_uuid' => 'company-1', 'path' => 'uploads/orders.pdf', 'disk' => 'local'],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'file_ordimport3', 'company_uuid' => 'company-1', 'path' => 'uploads/orders.csv', 'disk' => 'local'],
    ]);

    $controller = new OrderController();

    fleetopsOrderImportFilesExcelFake([]);
    $invalidType = $controller->importFromFiles(fleetopsOrderImportFilesRequest([
        'files' => ['11111111-1111-4111-8111-111111111111'],
    ]));
    expect($invalidType->getStatusCode())->toBeGreaterThanOrEqual(400);

    fleetopsOrderImportFilesExcelFake([], true);
    $unreadable = $controller->importFromFiles(fleetopsOrderImportFilesRequest([
        'files' => ['22222222-2222-4222-8222-222222222222'],
    ]));
    expect($unreadable->getStatusCode())->toBeGreaterThanOrEqual(400);
});
