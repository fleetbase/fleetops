<?php

use Fleetbase\FleetOps\Casts\MultiPolygon;
use Fleetbase\FleetOps\Casts\OrderConfigEntities;
use Fleetbase\FleetOps\Casts\Point as PointCast;
use Fleetbase\FleetOps\Casts\Polygon;
use Fleetbase\FleetOps\Rules\ComputableAlgo;
use Fleetbase\FleetOps\Rules\CustomerIdOrDetails;
use Fleetbase\FleetOps\Rules\ResolvablePoint;
use Fleetbase\FleetOps\Rules\ResolvableVehicle;
use Fleetbase\LaravelMysqlSpatial\Eloquent\SpatialExpression;
use Fleetbase\LaravelMysqlSpatial\Types\MultiPolygon as SpatialMultiPolygon;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\LaravelMysqlSpatial\Types\Polygon as SpatialPolygon;
use Illuminate\Database\Query\Expression;

class FleetOpsOrderConfigEntitiesCastProbe extends OrderConfigEntities
{
    public array $photoUrls = [];

    protected function photoUrlFor(string $photoUuid): ?string
    {
        return $this->photoUrls[$photoUuid] ?? null;
    }
}

test('customer detail validation accepts useful inline customer payloads', function () {
    $rule = new CustomerIdOrDetails();

    expect($rule->passes('customer', ['name' => 'Jane Customer']))->toBeTrue()
        ->and($rule->passes('customer', ['email' => 'jane@example.test']))->toBeTrue()
        ->and($rule->passes('customer', ['name' => 'Jane Customer', 'email' => 'jane@example.test']))->toBeTrue()
        ->and($rule->message())->toBe('The :attribute is invalid.');
});

test('customer detail validation reports specific shape errors', function () {
    $rule = new CustomerIdOrDetails();

    expect($rule->passes('customer', []))->toBeFalse()
        ->and($rule->message())->toBe('The :attribute must have at least a name and email.')
        ->and($rule->passes('customer', ['email' => 'not-an-email']))->toBeFalse()
        ->and($rule->message())->toBe('The :attribute email must be a valid email address.')
        ->and($rule->passes('customer', ['name' => '   ']))->toBeFalse()
        ->and($rule->message())->toBe('The :attribute name must be a non-empty string.')
        ->and($rule->passes('customer', false))->toBeFalse()
        ->and($rule->message())->toBe('The :attribute must be either a string (customer ID) or an object with name and/or email.');
});

test('computable algorithm rule delegates to algorithm evaluation', function () {
    $rule = new ComputableAlgo();

    expect($rule->passes('algorithm', '{base_fee} + max({distance_km}, 10)'))->toBeTrue()
        ->and($rule->passes('algorithm', '{base_fee} +'))->toBeFalse()
        ->and($rule->message())->toBe('Algorithm provided is not computable.');
});

test('resolvable vehicle extracts supported identifier shapes without hitting the database for empty values', function () {
    $rule   = new ResolvableVehicle();
    $method = new ReflectionMethod($rule, 'extractIdentifier');
    $method->setAccessible(true);

    expect($method->invoke($rule, 'vehicle_123'))->toBe('vehicle_123')
        ->and($method->invoke($rule, ['id' => 'vehicle_id']))->toBe('vehicle_id')
        ->and($method->invoke($rule, ['public_id' => 'vehicle_public']))->toBe('vehicle_public')
        ->and($method->invoke($rule, ['uuid' => 'vehicle_uuid']))->toBe('vehicle_uuid')
        ->and($method->invoke($rule, (object) ['public_id' => 'vehicle_object']))->toBe('vehicle_object')
        ->and($method->invoke($rule, false))->toBeNull()
        ->and($rule->passes('vehicle', null))->toBeTrue()
        ->and($rule->getResolved())->toBeNull()
        ->and($rule->message())->toBe('The :attribute must be a valid vehicle public ID, UUID, or vehicle object.');
});

