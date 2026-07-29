<?php

/**
 * Covers the relation-resolution helpers shared by the v1 resources: loaded
 * relations collapse to a public id for public API requests and resolve to a
 * nested resource internally, while morph transformers fall back to a generic
 * JSON resource when no dedicated resource class is registered.
 */
class FleetOpsResourceRelationModel extends Illuminate\Database\Eloquent\Model
{
    protected $table    = 'resource_relation_models';
    protected $guarded  = [];
    public $exists      = true;
    public $timestamps  = false;
}

test('loaded relations collapse to public ids outside internal requests', function (string $resourceClass) {
    $resource   = new $resourceClass(null);
    $reflection = new ReflectionMethod($resourceClass, 'resolveLoadedRelation');
    $reflection->setAccessible(true);

    // Absent relations resolve to nothing
    expect($reflection->invoke($resource, null))->toBeNull();

    // Public API requests expose the related record by its public id
    $related = new FleetOpsResourceRelationModel(['uuid' => 'related-1', 'public_id' => 'related_public1']);
    expect($reflection->invoke($resource, $related))->toBe('related_public1');
})->with([
    'device'           => [Fleetbase\FleetOps\Http\Resources\v1\Device::class],
    'equipment'        => [Fleetbase\FleetOps\Http\Resources\v1\Equipment::class],
    'fuel transaction' => [Fleetbase\FleetOps\Http\Resources\v1\FuelTransaction::class],
    'part'             => [Fleetbase\FleetOps\Http\Resources\v1\Part::class],
    'sensor'           => [Fleetbase\FleetOps\Http\Resources\v1\Sensor::class],
]);

test('morph transformers serialize subjects without a dedicated resource', function (string $resourceClass) {
    // JsonResource::resolve() reads the current request when serializing
    app()->instance('request', Illuminate\Http\Request::create('/v1/resource', 'GET'));

    $resource   = new $resourceClass(null);
    $reflection = new ReflectionMethod($resourceClass, 'transformMorphResource');
    $reflection->setAccessible(true);

    // Find::httpResourceForModel() always resolves a class, falling back to
    // FleetbaseResource, so unknown subjects still serialize
    $subject  = new FleetOpsResourceRelationModel(['uuid' => 'subject-1', 'public_id' => 'subject_public1']);
    $resolved = $reflection->invoke($resource, $subject);

    expect($resolved)->toBeArray()
        ->and($resolved['public_id'] ?? null)->toBe('subject_public1');
})->with([
    'maintenance'          => [Fleetbase\FleetOps\Http\Resources\v1\Maintenance::class],
    'maintenance schedule' => [Fleetbase\FleetOps\Http\Resources\v1\MaintenanceSchedule::class],
    'work order'           => [Fleetbase\FleetOps\Http\Resources\v1\WorkOrder::class],
]);
