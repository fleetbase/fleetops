<?php

use Fleetbase\FleetOps\Http\Resources\v1\Entity as EntityResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Order as IndexOrderResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Vehicle as IndexVehicleResource;
use Fleetbase\FleetOps\Http\Resources\v1\ServiceRate as ServiceRateResource;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FleetOpsCompactResourceRouteFixture
{
    public function __construct(private string $uri)
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }
}

class FleetOpsCompactResourceFixture implements ArrayAccess
{
    public bool $wasRecentlyCreated = false;

    public function __construct(private array $attributes, private array $loaded = [])
    {
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? $this->loaded[$key] ?? null;
    }

    public function __isset(string $key): bool
    {
        return array_key_exists($key, $this->attributes) || array_key_exists($key, $this->loaded);
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
        return array_key_exists($relationship, $this->loaded);
    }

    public function loadMissing(string $relationship): self
    {
        return $this;
    }

    public function getRelation(string $relationship): mixed
    {
        return $this->loaded[$relationship] ?? null;
    }

    public function setRelation(string $relationship, mixed $value): self
    {
        $this->loaded[$relationship] = $value;

        return $this;
    }

    public function withCustomFields(array $payload): array
    {
        return $payload;
    }
}

class TestFleetOpsIndexVehicleResource extends IndexVehicleResource
{
    protected function assignedOrdersCount(): int
    {
        return 6;
    }

    protected function currentOrderReference(): ?string
    {
        return 'TRK-INDEX';
    }

    protected function speedLabel(): string
    {
        return '54 km/h';
    }

    protected function headingLabel(): string
    {
        return '270 deg';
    }
}

function fleetopsCompactResourceRequest(bool $internal): Request
{
    $uri     = $internal ? 'api/int/v1/fleet-ops/resources/resource_123' : 'api/v1/fleet-ops/resources/resource_123';
    $request = Request::create('/' . $uri, 'GET');

    $request->setRouteResolver(fn () => new FleetOpsCompactResourceRouteFixture($uri));
    app()->instance('request', $request);

    return $request;
}

function fleetopsCompactResourceFixture(array $attributes = [], array $loaded = []): FleetOpsCompactResourceFixture
{
    return new FleetOpsCompactResourceFixture(array_merge([
        'id'         => 101,
        'uuid'       => 'fixture-uuid',
        'public_id'  => 'fixture_public',
        'updated_at' => '2026-07-02 10:00:00',
        'created_at' => '2026-01-02 09:00:00',
    ], $attributes), $loaded);
}

test('service rate resource serializes pricing rules for internal and webhook consumers', function () {
    $request = fleetopsCompactResourceRequest(true);
    $rate    = fleetopsCompactResourceFixture([
        'service_area_uuid'             => 'area-uuid',
        'zone_uuid'                     => 'zone-uuid',
        'order_config_uuid'             => 'config-uuid',
        'orderConfig'                   => (object) ['public_id' => 'config_public'],
        'serviceArea'                   => (object) ['name' => 'Central'],
        'zone'                          => (object) ['name' => 'Downtown'],
        'service_name'                  => 'Same Day',
        'service_type'                  => 'delivery',
        'base_fee'                      => 12.5,
        'rate_calculation_method'       => 'flat',
        'per_meter_flat_rate_fee'       => 0.02,
        'per_meter_unit'                => 1000,
        'per_km_flat_rate_fee'          => 2.5,
        'max_distance_unit'             => 'km',
        'max_distance'                  => 50,
        'rateFees'                      => [],
        'parcelFees'                    => [],
        'algorithm'                     => 'distance',
        'has_cod_fee'                   => 1,
        'cod_calculation_method'        => 'flat',
        'cod_flat_fee'                  => 3,
        'cod_percent'                   => 0,
        'has_peak_hours_fee'            => 'true',
        'peak_hours_calculation_method' => 'percent',
        'peak_hours_flat_fee'           => 0,
        'peak_hours_percent'            => 15,
        'peak_hours_start'              => '17:00',
        'peak_hours_end'                => '20:00',
        'currency'                      => 'SGD',
        'duration_terms'                => 'same_day',
        'estimated_days'                => 1,
    ]);

    $payload = (new ServiceRateResource($rate))->resolve($request);
    $webhook = (new ServiceRateResource($rate))->toWebhookPayload();

    expect($payload)->toMatchArray([
        'id'                            => 101,
        'uuid'                          => 'fixture-uuid',
        'service_area_uuid'             => 'area-uuid',
        'zone_uuid'                     => 'zone-uuid',
        'order_config_uuid'             => 'config-uuid',
        'public_id'                     => 'fixture_public',
        'service_area_name'             => 'Central',
        'zone_name'                     => 'Downtown',
        'service_name'                  => 'Same Day',
        'service_type'                  => 'delivery',
        'base_fee'                      => 12.5,
        'rate_calculation_method'       => 'flat',
        'per_meter_flat_rate_fee'       => 0.02,
        'per_meter_unit'                => 1000,
        'max_distance_unit'             => 'km',
        'max_distance'                  => 50,
        'algorithm'                     => 'distance',
        'has_cod_fee'                   => true,
        'cod_calculation_method'        => 'flat',
        'cod_flat_fee'                  => 3,
        'has_peak_hours_fee'            => true,
        'peak_hours_calculation_method' => 'percent',
        'peak_hours_percent'            => 15,
        'currency'                      => 'SGD',
        'duration_terms'                => 'same_day',
        'estimated_days'                => 1,
    ])
        ->and($webhook)->toMatchArray([
            'id'                            => 'fixture_public',
            'service_name'                  => 'Same Day',
            'service_type'                  => 'delivery',
            'base_fee'                      => 12.5,
            'per_km_flat_rate_fee'          => 2.5,
            'has_cod_fee'                   => true,
            'has_peak_hours_fee'            => true,
            'peak_hours_start'              => '17:00',
            'peak_hours_end'                => '20:00',
            'currency'                      => 'SGD',
            'estimated_days'                => 1,
        ]);
});

