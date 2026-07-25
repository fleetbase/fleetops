<?php

use Fleetbase\FleetOps\Casts\MultiPolygon;
use Fleetbase\FleetOps\Casts\OrderConfigEntities;
use Fleetbase\FleetOps\Casts\Polygon;
use Fleetbase\FleetOps\Rules\ComputableAlgo;
use Fleetbase\FleetOps\Rules\CustomerIdOrDetails;
use Fleetbase\FleetOps\Rules\ResolvablePoint;
use Fleetbase\FleetOps\Rules\ResolvableVehicle;
use Fleetbase\LaravelMysqlSpatial\Types\Point;

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

test('polygon casts reject unsupported values with helpful field names', function () {
    $model             = new stdClass();
    $model->geometries = [];

    expect(fn () => (new Polygon())->set($model, 'border', 'invalid', []))
        ->toThrow(Exception::class, 'Invalid Polygon provided for border')
        ->and(fn () => (new MultiPolygon())->set($model, 'coverage_area', 'invalid', []))
        ->toThrow(Exception::class, 'Invalid MultiPolygon provided for coverage_area');
});
