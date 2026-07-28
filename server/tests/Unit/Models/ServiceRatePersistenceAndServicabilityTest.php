<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Support\config')) {
    eval('namespace Fleetbase\FleetOps\Support; function config($key = null, $default = null) { return $key === "fleetops.distance_matrix.provider" ? "calculate" : $default; }');
}

if (!function_exists('Cknow\Money\config')) {
    eval('namespace Cknow\Money; function config($key = null, $default = null) { return $default; }');
}

use Brick\Geo\Engine\GeometryEngineRegistry;
use Brick\Geo\Engine\PDOEngine;
use Brick\Geo\Point as BrickPoint;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the ServiceRate persistence setters for rate and parcel fees, the
 * servicable lookups for waypoints and places with geometry containment via a
 * SQLite-backed brick geometry engine, and the point-to-point quote flow.
 */
function fleetopsServiceRateGeoJson(array $bounds): array
{
    [$minLng, $minLat, $maxLng, $maxLat] = $bounds;

    return [
        'type'        => 'Polygon',
        'coordinates' => [[
            [$minLng, $minLat],
            [$maxLng, $minLat],
            [$maxLng, $maxLat],
            [$minLng, $maxLat],
            [$minLng, $minLat],
        ]],
    ];
}

class FleetOpsServiceRateBorderJson
{
    public function __construct(private string $json)
    {
    }

    public function toJson(): string
    {
        return $this->json;
    }
}

class FleetOpsServiceRateAreaHolder
{
    public function __construct(public mixed $border)
    {
    }
}

class FleetOpsServicableRate extends ServiceRate
{
    protected $table = 'service_rates';

    public function hasServiceArea(): bool
    {
        return !empty($this->attributes['area_mode']);
    }

    public function hasZone(): bool
    {
        return !empty($this->attributes['zone_mode']);
    }

    public function getAttribute($key)
    {
        if ($key === 'serviceArea') {
            return fleetopsServiceRateBorderHolder($this->attributes['area_mode'] ?? null);
        }
        if ($key === 'zone') {
            return fleetopsServiceRateBorderHolder($this->attributes['zone_mode'] ?? null);
        }

        return parent::getAttribute($key);
    }

    public function rateFees()
    {
        return $this->hasMany(Fleetbase\FleetOps\Models\ServiceRateFee::class, 'service_rate_uuid', 'uuid');
    }

    public function parcelFees()
    {
        return $this->hasMany(Fleetbase\FleetOps\Models\ServiceRateParcelFee::class, 'service_rate_uuid', 'uuid');
    }
}

/**
 * Decode point and polygon WKB into space-separated coordinate pairs so the
 * SQLite containment shim can bounding-box match them.
 */
function fleetopsServiceRateWkbToCoordinateText(string $wkb): string
{
    $offset   = 1;
    $readUInt = function () use (&$offset, $wkb) {
        $value = unpack('V', substr($wkb, $offset, 4))[1];
        $offset += 4;

        return $value;
    };

    $coords = [];
    $type   = $readUInt();
    if ($type === 1) {
        $pair     = unpack('d2', substr($wkb, $offset, 16));
        $coords[] = $pair[1] . ' ' . $pair[2];
    } elseif ($type === 3) {
        $rings = $readUInt();
        for ($ring = 0; $ring < $rings; $ring++) {
            $points = $readUInt();
            for ($i = 0; $i < $points; $i++) {
                $pair = unpack('d2', substr($wkb, $offset, 16));
                $offset += 16;
                $coords[] = $pair[1] . ' ' . $pair[2];
            }
        }
    }

    return implode(', ', $coords);
}

function fleetopsServiceRateBorderHolder(?string $mode): ?FleetOpsServiceRateAreaHolder
{
    $near = fleetopsServiceRateGeoJson([103.70, 1.20, 103.95, 1.45]);
    $far  = fleetopsServiceRateGeoJson([10.0, 10.0, 11.0, 11.0]);

    return match ($mode) {
        'list'    => new FleetOpsServiceRateAreaHolder([new FleetOpsServiceRateBorderJson(json_encode($near))]),
        'single'  => new FleetOpsServiceRateAreaHolder(new FleetOpsServiceRateBorderJson(json_encode($near))),
        'blank'   => new FleetOpsServiceRateAreaHolder(new FleetOpsServiceRateBorderJson('')),
        'string'  => new FleetOpsServiceRateAreaHolder(json_encode($near)),
        'far'     => new FleetOpsServiceRateAreaHolder(json_encode($far)),
        'array'   => new FleetOpsServiceRateAreaHolder($near),
        'invalid' => new FleetOpsServiceRateAreaHolder(new FleetOpsServiceRateBorderJson('{bad json')),
        default   => null,
    };
}

function fleetopsServiceRatePersistenceBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromWKB', fn ($wkb, $srid = 0) => fleetopsServiceRateWkbToCoordinateText((string) $wkb));
    $pdo->sqliteCreateFunction('ST_Contains', function ($container, $contained) {
        preg_match_all('/(-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?)/', (string) $container, $poly);
        preg_match_all('/(-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?)/', (string) $contained, $point);
        if (empty($poly[1]) || empty($point[1])) {
            return 0;
        }
        $xs = array_map('floatval', $poly[1]);
        $ys = array_map('floatval', $poly[2]);
        $px = (float) $point[1][0];
        $py = (float) $point[2][0];

        return ($px >= min($xs) && $px <= max($xs) && $py >= min($ys) && $py <= max($ys)) ? 1 : 0;
    });
    GeometryEngineRegistry::set(new PDOEngine($pdo, false));

    $connection = new SQLiteConnection($pdo);
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
        'service_rates'            => ['uuid', 'public_id', 'company_uuid', 'service_area_uuid', 'zone_uuid', 'service_name', 'service_type', 'currency', 'base_fee', 'rate_calculation_method', 'area_mode', 'zone_mode'],
        'service_rate_fees'        => ['uuid', 'service_rate_uuid', 'service_area_uuid', 'zone_uuid', 'label', 'priority', 'is_fallback', 'distance', 'distance_unit', 'min', 'max', 'unit', 'fee', 'currency', '_key'],
        'service_rate_parcel_fees' => ['uuid', 'service_rate_uuid', 'size', 'length', 'width', 'height', 'dimensions_unit', 'weight', 'weight_unit', 'fee', 'currency', '_key'],
        'service_areas'            => ['uuid', 'public_id', 'company_uuid', 'name', 'border', 'type'],
        'zones'                    => ['uuid', 'public_id', 'company_uuid', 'service_area_uuid', 'name', 'border'],
        'places'                   => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'location', 'type'],
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

    app()->instance('geocoder', new class {
        public function geocode($query)
        {
            return $this;
        }

        public function reverse($lat, $lng)
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

    session(['company' => 'company-1']);

    return $connection;
}

test('set service rate fees updates existing rows and inserts normalized new rows', function () {
    $connection = fleetopsServiceRatePersistenceBoot();
    $rate       = (new ServiceRate())->forceFill(['uuid' => 'rate-1']);

    expect($rate->setServiceRateFees(null))->toBe($rate)
        ->and($rate->setServiceRateFees(['not-an-array']))->toBe($rate)
        ->and($connection->table('service_rate_fees')->count())->toBe(0);

    $connection->table('service_rate_fees')->insert(['uuid' => 'fee-1', 'service_rate_uuid' => 'rate-1', 'min' => 0, 'max' => 5, 'fee' => 100, 'currency' => 'USD']);

    $rate->setServiceRateFees([
        ['uuid' => 'fee-1', 'min' => 0, 'max' => 10, 'fee' => 250, 'currency' => 'USD'],
        ['min' => 11, 'max' => 20, 'fee' => 400, 'currency' => 'USD', 'is_fallback' => 1, 'service_area' => ['uuid' => 'sa-1'], 'zone' => ['uuid' => 'zone-1']],
    ]);

    $updated = $connection->table('service_rate_fees')->where('uuid', 'fee-1')->first();
    expect((int) $updated->max)->toBe(10)
        ->and((int) $updated->fee)->toBe(250);

    $inserted = $connection->table('service_rate_fees')->where('uuid', '!=', 'fee-1')->first();
    expect($inserted->service_rate_uuid)->toBe('rate-1')
        ->and($inserted->service_area_uuid)->toBeNull()
        ->and($inserted->zone_uuid)->toBeNull()
        ->and((int) $inserted->fee)->toBe(400);
});

test('set service rate parcel fees dedupes updates deletes and inserts', function () {
    $connection = fleetopsServiceRatePersistenceBoot();
    $rate       = (new ServiceRate())->forceFill(['uuid' => 'rate-1']);

    expect($rate->setServiceRateParcelFees(null))->toBe($rate);

    $connection->table('service_rate_parcel_fees')->insert([
        ['uuid' => 'pf-1', 'service_rate_uuid' => 'rate-1', 'size' => 'small', 'fee' => 100, 'length' => 1, 'width' => 1, 'height' => 1, 'dimensions_unit' => 'cm', 'weight' => 1, 'weight_unit' => 'g', 'currency' => 'USD', '_key' => null],
        ['uuid' => 'pf-2', 'service_rate_uuid' => 'rate-1', 'size' => 'medium', 'fee' => 200, 'length' => 2, 'width' => 2, 'height' => 2, 'dimensions_unit' => 'cm', 'weight' => 2, 'weight_unit' => 'g', 'currency' => 'USD', '_key' => null],
    ]);

    // pf-1 is updated, pf-2 (not submitted) is deleted, duplicate large rows
    // collapse to the latest submitted fee before insert
    $rate->setServiceRateParcelFees([
        ['uuid' => 'pf-1', 'size' => 'small', 'fee' => 150, 'length' => 1, 'width' => 1, 'height' => 1, 'dimensions_unit' => 'cm', 'weight' => 1, 'weight_unit' => 'g', 'currency' => 'USD'],
        ['size' => 'large', 'fee' => 300, 'length' => 9, 'width' => 9, 'height' => 9, 'dimensions_unit' => 'cm', 'weight' => 9, 'weight_unit' => 'g', 'currency' => 'USD'],
        ['size' => 'large', 'fee' => 350, 'length' => 9, 'width' => 9, 'height' => 9, 'dimensions_unit' => 'cm', 'weight' => 9, 'weight_unit' => 'g', 'currency' => 'USD'],
        'skip-me',
    ]);

    // Deletes are soft deletes, so live rows are the ones without deleted_at
    $live = fn () => $connection->table('service_rate_parcel_fees')->whereNull('deleted_at');
    expect((int) $live()->where('uuid', 'pf-1')->value('fee'))->toBe(150)
        ->and($live()->where('uuid', 'pf-2')->count())->toBe(0)
        ->and((int) $live()->where('size', 'large')->value('fee'))->toBe(350)
        ->and($live()->count())->toBe(2);

    // With no submitted uuids every existing row is replaced
    $rate->setServiceRateParcelFees([
        ['size' => 'xl', 'fee' => 900, 'length' => 20, 'width' => 20, 'height' => 20, 'dimensions_unit' => 'cm', 'weight' => 20, 'weight_unit' => 'g', 'currency' => 'USD'],
    ]);

    expect($live()->count())->toBe(1)
        ->and($live()->value('size'))->toBe('xl');
});

test('servicable for waypoints reads borders and checks polygon containment', function () {
    $connection = fleetopsServiceRatePersistenceBoot();

    $connection->table('service_rates')->insert([
        ['uuid' => 'rate-1', 'company_uuid' => 'company-1', 'area_mode' => 'list', 'zone_mode' => 'list', 'currency' => 'USD'],
        ['uuid' => 'rate-2', 'company_uuid' => 'company-1', 'area_mode' => null, 'zone_mode' => null, 'currency' => 'USD'],
    ]);

    $inside  = BrickPoint::fromText('POINT (103.8 1.3)', 4326);
    $outside = BrickPoint::fromText('POINT (50.0 50.0)', 4326);

    $ordered = [];
    $rates   = FleetOpsServicableRate::getServicableForWaypoints([$inside, $outside], function ($query) use (&$ordered) {
        $ordered[] = true;
        $query->orderBy('uuid');
    });

    expect($ordered)->toHaveCount(1)
        ->and($rates)->toHaveCount(2)
        ->and(collect($rates)->pluck('uuid')->all())->toBe(['rate-1', 'rate-2']);
});

test('servicable for places filters by geometry containment per border shape', function () {
    $connection = fleetopsServiceRatePersistenceBoot();

    expect(FleetOpsServicableRate::getServicableForPlaces([], 'transit', 'USD'))->toBe([]);

    $connection->table('service_rates')->insert([
        ['uuid' => 'rate-contained', 'company_uuid' => 'company-1', 'area_mode' => 'single', 'zone_mode' => null, 'service_type' => 'transit', 'currency' => 'USD'],
        ['uuid' => 'rate-blank-border', 'company_uuid' => 'company-1', 'area_mode' => 'blank', 'zone_mode' => null, 'service_type' => 'transit', 'currency' => 'USD'],
        ['uuid' => 'rate-far-zone', 'company_uuid' => 'company-1', 'area_mode' => null, 'zone_mode' => 'far', 'service_type' => 'transit', 'currency' => 'USD'],
        ['uuid' => 'rate-open', 'company_uuid' => 'company-1', 'area_mode' => null, 'zone_mode' => null, 'service_type' => 'transit', 'currency' => 'USD'],
        ['uuid' => 'rate-invalid-border', 'company_uuid' => 'company-1', 'area_mode' => 'invalid', 'zone_mode' => null, 'service_type' => 'transit', 'currency' => 'USD'],
        ['uuid' => 'rate-array-border', 'company_uuid' => 'company-1', 'area_mode' => 'array', 'zone_mode' => null, 'service_type' => 'transit', 'currency' => 'USD'],
        ['uuid' => 'rate-string-border', 'company_uuid' => 'company-1', 'area_mode' => 'string', 'zone_mode' => null, 'service_type' => 'transit', 'currency' => 'USD'],
    ]);

    $place = new Place(['location' => new Point(1.3, 103.8)]);
    $rates = FleetOpsServicableRate::getServicableForPlaces([$place], 'transit', 'USD');

    expect(collect($rates)->pluck('uuid')->all())->toBe([
        'rate-contained',
        'rate-open',
        'rate-array-border',
        'rate-string-border',
    ]);
});

test('point quote calculates distance and returns base fee line items', function () {
    fleetopsServiceRatePersistenceBoot();

    $rate = (new ServiceRate())->forceFill([
        'uuid'     => 'rate-1',
        'base_fee' => 500,
        'currency' => 'USD',
    ]);

    [$subTotal, $lines] = $rate->pointQuote('1.30,103.80', '1.35,103.85');

    expect($subTotal)->toBe(500)
        ->and($lines->first()['code'])->toBe('BASE_FEE')
        ->and($lines->first()['currency'])->toBe('USD');
});