test('entity resource exposes package details and webhook payloads', function () {
    $request = fleetopsCompactResourceRequest(true);
    $entity  = fleetopsCompactResourceFixture([
        'photo_uuid'           => 'photo-uuid',
        'customer_uuid'        => null,
        'customer_type'        => null,
        'supplier_uuid'        => 'supplier-uuid',
        'destination_uuid'     => 'place-uuid',
        'payload_uuid'         => 'payload-uuid',
        'internal_id'          => 'ENT-101',
        'name'                 => 'Parcel 101',
        'type'                 => 'parcel',
        'trackingNumber'       => (object) [
            'tracking_number' => 'TN-101',
            'barcode'         => 'barcode-data',
            'qr_code'         => 'qr-data',
        ],
        'description'          => 'Fragile parcel',
        'photo_url'            => 'https://cdn.test/entity.png',
        'length'               => 10,
        'width'                => 8,
        'height'               => 6,
        'dimensions_unit'      => 'cm',
        'weight'               => 2.4,
        'weight_unit'          => 'kg',
        'declared_value'       => 75,
        'price'                => 80,
        'sale_price'           => 70,
        'sku'                  => 'SKU-101',
        'currency'             => 'SGD',
        'meta'                 => ['fragile' => true],
        'destination'          => (object) ['public_id' => 'place_public'],
    ]);

    $payload = (new EntityResource($entity))->resolve($request);
    $webhook = (new EntityResource($entity))->toWebhookPayload();

    expect($payload)->toMatchArray([
        'id'               => 101,
        'uuid'             => 'fixture-uuid',
        'photo_uuid'       => 'photo-uuid',
        'public_id'        => 'fixture_public',
        'supplier_uuid'    => 'supplier-uuid',
        'destination_uuid' => 'place-uuid',
        'payload_uuid'     => 'payload-uuid',
        'internal_id'      => 'ENT-101',
        'name'             => 'Parcel 101',
        'type'             => 'parcel',
        'description'      => 'Fragile parcel',
        'photo_url'        => 'https://cdn.test/entity.png',
        'dimensions_unit'  => 'cm',
        'weight_unit'      => 'kg',
        'currency'         => 'SGD',
        'meta'             => ['fragile' => true],
    ])
        ->and($webhook)->toMatchArray([
            'id'              => 'fixture_public',
            'internal_id'     => 'ENT-101',
            'name'            => 'Parcel 101',
            'type'            => 'parcel',
            'destination'     => 'place_public',
            'description'     => 'Fragile parcel',
            'photo_url'       => 'https://cdn.test/entity.png',
            'length'          => 10,
            'width'           => 8,
            'height'          => 6,
            'declared_value'  => 75,
            'sale_price'      => 70,
            'sku'             => 'SKU-101',
            'currency'        => 'SGD',
            'meta'            => ['fragile' => true],
        ]);
});

