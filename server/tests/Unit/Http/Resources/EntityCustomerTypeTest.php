<?php

use Fleetbase\FleetOps\Http\Resources\v1\Entity as EntityResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Entity;

/**
 * Covers Entity::setCustomerType(), which stamps the ember-facing customer type
 * onto an already-resolved customer payload. An empty payload is passed straight
 * back, since there is nothing to annotate.
 */
test('entity customer payloads are stamped with the ember resource type', function () {
    $entity = new Entity();
    $entity->setRawAttributes([
        'uuid'          => 'entity-customer-type-1',
        'public_id'     => 'entity_customertype1',
        'customer_uuid' => 'contact-customer-type-1',
        'customer_type' => Contact::class,
    ], true);

    $resource = new EntityResource($entity);

    // A resolved customer payload gains the generic type plus the short
    // ember-style class name derived from customer_type
    $stamped = $resource->setCustomerType(['id' => 'contact_customertype1', 'name' => 'Stamped Customer']);

    expect($stamped['type'])->toBe('customer')
        ->and($stamped['name'])->toBe('Stamped Customer')
        ->and($stamped['customer_type'])->not->toBeNull()
        ->and($stamped['customer_type'])->not->toBe(Contact::class);

    // Nothing resolved means nothing to stamp, and the input is returned as-is
    expect($resource->setCustomerType([]))->toBe([])
        ->and($resource->setCustomerType(null))->toBeNull();
});