test('resolvable point accepts point instances and rejects unresolvable values', function () {
    $rule = new ResolvablePoint();

    expect($rule->passes('location', new Point(1.3, 103.8)))->toBeTrue()
        ->and($rule->passes('location', 'not-a-point'))->toBeFalse()
        ->and($rule->message())->toBe('The :attribute must be a valid GeoJSON Point or a type (Place ID) that can be resolved to a Point.');
});

test('order config entity cast serializes arrays for storage', function () {
    $cast = new OrderConfigEntities();
    $data = [
        ['name' => 'Parcel', 'type' => 'parcel'],
        ['name' => 'Pallet', 'type' => 'pallet'],
    ];

    expect($cast->set(new stdClass(), 'entities', $data, []))->toBe(json_encode($data));
});

test('order config entity cast decodes entities and enriches available photos', function () {
    $cast            = new FleetOpsOrderConfigEntitiesCastProbe();
    $cast->photoUrls = [
        'photo-uuid' => 'https://cdn.test/photo.png',
    ];

    $entities = [
        ['name' => 'Parcel', 'photo_uuid' => 'photo-uuid'],
        ['name' => 'Pallet', 'photo_uuid' => 'missing-photo'],
        ['name' => 'Envelope'],
    ];

    expect($cast->get(new stdClass(), 'entities', json_encode($entities), []))->toBe([
        ['name' => 'Parcel', 'photo_uuid' => 'photo-uuid', 'photo_url' => 'https://cdn.test/photo.png'],
        ['name' => 'Pallet', 'photo_uuid' => 'missing-photo'],
        ['name' => 'Envelope'],
    ])
        ->and($cast->get(new stdClass(), 'entities', '"not-an-array"', []))->toBe('not-an-array');
});

test('polygon casts reject unsupported values with helpful field names', function () {
    $model             = new stdClass();
    $model->geometries = [];

    expect(fn () => (new Polygon())->set($model, 'border', 'invalid', []))
        ->toThrow(Exception::class, 'Invalid Polygon provided for border')
        ->and(fn () => (new MultiPolygon())->set($model, 'coverage_area', 'invalid', []))
        ->toThrow(Exception::class, 'Invalid MultiPolygon provided for coverage_area');
});

