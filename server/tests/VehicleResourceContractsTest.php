<?php

use Fleetbase\FleetOps\Http\Resources\v1\Vehicle as VehicleResource;
use Fleetbase\FleetOps\Http\Resources\v1\VehicleWithoutDriver as VehicleWithoutDriverResource;
use Illuminate\Http\Request;

class FleetOpsVehicleResourceRouteFixture
{
    public function __construct(private string $uri)
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }
}

class FleetOpsVehicleResourceFixture implements ArrayAccess
{
    public function __construct(private array $attributes)
    {
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __isset(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->attributes);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->attributes[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[(string) $offset]);
    }

    public function relationLoaded(string $relationship): bool
    {
        return false;
    }

    public function loadMissing(string $relationship): self
    {
        return $this;
    }

    public function getOriginal(string $key): mixed
    {
        return $this->attributes['original'][$key] ?? $this->attributes[$key] ?? null;
    }

    public function withCustomFields(array $payload): array
    {
        return $payload;
    }
}

class TestFleetOpsVehicleResource extends VehicleResource
{
    protected function assignedOrdersCount(): int
    {
        return 3;
    }

    protected function currentOrderReference(): ?string
    {
        return 'order_current';
    }
}

function fleetopsVehicleResourceRequest(bool $internal): Request
{
    $uri     = $internal ? 'api/int/v1/fleet-ops/vehicles/vehicle_123' : 'api/v1/fleet-ops/vehicles/vehicle_123';
    $request = Request::create('/' . $uri, 'GET');

    $request->setRouteResolver(fn () => new FleetOpsVehicleResourceRouteFixture($uri));
    app()->instance('request', $request);

    return $request;
}

function fleetopsVehicleResourceFixture(array $overrides = []): FleetOpsVehicleResourceFixture
{
    return new FleetOpsVehicleResourceFixture(array_merge([
        'id'                                   => 42,
        'uuid'                                 => 'vehicle-uuid',
        'public_id'                            => 'vehicle_public',
        'internal_id'                          => 'VEH-42',
        'company_uuid'                         => 'company-uuid',
        'vendor_uuid'                          => 'vendor-uuid',
        'category_uuid'                        => 'category-uuid',
        'warranty_uuid'                        => 'warranty-uuid',
        'telematic_uuid'                       => 'telematic-uuid',
        'photo_uuid'                           => 'photo-uuid',
        'photo_url'                            => 'https://cdn.test/photo.png',
        'avatar_url'                           => 'https://cdn.test/avatar.png',
        'original'                             => ['avatar_url' => 'avatar-upload-token'],
        'name'                                 => 'Truck 42',
        'display_name'                         => 'Truck 42 Display',
        'description'                          => 'Primary city truck',
        'driver_name'                          => 'Jane Driver',
        'vendor_name'                          => 'Vendor One',
        'make'                                 => 'Ford',
        'model'                                => 'Transit',
        'model_type'                           => 'Cargo',
        'year'                                 => 2025,
        'trim'                                 => 'XL',
        'type'                                 => 'van',
        'class'                                => 'commercial',
        'color'                                => 'white',
        'serial_number'                        => 'SER-42',
        'plate_number'                         => 'ABC-123',
        'call_sign'                            => 'TRUCK-42',
        'fuel_card_number'                     => 'FUEL-42',
        'vin'                                  => 'VIN42',
        'vin_data'                             => ['manufacturer' => 'Ford'],
        'specs'                                => ['doors' => 4],
        'details'                              => ['liftgate' => true],
        'status'                               => 'active',
        'online'                               => true,
        'slug'                                 => 'truck-42',
        'financing_status'                     => 'owned',
        'measurement_system'                   => 'imperial',
        'odometer'                             => 12000,
        'odometer_unit'                        => 'mi',
        'odometer_at_purchase'                 => 100,
        'fuel_type'                            => 'diesel',
        'fuel_volume_unit'                     => 'gal',
        'body_type'                            => 'cargo',
        'body_sub_type'                        => 'high-roof',
        'usage_type'                           => 'delivery',
        'ownership_type'                       => 'owned',
        'transmission'                         => 'automatic',
        'engine_number'                        => 'ENG-42',
        'engine_make'                          => 'Ford',
        'engine_model'                         => 'EcoBlue',
        'engine_family'                        => 'inline',
        'engine_configuration'                 => 'I4',
        'engine_size'                          => '2.0L',
        'engine_displacement'                  => '1995cc',
        'cylinder_arrangement'                 => 'inline',
        'number_of_cylinders'                  => 4,
        'horsepower'                           => 170,
        'horsepower_rpm'                       => 3500,
        'torque'                               => 390,
        'torque_rpm'                           => 1750,
        'fuel_capacity'                        => 25,
        'payload_capacity'                     => 3500,
        'towing_capacity'                      => 5000,
        'seating_capacity'                     => 2,
        'weight'                               => 6200,
        'length'                               => 240,
        'width'                                => 82,
        'height'                               => 110,
        'cargo_volume'                         => 487,
        'passenger_volume'                     => 120,
        'interior_volume'                      => 607,
        'ground_clearance'                     => 6,
        'bed_length'                           => 0,
        'emission_standard'                    => 'Euro 6',
        'dpf_equipped'                         => true,
        'scr_equipped'                         => true,
        'gvwr'                                 => 9500,
        'gcwr'                                 => 12000,
        'estimated_service_life_distance'      => 250000,
        'estimated_service_life_distance_unit' => 'mi',
        'estimated_service_life_months'        => 96,
        'currency'                             => 'USD',
        'acquisition_cost'                     => 45000,
        'current_value'                        => 40000,
        'insurance_value'                      => 42000,
        'depreciation_rate'                    => 0.15,
        'loan_amount'                          => 0,
        'loan_number_of_payments'              => 0,
        'loan_first_payment'                   => null,
        'purchased_at'                         => '2026-01-01',
        'lease_expires_at'                     => null,
        'deleted_at'                           => null,
        'updated_at'                           => '2026-07-01 10:00:00',
        'created_at'                           => '2026-01-01 09:00:00',
        'location'                             => ['type' => 'Point', 'coordinates' => [103.85, 1.29]],
        'heading'                              => 180,
        'altitude'                             => 12,
        'speed'                                => 45,
        'telematics'                           => ['provider' => 'safee'],
        'notes'                                => 'Keep in city route pool.',
        'meta'                                 => ['pool' => 'city'],
    ], $overrides));
}