test('index vehicle resource returns compact fleet state metadata', function () {
    $request = fleetopsCompactResourceRequest(true);
    $vehicle = fleetopsCompactResourceFixture([
        'company_uuid'     => 'company-uuid',
        'vendor_uuid'      => 'vendor-uuid',
        'photo_uuid'       => 'photo-uuid',
        'internal_id'      => 'VEH-INDEX',
        'display_name'     => 'Index Truck',
        'driver_name'      => 'Jane Driver',
        'plate_number'     => 'IDX-101',
        'serial_number'    => 'SER-101',
        'fuel_card_number' => 'FUEL-101',
        'vin'              => 'VIN-101',
        'make'             => 'Ford',
        'model'            => 'Transit',
        'year'             => 2026,
        'photo_url'        => 'https://cdn.test/index-truck.png',
        'status'           => 'in_service',
        'location'         => null,
        'heading'          => 270,
        'altitude'         => 20,
        'speed'            => 54,
        'online'           => 1,
    ]);

    $payload = (new TestFleetOpsIndexVehicleResource($vehicle))->resolve($request);

    expect($payload)->toMatchArray([
        'id'                    => 101,
        'uuid'                  => 'fixture-uuid',
        'public_id'             => 'fixture_public',
        'company_uuid'          => 'company-uuid',
        'vendor_uuid'           => 'vendor-uuid',
        'photo_uuid'            => 'photo-uuid',
        'internal_id'           => 'VEH-INDEX',
        'display_name'          => 'Index Truck',
        'driver_name'           => 'Jane Driver',
        'plate_number'          => 'IDX-101',
        'status'                => 'in_service',
        'heading'               => 270,
        'altitude'              => 20,
        'speed'                 => 54,
        'online'                => true,
        'assigned_orders_count' => 6,
        'meta'                  => [
            '_index_resource'         => true,
            'current_order_reference' => 'TRK-INDEX',
            'location_coordinates'    => '0 0',
            'speed_label'             => '54 km/h',
            'heading_label'           => '270 deg',
            'status_label'            => 'In Service',
        ],
    ]);
});

test('index order resource returns compact order table payloads', function () {
    $request = fleetopsCompactResourceRequest(true);
    $order   = fleetopsCompactResourceFixture([
        'internal_id'            => 'ORD-INDEX',
        'company_uuid'           => 'company-uuid',
        'payload_uuid'           => 'payload-uuid',
        'driver_assigned_uuid'   => 'driver-uuid',
        'vehicle_assigned_uuid'  => 'vehicle-uuid',
        'customer_uuid'          => 'customer-uuid',
        'customer_type'          => 'Fleetbase\\Models\\Contact',
        'facilitator_uuid'       => 'facilitator-uuid',
        'facilitator_type'       => 'Fleetbase\\Models\\Vendor',
        'tracking_number_uuid'   => 'tracking-uuid',
        'order_config_uuid'      => 'config-uuid',
        'trackingNumber'         => (object) ['tracking_number' => 'TN-INDEX'],
        'type'                   => 'transport',
        'status'                 => 'created',
        'adhoc'                  => 1,
        'dispatched'             => 0,
        'has_driver_assigned'    => true,
        'is_scheduled'           => false,
        'transaction_amount'     => 99.95,
        'transaction_currency'   => 'SGD',
        'scheduled_at'           => '2026-07-27 09:00:00',
        'dispatched_at'          => null,
        'started_at'             => null,
    ], [
        'orderConfig' => fleetopsCompactResourceFixture([
            'uuid'      => 'config-uuid',
            'public_id' => 'config_public',
            'name'      => 'Delivery',
            'key'       => 'delivery',
        ]),
        'trackingStatuses' => new Collection([
            (object) ['status' => 'Created', 'code' => 'created'],
        ]),
    ]);

    $payload = (new IndexOrderResource($order))->resolve($request);

    expect($payload)->toMatchArray([
        'id'                    => 101,
        'uuid'                  => 'fixture-uuid',
        'public_id'             => 'fixture_public',
        'internal_id'           => 'ORD-INDEX',
        'company_uuid'          => 'company-uuid',
        'payload_uuid'          => 'payload-uuid',
        'driver_assigned_uuid'  => 'driver-uuid',
        'vehicle_assigned_uuid' => 'vehicle-uuid',
        'customer_uuid'         => 'customer-uuid',
        'tracking_number_uuid'  => 'tracking-uuid',
        'order_config_uuid'     => 'config-uuid',
        'tracking'              => 'TN-INDEX',
        'order_config'          => [
            'uuid'      => 'config-uuid',
            'public_id' => 'config_public',
            'name'      => 'Delivery',
            'key'       => 'delivery',
        ],
        'latest_status'         => 'Created',
        'latest_status_code'    => 'created',
        'type'                  => 'transport',
        'status'                => 'created',
        'adhoc'                 => true,
        'dispatched'            => false,
        'has_driver_assigned'   => true,
        'is_scheduled'          => false,
        'transaction_amount'    => 99.95,
        'currency'              => 'SGD',
        'scheduled_at'          => '2026-07-27 09:00:00',
        'meta'                  => ['_index_resource' => true],
    ]);
});
