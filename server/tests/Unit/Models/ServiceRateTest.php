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

use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Models\ServiceRateFee;
use Fleetbase\FleetOps\Models\ServiceRateParcelFee;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FleetOpsServiceRateUnitFake extends ServiceRate
{
    public function load($relations)
    {
        return $this;
    }

    public function loadMissing($relations)
    {
        return $this;
    }
}

class FleetOpsServiceRateUnitGeometryFake extends FleetOpsServiceRateUnitFake
{
    protected function readRateRuleGeometry(ServiceRateFee $rule, Brick\Geo\IO\GeoJSONReader $reader)
    {
        return new FleetOpsServiceRateUnitContainsGeometry();
    }
}

class FleetOpsServiceRateUnitContainsGeometry
{
    public function __construct(private bool $contains = true)
    {
    }

    public function contains($point): bool
    {
        return $this->contains;
    }
}

class FleetOpsServiceRateUnitGeometryBorder
{
    public function __construct(private array $geojson)
    {
    }

    public function toJson(): string
    {
        return json_encode($this->geojson);
    }
}

class FleetOpsServiceRateUnitPayloadFake extends Payload
{
    private Collection $stops;

    private Collection $entityCollection;

    public function __construct(?Collection $stops = null, ?Collection $entityCollection = null, ?int $codAmount = 500)
    {
        parent::__construct(['cod_amount' => $codAmount]);

        $this->stops            = $stops ?? collect();
        $this->entityCollection = $entityCollection ?? collect();
    }

    public function getAllStops()
    {
        return $this->stops;
    }

    public function getAttribute($key)
    {
        return match ($key) {
            'entities'   => $this->entityCollection,
            'pickup'     => $this->stops->first(),
            'dropoff'    => $this->stops->get(1),
            'cod_amount' => $this->attributes['cod_amount'] ?? null,
            default      => parent::getAttribute($key),
        };
    }
}

function fleetopsServiceRateUnitUseInMemoryRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

function fleetopsServiceRateUnitPayload(array $entities = []): Payload
{
    $pickup  = new Point(1.3000, 103.8000);
    $dropoff = new Point(1.3000, 103.8000);

    return new FleetOpsServiceRateUnitPayloadFake(collect([$pickup, $dropoff]), collect($entities));
}

function fleetopsServiceRateUnitParcel(array $overrides = []): Entity
{
    return new Entity(array_merge([
        'type'            => 'parcel',
        'length'          => 15,
        'width'           => 12,
        'height'          => 8,
        'dimensions_unit' => 'cm',
        'weight'          => 800,
        'weight_unit'     => 'g',
    ], $overrides));
}

function fleetopsServiceRateUnitParcelFee(array $overrides = []): ServiceRateParcelFee
{
    return new ServiceRateParcelFee(array_merge([
        'size'            => 'small_box',
        'length'          => 20,
        'width'           => 20,
        'height'          => 20,
        'dimensions_unit' => 'cm',
        'weight'          => 1000,
        'weight_unit'     => 'g',
        'fee'             => 125,
    ], $overrides));
}

function fleetopsServiceRateUnitPolygon(array $bounds = [103.70, 1.20, 103.95, 1.45]): array
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