test('vehicle resource exposes internal identifiers counters and current order metadata', function () {
    $request = fleetopsVehicleResourceRequest(true);
    $payload = (new TestFleetOpsVehicleResource(fleetopsVehicleResourceFixture()))->resolve($request);

    expect($payload)->toMatchArray([
        'id'                      => 42,
        'uuid'                    => 'vehicle-uuid',
        'public_id'               => 'vehicle_public',
        'company_uuid'            => 'company-uuid',
        'photo_uuid'              => 'photo-uuid',
        'avatar_value'            => 'avatar-upload-token',
        'display_name'            => 'Truck 42 Display',
        'driver_name'             => 'Jane Driver',
        'vendor_name'             => 'Vendor One',
        'assigned_orders_count'   => 3,
        'current_order_reference' => 'order_current',
        'plate_number'            => 'ABC-123',
        'vin_data'                => ['manufacturer' => 'Ford'],
        'specs'                   => ['doors' => 4],
        'details'                 => ['liftgate' => true],
        'online'                  => true,
        'heading'                 => 180,
        'altitude'                => 12,
        'speed'                   => 45,
        'telematics'              => ['provider' => 'safee'],
        'meta'                    => ['pool' => 'city'],
    ]);
});

test('vehicle resource keeps public payload focused on public identifiers', function () {
    $request = fleetopsVehicleResourceRequest(false);
    $payload = (new TestFleetOpsVehicleResource(fleetopsVehicleResourceFixture()))->resolve($request);

    expect($payload['id'])->toBe('vehicle_public')
        ->and($payload)->not->toHaveKeys(['uuid', 'public_id', 'company_uuid', 'photo_uuid', 'avatar_value', 'display_name'])
        ->and($payload)->toMatchArray([
            'internal_id'  => 'VEH-42',
            'photo_url'    => 'https://cdn.test/photo.png',
            'avatar_url'   => 'https://cdn.test/avatar.png',
            'name'         => 'Truck 42',
            'plate_number' => 'ABC-123',
            'status'       => 'active',
            'online'       => true,
            'currency'     => 'USD',
            'notes'        => 'Keep in city route pool.',
        ]);
});

test('vehicle resource webhook payload serializes fleet vehicle details', function () {
    $payload = (new TestFleetOpsVehicleResource(fleetopsVehicleResourceFixture()))->toWebhookPayload();

    expect($payload)->toMatchArray([
        'id'                                   => 'vehicle_public',
        'internal_id'                          => 'VEH-42',
        'name'                                 => 'Truck 42',
        'display_name'                         => 'Truck 42 Display',
        'vin'                                  => 'VIN42',
        'plate_number'                         => 'ABC-123',
        'make'                                 => 'Ford',
        'model'                                => 'Transit',
        'status'                               => 'active',
        'online'                               => true,
        'measurement_system'                   => 'imperial',
        'fuel_type'                            => 'diesel',
        'body_type'                            => 'cargo',
        'engine_number'                        => 'ENG-42',
        'fuel_capacity'                        => 25,
        'estimated_service_life_distance'      => 250000,
        'estimated_service_life_distance_unit' => 'mi',
        'currency'                             => 'USD',
        'current_value'                        => 40000,
        'heading'                              => 180,
        'altitude'                             => 12,
        'speed'                                => 45,
        'telematics'                           => ['provider' => 'safee'],
        'meta'                                 => ['pool' => 'city'],
    ]);
});

test('vehicle without driver resource omits driver relationships and serializes webhook payloads', function () {
    $resource = new VehicleWithoutDriverResource(fleetopsVehicleResourceFixture());
    $request  = fleetopsVehicleResourceRequest(true);
    $payload  = $resource->resolve($request);
    $webhook  = $resource->toWebhookPayload();

    expect($payload)->toMatchArray([
        'id'            => 42,
        'public_id'     => 'vehicle_public',
        'driver_name'   => 'Jane Driver',
        'vendor_name'   => 'Vendor One',
        'plate_number'  => 'ABC-123',
        'fuel_capacity' => 25,
        'meta'          => ['pool' => 'city'],
    ])
        ->and($payload)->not->toHaveKeys(['driver', 'assigned_orders_count', 'current_order_reference'])
        ->and($webhook)->toMatchArray([
            'id'           => 'vehicle_public',
            'name'         => 'Truck 42',
            'plate_number' => 'ABC-123',
            'online'       => true,
            'currency'     => 'USD',
            'meta'         => ['pool' => 'city'],
        ])
        ->and($webhook)->not->toHaveKey('driver');
});
