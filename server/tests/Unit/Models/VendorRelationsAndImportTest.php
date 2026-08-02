<?php

use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the Vendor relation builders and import creation: connected
 * company/personnel/order/file relations and place-resolved vendor rows.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

function fleetopsVendorModelBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    foreach (['ST_PointFromText', 'ST_GeomFromText'] as $fn) {
        $pdo->sqliteCreateFunction($fn, fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    }
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
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

    $schema = $connection->getSchemaBuilder();
    $schema->create('companies', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'name', 'country', 'options'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });
    $schema->create('vendors', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'connect_company_uuid', 'place_uuid', 'name', 'email', 'phone', 'address', 'website', 'country', 'type', 'status', 'meta', 'internal_id', 'slug', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    session(['company' => 'company-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Vendor Co']);
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    return $connection;
}

test('vendor relations and imports resolve builders and persist rows', function () {
    $connection = fleetopsVendorModelBoot();

    $vendor = new Vendor();
    expect($vendor->company())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($vendor->connectCompany())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($vendor->vendorPersonnel())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\HasMany::class)
        ->and($vendor->personnels())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\HasManyThrough::class)
        ->and($vendor->facilitatorOrders())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\HasMany::class)
        ->and($vendor->customerOrders())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\HasMany::class)
        ->and($vendor->files())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\HasMany::class);

    // Imports persist vendors and adopt resolved places
    $place = new Fleetbase\FleetOps\Models\Place();
    $place->setRawAttributes(['uuid' => 'place-vendor-1', 'public_id' => 'place_vendorone'], true);
    $place->exists = true;

    $created = Vendor::createFromImport([
        'name'    => 'Imported Vendor',
        'phone'   => '+6588881234',
        'email'   => 'imported@example.test',
        'website' => 'https://vendor.example.test',
        'address' => $place,
    ], true);

    expect($created)->toBeInstanceOf(Vendor::class)
        ->and($created->place_uuid)->toBe('place-vendor-1')
        ->and($connection->table('vendors')->count())->toBe(1);
});
