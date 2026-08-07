<?php

use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Zone;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the fallbacks the CSV importers take on rows that omit the columns
 * every fixture supplies, plus the zone centroid fallback for a border that
 * never became a polygon.
 */
// The vehicle-name parser reads its make/model dataset relative to the
// installed package path, which does not exist when running in-tree.
if (!function_exists('Fleetbase\FleetOps\Support\base_path')) {
    eval('namespace Fleetbase\FleetOps\Support; function base_path($path = "") { return getcwd() . "/" . str_replace("vendor/fleetbase/fleetops-api/", "", $path); }');
}

function fleetopsImportSeamBoot(): SQLiteConnection
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

    $schema  = $connection->getSchemaBuilder();
    $columns = ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'driver_uuid', 'vehicle_uuid', 'name', 'type', 'status', 'make', 'model', 'year', '_key'];
    foreach (['users', 'drivers', 'vehicles', 'issues'] as $table) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-import-1']);

    return $connection;
}

test('vehicle imports fall back to the raw name when nothing parses out of it', function () {
    fleetopsImportSeamBoot();

    // A name the vehicle-data parser cannot decompose leaves make unset, and
    // dropping the row's only identifying value would lose the vehicle.
    $vehicle = Vehicle::createFromImport(['vehicle' => 'Zzqx Unparseable 9000']);

    expect($vehicle->make)->toBe('Zzqx Unparseable 9000');
});

test('issue imports without any location column resolve to no point', function () {
    fleetopsImportSeamBoot();

    $issue = Issue::createFromImport(['issue' => 'Cracked windscreen', 'type' => 'vehicle']);

    expect($issue)->toBeInstanceOf(Issue::class);
});

test('zone centroids fall back to null island when the border is not a polygon', function () {
    fleetopsImportSeamBoot();

    // The engine is resolved before the border is inspected, so it has to be
    // present even on the path that never asks it for a centroid.
    Brick\Geo\Engine\GeometryEngineRegistry::set(new class(new PDO('sqlite::memory:'), false) extends Brick\Geo\Engine\PDOEngine {
        public function centroid(Brick\Geo\Geometry $g): Brick\Geo\Point
        {
            return Brick\Geo\Point::xy(1.33, 103.83);
        }
    });

    $zone = new Zone();
    $zone->setRawAttributes(['uuid' => 'zone-import-1', 'name' => 'Borderless', 'border' => null], true);

    $centroid = $zone->getCentroid();

    expect($centroid)->toBeInstanceOf(Brick\Geo\Point::class)
        ->and($centroid->x())->toBe(0.0)
        ->and($centroid->y())->toBe(0.0);
});
