<?php

use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Zone;

test('service area create and update accept persisted map geometry and style fields', function () {
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/Api/v1/ServiceAreaController.php');

    expect($controller)
        ->toContain("'border'")
        ->toContain("'color'")
        ->toContain("'stroke_color'");
});

test('service area resource returns map geometry and style fields', function () {
    $resource = file_get_contents(__DIR__ . '/../src/Http/Resources/v1/ServiceArea.php');

    expect($resource)
        ->toContain("'border'")
        ->toContain("'color'")
        ->toContain("'stroke_color'")
        ->toContain("'trigger_on_entry'")
        ->toContain("'trigger_on_exit'");
});

test('zone create accepts only service area public ids on the consumable api', function () {
    $request    = file_get_contents(__DIR__ . '/../src/Http/Requests/CreateZoneRequest.php');
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/Api/v1/ZoneController.php');

    expect($request)
        ->toContain("'service_area'")
        ->not->toContain("'service_area_uuid'");

    expect($controller)
        ->toContain("'public_id'    => \$request->input('service_area')")
        ->not->toContain("->orWhere('uuid'");
});

test('service area zones relationship uses the stored service area uuid foreign key', function () {
    $relation = (new ServiceArea())->zones();

    expect($relation->getForeignKeyName())->toBe('service_area_uuid')
        ->and($relation->getLocalKeyName())->toBe('uuid');
});

test('zone service area relationship uses the stored service area uuid foreign key', function () {
    $relation = (new Zone())->serviceArea();

    expect($relation->getForeignKeyName())->toBe('service_area_uuid')
        ->and($relation->getOwnerKeyName())->toBe('uuid');
});