beforeEach(function () {
    fleetopsServiceRateUnitUseInMemoryRelationConnection();
    Carbon::setTestNow(Carbon::parse('2026-07-27 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('service rate preliminary quotes cover parcel cod and peak hour branches', function () {
    $rate = new FleetOpsServiceRateUnitFake([
        'rate_calculation_method'       => 'parcel',
        'base_fee'                      => 100,
        'currency'                      => 'USD',
        'has_cod_fee'                   => true,
        'cod_calculation_method'        => 'flat',
        'cod_flat_fee'                  => 25,
        'has_peak_hours_fee'            => true,
        'peak_hours_calculation_method' => 'flat',
        'peak_hours_flat_fee'           => 40,
        'peak_hours_start'              => '00:00',
        'peak_hours_end'                => '23:59',
    ]);
    $rate->setRelation('parcelFees', collect([
        fleetopsServiceRateUnitParcelFee(['size' => 'medium_box', 'length' => 10, 'width' => 10, 'height' => 10, 'weight' => 500, 'fee' => 90]),
        fleetopsServiceRateUnitParcelFee(['size' => 'small_box', 'fee' => 125]),
    ]));

    [$total, $lines] = $rate->quoteFromPreliminaryData(
        [fleetopsServiceRateUnitParcel()],
        [new Place(), new Place()],
        0,
        0,
        true
    );

    expect($total)->toBe(290)
        ->and($lines)->toHaveCount(4)
        ->and($lines->pluck('code')->all())->toBe(['BASE_FEE', 'PARCEL_FEE', 'COD_FEE', 'PEAK_HOUR_FEE'])
        ->and($lines[1]['details'])->toBe('Small Box parcel fee');
});

test('service rate payload quote covers fixed drop per meter and algorithm branches', function (string $method, array $attributes, array $fees, int $expectedLineCount) {
    $rate = new FleetOpsServiceRateUnitFake(array_merge([
        'rate_calculation_method' => $method,
        'base_fee'                => 100,
        'currency'                => 'USD',
    ], $attributes));
    $rate->setRelation('rateFees', collect($fees));
    $rate->setRelation('parcelFees', collect());

    [$total, $lines] = $rate->quote(fleetopsServiceRateUnitPayload());

    expect($total)->toBeGreaterThanOrEqual(100)
        ->and($lines)->toHaveCount($expectedLineCount)
        ->and($lines->first()['code'])->toBe('BASE_FEE')
        ->and($lines->last()['details'])->toBe('Service Fee');
})->with([
    'fixed meter' => [
        'fixed_meter',
        [],
        [new ServiceRateFee(['distance' => 3, 'fee' => 250])],
        2,
    ],
    'per drop' => [
        'per_drop',
        [],
        [new ServiceRateFee(['min' => 2, 'max' => 3, 'fee' => 175])],
        2,
    ],
    'per meter' => [
        'per_meter',
        ['per_meter_unit' => 'km', 'per_meter_flat_rate_fee' => 25],
        [],
        2,
    ],
    'algorithm' => [
        'algo',
        ['algorithm' => '{base_fee} + {stops} + {entities}'],
        [],
        2,
    ],
]);

test('service rate payload quote applies parcel cod and percentage peak hour fees', function () {
    $rate = new FleetOpsServiceRateUnitFake([
        'rate_calculation_method'       => 'parcel',
        'base_fee'                      => 200,
        'currency'                      => 'USD',
        'has_cod_fee'                   => true,
        'cod_calculation_method'        => 'percentage',
        'cod_percent'                   => 10,
        'has_peak_hours_fee'            => true,
        'peak_hours_calculation_method' => 'percentage',
        'peak_hours_percent'            => 5,
        'peak_hours_start'              => '00:00',
        'peak_hours_end'                => '23:59',
    ]);
    $rate->setRelation('rateFees', collect());
    $rate->setRelation('parcelFees', collect([
        fleetopsServiceRateUnitParcelFee(['size' => 'document_pack', 'fee' => 50]),
    ]));

    [$total, $lines] = $rate->quote(fleetopsServiceRateUnitPayload([
        fleetopsServiceRateUnitParcel(['length' => 8, 'width' => 8, 'height' => 2, 'weight' => 200]),
    ]));

    expect($total)->toBe(288)
        ->and($lines)->toHaveCount(4)
        ->and($lines->pluck('code')->all())->toBe(['BASE_FEE', 'PARCEL_FEE', 'COD_FEE', 'PEAK_HOUR_FEE'])
        ->and($lines[1]['details'])->toBe('Document Pack parcel fee');
});

test('service rate multi zone distance pricing covers geometry matching scaling and guards', function () {
    $rate = new FleetOpsServiceRateUnitGeometryFake([
        'rate_calculation_method' => 'multi_zone_distance',
        'base_fee'                => 100,
        'currency'                => 'USD',
    ]);

    $zoneRule = new ServiceRateFee([
        'uuid'          => 'zone-rule',
        'label'         => 'Core',
        'priority'      => 20,
        'is_fallback'   => false,
        'distance_unit' => 'km',
        'fee'           => 3,
    ]);
    $zoneRule->setRelation('zone', (object) [
        'name'   => 'Core',
        'border' => new FleetOpsServiceRateUnitGeometryBorder(fleetopsServiceRateUnitPolygon()),
    ]);

    $fallbackRule = new ServiceRateFee([
        'uuid'          => 'fallback-rule',
        'priority'      => 1,
        'is_fallback'   => true,
        'distance_unit' => 'mi',
        'fee'           => 10,
    ]);

    $rate->setRelation('rateFees', collect([$fallbackRule, $zoneRule]));

    $pickup  = new Place(['location' => new Point(1.30, 103.80)]);
    $dropoff = new Place(['location' => new Point(1.35, 103.85)]);

    $reflection          = new ReflectionClass(ServiceRate::class);
    $quoteMultiZone      = $reflection->getMethod('quoteMultiZoneDistance');
    $calculateDistances  = $reflection->getMethod('calculateMultiZoneDistances');
    $readGeometry        = $reflection->getMethod('readRateRuleGeometry');
    $matchRule           = $reflection->getMethod('matchMultiZoneRule');
    $placePoint          = $reflection->getMethod('getLngLatFromPlace');
    $distanceNormalizer  = $reflection->getMethod('normalizeDistanceForUnit');
    $endpointInferrer    = $reflection->getMethod('inferEndpointCountFromStops');
    $weightNormalizer    = $reflection->getMethod('normalizeEntityWeightToKilograms');
    $algorithmVariables  = $reflection->getMethod('buildAlgorithmVariables');

    $reader       = new Brick\Geo\IO\GeoJSONReader();
    $zoneGeometry = $readGeometry->invoke(new FleetOpsServiceRateUnitFake(), $zoneRule, $reader);
    $arrayRule    = new ServiceRateFee(['is_fallback' => false]);
    $arrayRule->setRelation('serviceArea', (object) [
        'border' => fleetopsServiceRateUnitPolygon([103.00, 1.00, 104.20, 1.80]),
    ]);
    $arrayGeometry = $readGeometry->invoke(new FleetOpsServiceRateUnitFake(), $arrayRule, $reader);

    [$total, $lines] = $quoteMultiZone->invoke($rate, [$pickup, $dropoff], 10000);
    $distances       = $calculateDistances->invoke($rate, [$pickup, $dropoff], collect([$zoneRule]), $fallbackRule, 10000);
    $matchedRule     = $matchRule->invoke($rate, ['lat' => 1.31, 'lng' => 103.81], collect([
        ['rule' => $zoneRule, 'geometry' => new FleetOpsServiceRateUnitContainsGeometry()],
    ]));
    $missingRule = $matchRule->invoke($rate, ['lat' => 9.99, 'lng' => 9.99], collect([
        ['rule' => $zoneRule, 'geometry' => new FleetOpsServiceRateUnitContainsGeometry(false)],
    ]));

    $variables = $algorithmVariables->invoke($rate, [
        ['type' => 'parcel', 'weight' => 1000, 'weight_unit' => 'g'],
        ['type' => 'parcel', 'weight' => 2, 'weight_unit' => 'pounds'],
        ['type' => 'item', 'weight' => 1, 'weight_unit' => 'metric_ton'],
    ], [$pickup, $dropoff, new Place()], 10000, 900, 2);

    expect($zoneGeometry)->not->toBeNull()
        ->and($arrayGeometry)->not->toBeNull()
        ->and($total)->toBe(30)
        ->and($lines)->toHaveCount(1)
        ->and($lines->first()['details'])->toContain('Core distance charge')
        ->and($distances[0]['rule'])->toBe($zoneRule)
        ->and((int) round($distances[0]['distance_m']))->toBe(10000)
        ->and($matchedRule)->toBe($zoneRule)
        ->and($missingRule)->toBeNull()
        ->and($placePoint->invoke($rate, $pickup))->toBe(['lat' => 1.3, 'lng' => 103.8])
        ->and(round($distanceNormalizer->invoke($rate, 3218.688, 'mi'), 3))->toBe(2.0)
        ->and($endpointInferrer->invoke($rate, []))->toBe(0)
        ->and(round($weightNormalizer->invoke($rate, ['weight' => 16, 'weight_unit' => 'ounces']), 4))->toBe(0.4536)
        ->and($variables)->toMatchArray([
            'distance_m' => 10000,
            'time_s'     => 900,
            'stops'      => 3,
            'waypoints'  => 1,
            'parcels'    => 2,
            'entities'   => 3,
        ])
        ->and(round($variables['weight_kg'], 4))->toBe(1001.9072);
});