test('spatial casts preserve valid geometry expressions and geojson payloads', function () {
    $model             = new stdClass();
    $model->geometries = [];

    $polygonGeoJson = [
        'type'        => 'Polygon',
        'coordinates' => [[
            [106.0, 47.0],
            [107.0, 47.0],
            [107.0, 48.0],
            [106.0, 47.0],
        ]],
    ];
    $multiPolygonGeoJson = [
        'type'        => 'MultiPolygon',
        'coordinates' => [[[
            [106.0, 47.0],
            [107.0, 47.0],
            [107.0, 48.0],
            [106.0, 47.0],
        ]]],
    ];
    $pointGeoJson = [
        'type'        => 'Point',
        'coordinates' => [106.9338169, 47.9131423],
    ];

    $polygonCast      = new Polygon();
    $multiPolygonCast = new MultiPolygon();
    $pointCast        = new PointCast();
    $polygon          = SpatialPolygon::fromJson(json_encode($polygonGeoJson));
    $multiPolygon     = SpatialMultiPolygon::fromJson(json_encode($multiPolygonGeoJson));
    $pointExpression  = new SpatialExpression(new Point(47.9131423, 106.9338169));
    $sqlExpression    = new Expression("ST_PointFromText('POINT(106.9338169 47.9131423)')");

    expect($polygonCast->get($model, 'border', 'stored-polygon', []))->toBe('stored-polygon')
        // Polygon wraps in a SpatialExpression like the Point and MultiPolygon
        // casts do; BaseBuilder::cleanBindings() relies on that wrapper to supply
        // the WKT and SRID bindings for ST_GeomFromText(?, ?)
        ->and($polygonCast->set($model, 'border', $polygon, []))->toBeInstanceOf(SpatialExpression::class)
        ->and($polygonCast->set($model, 'border_expression', new SpatialExpression($polygon), []))->toBeInstanceOf(SpatialExpression::class)
        ->and($polygonCast->set($model, 'border_geojson', $polygonGeoJson, []))->toBeInstanceOf(SpatialPolygon::class)
        ->and($multiPolygonCast->get($model, 'coverage', 'stored-multipolygon', []))->toBe('stored-multipolygon')
        ->and($multiPolygonCast->set($model, 'coverage', $multiPolygon, []))->toBeInstanceOf(SpatialExpression::class)
        ->and($multiPolygonCast->set($model, 'coverage_expression', new SpatialExpression($multiPolygon), []))->toBeInstanceOf(SpatialExpression::class)
        ->and($multiPolygonCast->set($model, 'coverage_geojson', $multiPolygonGeoJson, []))->toBeInstanceOf(SpatialMultiPolygon::class)
        ->and($pointCast->get($model, 'location', 'stored-point', []))->toBe('stored-point')
        ->and($pointCast->set($model, 'location_sql', $sqlExpression, []))->toBe($sqlExpression)
        ->and($pointCast->set($model, 'location_geometry', new Point(47.9131423, 106.9338169), []))->toBeInstanceOf(SpatialExpression::class)
        ->and($pointCast->set($model, 'location_geojson', $pointGeoJson, []))->toBeInstanceOf(SpatialExpression::class)
        ->and($pointCast->set($model, 'location_coordinates', '47.9131423,106.9338169', []))->toBeInstanceOf(Point::class)
        ->and($pointCast->set($model, 'location_expression', $pointExpression, []))->toBe($pointExpression)
        ->and($pointCast->set($model, 'location_empty', 'notcoordinates', []))->toBeInstanceOf(SpatialExpression::class);
});

test('spatial cast output expands into wkt and srid query bindings', function () {
    $model             = new stdClass();
    $model->geometries = [];

    $polygon = SpatialPolygon::fromJson(json_encode([
        'type'        => 'Polygon',
        'coordinates' => [[
            [103.85, 1.35],
            [103.95, 1.35],
            [103.95, 1.45],
            [103.85, 1.35],
        ]],
    ]));

    // This is what makes the wrapper matter. Writes bind through BaseBuilder,
    // whose cleanBindings() turns a SpatialExpression into the two bindings that
    // ST_GeomFromText(?, ?) needs. A bare geometry is passed through untouched
    // and PDO cannot bind it — Geometry has no __toString — so an update would
    // fail. Inserts happen to survive either way because performInsert() wraps
    // the attribute itself, which is why an insert test cannot catch this.
    $builder = (new ReflectionClass(Fleetbase\LaravelMysqlSpatial\Eloquent\BaseBuilder::class))->newInstanceWithoutConstructor();

    foreach ([
        'polygon'      => (new Polygon())->set($model, 'border', $polygon, []),
        'multipolygon' => (new MultiPolygon())->set($model, 'coverage', SpatialMultiPolygon::fromJson(json_encode([
            'type'        => 'MultiPolygon',
            'coordinates' => [[[
                [103.85, 1.35],
                [103.95, 1.35],
                [103.95, 1.45],
                [103.85, 1.35],
            ]]],
        ])), []),
        'point' => (new PointCast())->set($model, 'location', new Point(1.35, 103.85), []),
    ] as $label => $castOutput) {
        expect($castOutput)->toBeInstanceOf(SpatialExpression::class);

        $bindings = $builder->cleanBindings([$castOutput]);

        expect($bindings)->toHaveCount(2)
            ->and($bindings[0])->toBeString()
            ->and($bindings[0])->toContain('(')
            ->and($bindings[1])->toBeInt();
    }
});
