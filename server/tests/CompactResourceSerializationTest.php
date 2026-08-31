<?php

use Fleetbase\FleetOps\Http\Resources\Internal\v1\IntegratedVendorFacilitator as InternalIntegratedVendorFacilitatorResource;
use Fleetbase\FleetOps\Http\Resources\Internal\v1\OrderConfig as InternalOrderConfigResource;
use Fleetbase\FleetOps\Http\Resources\Internal\v1\Vehicle as InternalVehicleResource;
use Fleetbase\FleetOps\Http\Resources\Internal\v1\Waypoint as InternalWaypointResource;
use Fleetbase\FleetOps\Http\Resources\v1\Contact as ContactResource;
use Fleetbase\FleetOps\Http\Resources\v1\CurrentJob as CurrentJobResource;
use Fleetbase\FleetOps\Http\Resources\v1\Customer as CustomerResource;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Device as DeviceResource;
use Fleetbase\FleetOps\Http\Resources\v1\Entity as EntityResource;
use Fleetbase\FleetOps\Http\Resources\v1\Fleet as FleetResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Customer as IndexCustomerResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Driver as IndexDriverResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Facilitator as IndexFacilitatorResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Order as IndexOrderResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Payload as IndexPayloadResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Place as IndexPlaceResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\TrackingNumber as IndexTrackingNumberResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Vehicle as IndexVehicleResource;
use Fleetbase\FleetOps\Http\Resources\v1\Issue as IssueResource;
use Fleetbase\FleetOps\Http\Resources\v1\Maintenance as MaintenanceResource;
use Fleetbase\FleetOps\Http\Resources\v1\MaintenanceSchedule as MaintenanceScheduleResource;
use Fleetbase\FleetOps\Http\Resources\v1\Orchestrator\Order as OrchestratorOrderResource;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Http\Resources\v1\OrderConfig as OrderConfigResource;
use Fleetbase\FleetOps\Http\Resources\v1\ParentFleet as ParentFleetResource;
use Fleetbase\FleetOps\Http\Resources\v1\Payload as PayloadResource;
use Fleetbase\FleetOps\Http\Resources\v1\Place as PlaceResource;
use Fleetbase\FleetOps\Http\Resources\v1\Proof as ProofResource;
use Fleetbase\FleetOps\Http\Resources\v1\PurchaseRate as PurchaseRateResource;
use Fleetbase\FleetOps\Http\Resources\v1\Sensor as SensorResource;
use Fleetbase\FleetOps\Http\Resources\v1\ServiceArea as ServiceAreaResource;
use Fleetbase\FleetOps\Http\Resources\v1\ServiceQuote as ServiceQuoteResource;
use Fleetbase\FleetOps\Http\Resources\v1\ServiceQuoteItem as ServiceQuoteItemResource;
use Fleetbase\FleetOps\Http\Resources\v1\ServiceRate as ServiceRateResource;
use Fleetbase\FleetOps\Http\Resources\v1\SubFleet as SubFleetResource;
use Fleetbase\FleetOps\Http\Resources\v1\TrackingNumber as TrackingNumberResource;
use Fleetbase\FleetOps\Http\Resources\v1\TrackingStatus as TrackingStatusResource;
use Fleetbase\FleetOps\Http\Resources\v1\VehicleWithoutDriver as VehicleWithoutDriverResource;
use Fleetbase\FleetOps\Http\Resources\v1\Vendor as VendorResource;
use Fleetbase\FleetOps\Http\Resources\v1\Waypoint as WaypointResource;
use Fleetbase\FleetOps\Http\Resources\v1\WorkOrder as WorkOrderResource;
use Fleetbase\FleetOps\Http\Resources\v1\Zone as ZoneResource;
use Fleetbase\FleetOps\Models\Contact as ContactModel;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

class FleetOpsCompactResourceRequest extends Request
{
    public function isArray(string $key): bool
    {
        return is_array($this->input($key));
    }

    public function array(string $key): array
    {
        $value = $this->input($key);

        return is_array($value) ? $value : [];
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

    public function loadMissing(string|array $relationship): self
    {
        return $this;
    }

    public function load(string|array $relationship): self
    {
        return $this;
    }

    public function getOriginal(string $key): mixed
    {
        return $this->attributes['original'][$key] ?? $this->attributes[$key] ?? null;
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

    public function __call(string $method, array $arguments): mixed
    {
        if (array_key_exists($method, $this->loaded) && $this->loaded[$method] instanceof Collection) {
            return new class($this->loaded[$method]) {
                public function __construct(private Collection $collection)
                {
                }

                public function count(): int
                {
                    return $this->collection->count();
                }
            };
        }

        if (array_key_exists($method, $this->loaded)) {
            return $this->loaded[$method];
        }

        if ($method === 'getAdhocDistance') {
            return $this->attributes['adhoc_distance'] ?? 0;
        }

        if (array_key_exists($method, $this->attributes)) {
            return $this->attributes[$method];
        }

        return null;
    }
}

class FleetOpsCompactOrderTrackerFixture
{
    public function toArray(): array
    {
        return ['state' => 'enroute', 'distance' => 4200];
    }

    public function eta(): array
    {
        return ['seconds' => 540, 'formatted' => '9 minutes'];
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

class TestFleetOpsIndexDriverResource extends IndexDriverResource
{
    protected function assignedOrdersCount(): int
    {
        return 5;
    }

    protected function currentOrderReference(): ?string
    {
        return 'TRK-DRIVER';
    }
}

class TestFleetOpsCustomerResource extends CustomerResource
{
    protected function getOrdersCount(Request $request): int
    {
        return $request->boolean('has_orders') ? 7 : 0;
    }

    protected function buildCompanyPayload(): ?array
    {
        return [
            'id'       => 'company_public',
            'name'     => 'Acme Logistics',
            'currency' => 'USD',
            'country'  => 'US',
            'phone'    => '+15550000000',
        ];
    }
}

function fleetopsCompactResourceRequest(bool $internal): Request
{
    $uri     = $internal ? 'api/int/v1/fleet-ops/resources/resource_123' : 'api/v1/fleet-ops/resources/resource_123';
    $request = FleetOpsCompactResourceRequest::create('/' . $uri, 'GET');

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

test('customer resource serializes public and internal customer payloads', function () {
    $place = fleetopsCompactResourceFixture([
        'public_id' => 'place_public',
        'address'   => '123 Harbor Way',
    ]);
    $addresses = collect([
        fleetopsCompactResourceFixture([
            'public_id' => 'place_home',
            'address'   => '123 Harbor Way',
        ]),
    ]);
    $fixture = fleetopsCompactResourceFixture([
        'id'           => 55,
        'uuid'         => 'customer-uuid',
        'user_uuid'    => 'user-uuid',
        'company_uuid' => 'company-uuid',
        'public_id'    => 'contact_public',
        'internal_id'  => 'CUST-55',
        'name'         => 'Jane Customer',
        'title'        => 'Operations Lead',
        'photo_url'    => 'https://cdn.test/customer.png',
        'email'        => 'jane@example.test',
        'phone'        => '+15551234567',
        'token'        => 'customer-token',
        'meta'         => ['tier' => 'gold'],
        'slug'         => 'jane-customer',
        'created_at'   => '2026-01-01 10:00:00',
        'updated_at'   => '2026-07-01 10:00:00',
    ], [
        'place'  => $place,
        'places' => $addresses,
    ]);

    $publicRequest = fleetopsCompactResourceRequest(false);
    $publicRequest->merge(['has_orders' => true]);

    $publicPayload   = (new TestFleetOpsCustomerResource($fixture))->resolve($publicRequest);
    $internalPayload = (new TestFleetOpsCustomerResource($fixture))->resolve(fleetopsCompactResourceRequest(true));

    expect($publicPayload)->toMatchArray([
        'id'           => 'customer_public',
        'internal_id'  => 'CUST-55',
        'name'         => 'Jane Customer',
        'title'        => 'Operations Lead',
        'photo_url'    => 'https://cdn.test/customer.png',
        'email'        => 'jane@example.test',
        'phone'        => '+15551234567',
        'address'      => '123 Harbor Way',
        'token'        => 'customer-token',
        'orders_count' => 7,
        'company'      => [
            'id'       => 'company_public',
            'name'     => 'Acme Logistics',
            'currency' => 'USD',
            'country'  => 'US',
            'phone'    => '+15550000000',
        ],
        'meta'         => ['tier' => 'gold'],
        'slug'         => 'jane-customer',
    ])
        ->and($publicPayload)->not->toHaveKeys(['uuid', 'user_uuid', 'company_uuid', 'public_id'])
        ->and($publicPayload['addresses'])->toHaveCount(1)
        ->and($internalPayload)->toMatchArray([
            'id'           => 55,
            'uuid'         => 'customer-uuid',
            'user_uuid'    => 'user-uuid',
            'company_uuid' => 'company-uuid',
            'public_id'    => 'contact_public',
            'orders_count' => 0,
        ]);
});

test('service rate resource serializes pricing rules for internal and webhook consumers', function () {
    $request = fleetopsCompactResourceRequest(true);
    $rate    = fleetopsCompactResourceFixture([
        'service_area_uuid'             => 'area-uuid',
        'zone_uuid'                     => 'zone-uuid',
        'order_config_uuid'             => 'config-uuid',
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
    ], [
        // Loaded rather than a plain attribute: internal consumers get the full
        // order config through whenLoaded, and only the public id otherwise.
        'orderConfig' => (object) ['public_id' => 'config_public'],
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
        ->and($payload['order_config']->public_id)->toBe('config_public')
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

test('order resource serializes internal order identifiers tracker data and timing state', function () {
    $request = fleetopsCompactResourceRequest(true);
    $request->query->set('with_tracker_data', '1');
    $request->query->set('with_eta', '1');

    $trackingNumber = fleetopsCompactResourceFixture([
        'tracking_number' => 'TN-ORDER',
        'barcode'         => 'barcode-order',
        'qr_code'         => 'qr-order',
    ]);
    $order          = fleetopsCompactResourceFixture([
        'internal_id'             => 'ORD-RESOURCE',
        'company_uuid'            => 'company-uuid',
        'transaction_uuid'        => 'transaction-uuid',
        'customer_uuid'           => 'customer-uuid',
        'customer_type'           => ContactModel::class,
        'facilitator_uuid'        => 'facilitator-uuid',
        'facilitator_type'        => 'Fleetbase\\FleetOps\\Models\\Vendor',
        'payload_uuid'            => 'payload-uuid',
        'route_uuid'              => 'route-uuid',
        'purchase_rate_uuid'      => 'purchase-rate-uuid',
        'tracking_number_uuid'    => 'tracking-number-uuid',
        'driver_assigned_uuid'    => 'driver-uuid',
        'vehicle_assigned_uuid'   => 'vehicle-uuid',
        'has_driver_assigned'     => true,
        'is_scheduled'            => true,
        'order_config_uuid'       => 'order-config-uuid',
        'orderConfig'             => fleetopsCompactResourceFixture(['public_id' => 'order_config_public']),
        'payload'                 => null,
        'trackingNumber'          => $trackingNumber,
        'comments'                => collect(),
        'files'                   => collect(),
        'purchaseRate'            => null,
        'notes'                   => 'Internal resource order.',
        'type'                    => 'transport',
        'status'                  => 'dispatched',
        'pod_method'              => 'qr_scan',
        'pod_required'            => 1,
        'dispatched'              => 1,
        'started'                 => 0,
        'adhoc'                   => 1,
        'adhoc_distance'          => 1234,
        'distance'                => 9876,
        'time'                    => 654,
        'transaction_amount'      => 199.95,
        'transaction_currency'    => 'SGD',
        'tracker'                 => new FleetOpsCompactOrderTrackerFixture(),
        'meta'                    => ['priority' => 'high'],
        'dispatched_at'           => '2026-07-26 10:00:00',
        'started_at'              => null,
        'scheduled_at'            => '2026-07-27 09:00:00',
    ], [
        'orderConfig' => fleetopsCompactResourceFixture(['public_id' => 'order_config_public']),
    ]);

    $payload = (new OrderResource($order))->resolve($request);

    expect($payload)->toMatchArray([
        'id'                    => 101,
        'uuid'                  => 'fixture-uuid',
        'public_id'             => 'fixture_public',
        'internal_id'           => 'ORD-RESOURCE',
        'company_uuid'          => 'company-uuid',
        'transaction_uuid'      => 'transaction-uuid',
        'customer_uuid'         => 'customer-uuid',
        'facilitator_uuid'      => 'facilitator-uuid',
        'payload_uuid'          => 'payload-uuid',
        'route_uuid'            => 'route-uuid',
        'purchase_rate_uuid'    => 'purchase-rate-uuid',
        'tracking_number_uuid'  => 'tracking-number-uuid',
        'driver_assigned_uuid'  => 'driver-uuid',
        'vehicle_assigned_uuid' => 'vehicle-uuid',
        'has_driver_assigned'   => true,
        'is_scheduled'          => true,
        'order_config_uuid'     => 'order-config-uuid',
        'tracking'              => 'TN-ORDER',
        'barcode'               => 'barcode-order',
        'qr_code'               => 'qr-order',
        'notes'                 => 'Internal resource order.',
        'type'                  => 'transport',
        'status'                => 'dispatched',
        'pod_method'            => 'qr_scan',
        'pod_required'          => true,
        'dispatched'            => true,
        'started'               => false,
        'adhoc'                 => true,
        'adhoc_distance'        => 1234,
        'distance'              => 9876,
        'time'                  => 654,
        'transaction_amount'    => 199.95,
        'currency'              => 'SGD',
        'tracker_data'          => ['state' => 'enroute', 'distance' => 4200],
        'eta'                   => ['seconds' => 540, 'formatted' => '9 minutes'],
        'meta'                  => ['priority' => 'high'],
        'dispatched_at'         => '2026-07-26 10:00:00',
        'started_at'            => null,
        'scheduled_at'          => '2026-07-27 09:00:00',
    ]);
});

test('order resource keeps public payload on public identifiers and applies customer facilitator type labels', function () {
    $request = fleetopsCompactResourceRequest(false);
    $order   = fleetopsCompactResourceFixture([
        'internal_id'          => 'ORD-PUBLIC',
        'customer_type'        => ContactModel::class,
        'facilitator_type'     => 'Fleetbase\\FleetOps\\Models\\Vendor',
        'orderConfig'          => fleetopsCompactResourceFixture(['public_id' => 'config_public']),
        'payload'              => null,
        'trackingNumber'       => null,
        'trackingStatuses'     => collect(),
        'comments'             => collect(),
        'files'                => collect(),
        'purchaseRate'         => null,
        'notes'                => 'Public resource order.',
        'type'                 => 'delivery',
        'status'               => 'created',
        'pod_required'         => false,
        'dispatched'           => false,
        'started'              => false,
        'adhoc'                => false,
        'distance'             => 0,
        'time'                 => 0,
        'transaction_amount'   => null,
        'transaction_currency' => null,
        'meta'                 => ['channel' => 'public-api'],
    ]);
    $resource = new OrderResource($order);
    $payload  = $resource->resolve($request);

    expect($payload['id'])->toBe('fixture_public')
        ->and($payload)->not->toHaveKeys(['uuid', 'public_id', 'company_uuid', 'transaction_uuid', 'tracking', 'barcode', 'qr_code'])
        ->and($payload)->toMatchArray([
            'internal_id'   => 'ORD-PUBLIC',
            'order_config'  => 'config_public',
            'notes'         => 'Public resource order.',
            'type'          => 'delivery',
            'status'        => 'created',
            'pod_required'  => false,
            'dispatched'    => false,
            'started'       => false,
            'adhoc'         => false,
            'meta'          => ['channel' => 'public-api'],
        ])
        ->and($resource->setCustomerType(['id' => 'customer_public']))->toMatchArray([
            'id'            => 'customer_public',
            'type'          => 'customer-contact',
            'customer_type' => 'customer-contact',
        ])
        ->and($resource->setFacilitatorType(['id' => 'vendor_public']))->toMatchArray([
            'id'               => 'vendor_public',
            'type'             => 'facilitator-vendor',
            'facilitator_type' => 'facilitator-vendor',
        ])
        ->and($resource->setCustomerType([]))->toBe([])
        ->and($resource->setFacilitatorType(null))->toBeNull();
});

test('issue resource serializes internal issue details and webhook identifiers', function () {
    $request = fleetopsCompactResourceRequest(true);
    $issue   = fleetopsCompactResourceFixture([
        'driver_uuid'      => 'driver-uuid',
        'company_uuid'     => 'company-uuid',
        'vehicle_uuid'     => 'vehicle-uuid',
        'order_uuid'       => null,
        'assigned_to_uuid' => 'assignee-uuid',
        'reported_by_uuid' => 'reporter-uuid',
        'driver_name'      => 'Jane Driver',
        'vehicle_name'     => 'Truck 101',
        'vehicle_id'       => 'VEH-101',
        'assignee_name'    => 'Dispatcher',
        'assignee_id'      => 'USR-101',
        'reporter_name'    => 'Operator',
        'reporter_id'      => 'USR-102',
        'issue_id'         => 'ISS-101',
        'title'            => 'Door latch',
        'report'           => 'Rear door latch sticks.',
        'priority'         => 'high',
        'meta'             => ['source' => 'inspection'],
        'type'             => 'vehicle',
        'category'         => 'body',
        'tags'             => ['safety'],
        'status'           => 'open',
        'location'         => null,
        'resolved_at'      => null,
        'reportedBy'       => (object) ['public_id' => 'reporter_public'],
        'assignedTo'       => (object) ['public_id' => 'assignee_public'],
        'driver'           => (object) ['public_id' => 'driver_public'],
        'vehicle'          => (object) ['public_id' => 'vehicle_public'],
    ]);

    $payload = (new IssueResource($issue))->resolve($request);
    $webhook = (new IssueResource($issue))->toWebhookPayload();

    expect($payload)->toMatchArray([
        'id'               => 101,
        'uuid'             => 'fixture-uuid',
        'public_id'        => 'fixture_public',
        'driver_uuid'      => 'driver-uuid',
        'company_uuid'     => 'company-uuid',
        'vehicle_uuid'     => 'vehicle-uuid',
        'assigned_to_uuid' => 'assignee-uuid',
        'reported_by_uuid' => 'reporter-uuid',
        'driver_name'      => 'Jane Driver',
        'vehicle_name'     => 'Truck 101',
        'issue_id'         => 'ISS-101',
        'title'            => 'Door latch',
        'report'           => 'Rear door latch sticks.',
        'priority'         => 'high',
        'meta'             => ['source' => 'inspection'],
        'type'             => 'vehicle',
        'category'         => 'body',
        'tags'             => ['safety'],
        'status'           => 'open',
    ])
        ->and($webhook)->toMatchArray([
            'id'       => 'fixture_public',
            'reporter' => 'reporter_public',
            'assignee' => 'assignee_public',
            'driver'   => 'driver_public',
            'vehicle'  => 'vehicle_public',
            'issue_id' => 'ISS-101',
            'priority' => 'high',
            'category' => 'body',
            'status'   => 'open',
        ]);
});

test('maintenance resource serializes work order costs and schedule state', function () {
    $request     = fleetopsCompactResourceRequest(true);
    $maintenance = fleetopsCompactResourceFixture([
        'company_uuid'          => 'company-uuid',
        'work_order_uuid'       => 'work-order-uuid',
        'created_by_uuid'       => 'creator-uuid',
        'updated_by_uuid'       => 'updater-uuid',
        'maintainable_uuid'     => 'vehicle-uuid',
        'maintainable_type'     => 'Fleetbase\\FleetOps\\Models\\Vehicle',
        'performed_by_uuid'     => 'vendor-uuid',
        'performed_by_type'     => 'Fleetbase\\FleetOps\\Models\\Vendor',
        'type'                  => 'repair',
        'status'                => 'completed',
        'priority'              => 'medium',
        'odometer'              => 10000,
        'engine_hours'          => 525,
        'summary'               => 'Brake service',
        'notes'                 => 'Replaced pads.',
        'line_items'            => [['name' => 'Pads', 'amount' => 120]],
        'labor_cost'            => 80,
        'parts_cost'            => 120,
        'tax'                   => 14,
        'total_cost'            => 214,
        'currency'              => 'SGD',
        'attachments'           => ['invoice.pdf'],
        'meta'                  => ['shop' => 'North'],
        'slug'                  => 'brake-service',
        'maintainable_name'     => 'Truck 101',
        'work_order_subject'    => 'WO-101',
        'performed_by_name'     => 'Vendor One',
        'duration_hours'        => 2,
        'is_overdue'            => false,
        'days_until_due'        => 0,
        'cost_breakdown'        => ['labor' => 80, 'parts' => 120],
        'scheduled_at'          => '2026-07-20 09:00:00',
        'started_at'            => '2026-07-20 10:00:00',
        'completed_at'          => '2026-07-20 12:00:00',
    ]);

    $payload = (new MaintenanceResource($maintenance))->resolve($request);

    expect($payload)->toMatchArray([
        'id'                  => 101,
        'uuid'                => 'fixture-uuid',
        'company_uuid'        => 'company-uuid',
        'work_order_uuid'     => 'work-order-uuid',
        'maintainable_uuid'   => 'vehicle-uuid',
        'maintainable_type'   => 'fleet-ops:vehicle',
        'performed_by_uuid'   => 'vendor-uuid',
        'performed_by_type'   => 'fleet-ops:vendor',
        'type'                => 'repair',
        'status'              => 'completed',
        'priority'            => 'medium',
        'summary'             => 'Brake service',
        'line_items'          => [['name' => 'Pads', 'amount' => 120]],
        'labor_cost'          => 80,
        'parts_cost'          => 120,
        'tax'                 => 14,
        'total_cost'          => 214,
        'currency'            => 'SGD',
        'attachments'         => ['invoice.pdf'],
        'maintainable_name'   => 'Truck 101',
        'work_order_subject'  => 'WO-101',
        'performed_by_name'   => 'Vendor One',
        'duration_hours'      => 2,
        'is_overdue'          => false,
        'cost_breakdown'      => ['labor' => 80, 'parts' => 120],
        'completed_at'        => '2026-07-20 12:00:00',
    ]);
});

test('maintenance schedule resource serializes interval and next due thresholds', function () {
    $request  = fleetopsCompactResourceRequest(true);
    $schedule = fleetopsCompactResourceFixture([
        'company_uuid'              => 'company-uuid',
        'created_by_uuid'           => 'creator-uuid',
        'updated_by_uuid'           => 'updater-uuid',
        'subject_uuid'              => 'vehicle-uuid',
        'subject_type'              => 'Fleetbase\\FleetOps\\Models\\Vehicle',
        'default_assignee_uuid'     => 'vendor-uuid',
        'default_assignee_type'     => 'Fleetbase\\FleetOps\\Models\\Vendor',
        'name'                      => 'Oil Change',
        'type'                      => 'preventive',
        'status'                    => 'active',
        'interval_method'           => 'distance',
        'interval_type'             => 'recurring',
        'interval_value'            => 5000,
        'interval_unit'             => 'km',
        'interval_distance'         => 5000,
        'interval_engine_hours'     => 250,
        'last_service_odometer'     => 10000,
        'last_service_engine_hours' => 400,
        'last_service_date'         => '2026-06-01',
        'next_due_date'             => '2026-08-01',
        'next_due_odometer'         => 15000,
        'next_due_engine_hours'     => 650,
        'default_priority'          => 'medium',
        'instructions'              => 'Replace oil and filter.',
        'reminder_offsets'          => [7, 1],
        'meta'                      => ['template' => true],
        'slug'                      => 'oil-change',
        'subject_name'              => 'Truck 101',
        'default_assignee_name'     => 'Vendor One',
        'last_triggered_at'         => '2026-06-01 12:00:00',
    ]);

    $payload = (new MaintenanceScheduleResource($schedule))->resolve($request);

    expect($payload)->toMatchArray([
        'id'                      => 101,
        'uuid'                    => 'fixture-uuid',
        'company_uuid'            => 'company-uuid',
        'subject_uuid'            => 'vehicle-uuid',
        'subject_type'            => 'fleet-ops:vehicle',
        'default_assignee_uuid'   => 'vendor-uuid',
        'default_assignee_type'   => 'fleet-ops:vendor',
        'name'                    => 'Oil Change',
        'type'                    => 'preventive',
        'status'                  => 'active',
        'interval_method'         => 'distance',
        'interval_type'           => 'recurring',
        'interval_value'          => 5000,
        'next_due_odometer'       => 15000,
        'default_priority'        => 'medium',
        'instructions'            => 'Replace oil and filter.',
        'reminder_offsets'        => [7, 1],
        'subject_name'            => 'Truck 101',
        'default_assignee_name'   => 'Vendor One',
        'last_triggered_at'       => '2026-06-01 12:00:00',
    ]);
});

test('place resource serializes address data for resources and webhooks', function () {
    $request = fleetopsCompactResourceRequest(true);
    $place   = fleetopsCompactResourceFixture([
        'company_uuid'          => 'company-uuid',
        'owner_uuid'            => null,
        'owner_type'            => null,
        'internal_id'           => 'PLC-101',
        'name'                  => 'Warehouse',
        'location'              => null,
        'address'               => '1 Fleet Way',
        'address_html'          => '<p>1 Fleet Way</p>',
        'avatar_url'            => 'https://cdn.test/place.png',
        'original'              => ['avatar_url' => 'avatar-token'],
        'street1'               => '1 Fleet Way',
        'street2'               => 'Dock 4',
        'city'                  => 'Singapore',
        'province'              => 'Central',
        'postal_code'           => '100001',
        'neighborhood'          => 'Marina',
        'district'              => 'Downtown',
        'building'              => 'Tower',
        'security_access_code'  => '1234',
        'country'               => 'SG',
        'country_name'          => 'Singapore',
        'phone'                 => '+6555550000',
        'type'                  => 'warehouse',
        'meta'                  => ['dock' => 4],
        'eta'                   => '10 minutes',
        'latitude'              => 1.29,
        'longitude'             => 103.85,
    ]);

    $payload = (new PlaceResource($place))->resolve($request);
    $webhook = (new PlaceResource($place))->toWebhookPayload();

    expect($payload)->toMatchArray([
        'id'                   => 101,
        'uuid'                 => 'fixture-uuid',
        'company_uuid'         => 'company-uuid',
        'name'                 => 'Warehouse',
        'address'              => '1 Fleet Way',
        'address_html'         => '<p>1 Fleet Way</p>',
        'avatar_value'         => 'avatar-token',
        'street1'              => '1 Fleet Way',
        'city'                 => 'Singapore',
        'country_name'         => 'Singapore',
        'phone'                => '+6555550000',
        'type'                 => 'warehouse',
        'meta'                 => ['dock' => 4],
        'eta'                  => '10 minutes',
    ])
        ->and($webhook)->toMatchArray([
            'id'                   => 'fixture_public',
            'internal_id'          => 'PLC-101',
            'name'                 => 'Warehouse',
            'latitude'             => 1.29,
            'longitude'            => 103.85,
            'street1'              => '1 Fleet Way',
            'street2'              => 'Dock 4',
            'city'                 => 'Singapore',
            'country'              => 'SG',
            'phone'                => '+6555550000',
            'type'                 => 'warehouse',
            'meta'                 => ['dock' => 4],
        ]);
});

test('device resource serializes telematics device status fields', function () {
    $request = fleetopsCompactResourceRequest(true);
    $device  = fleetopsCompactResourceFixture([
        'company_uuid'          => 'company-uuid',
        'telematic_uuid'        => 'telematic-uuid',
        'attachable_uuid'       => 'vehicle-uuid',
        'attachable_type'       => 'Fleetbase\\FleetOps\\Models\\Vehicle',
        'warranty_uuid'         => 'warranty-uuid',
        'photo_uuid'            => 'photo-uuid',
        'type'                  => 'gps',
        'device_id'             => 'DEV-101',
        'internal_id'           => 'INT-DEV-101',
        'imei'                  => 'imei-101',
        'imsi'                  => 'imsi-101',
        'firmware_version'      => '1.2.3',
        'provider'              => 'safee',
        'name'                  => 'Tracker 101',
        'model'                 => 'T100',
        'location'              => ['lat' => 1.29, 'lng' => 103.85],
        'manufacturer'          => 'TrackerCo',
        'serial_number'         => 'SER-DEV-101',
        'last_position'         => ['speed' => 42],
        'installation_date'     => '2026-01-01',
        'last_maintenance_date' => '2026-06-01',
        'meta'                  => ['vehicle' => 'Truck 101'],
        'data'                  => ['battery' => 87],
        'options'               => ['interval' => 60],
        'online'                => true,
        'status'                => 'active',
        'data_frequency'        => 60,
        'notes'                 => 'Mounted under dash.',
        'last_online_at'        => '2026-07-02 10:00:00',
        'warranty_name'         => 'Standard',
        'telematic_name'        => 'Safee',
        'is_online'             => true,
        'attached_to_name'      => 'Truck 101',
        'connection_status'     => 'connected',
        'photo_url'             => 'https://cdn.test/device.png',
        'sensors_count'         => 2,
        'slug'                  => 'tracker-101',
    ]);

    $payload = (new DeviceResource($device))->resolve($request);

    expect($payload)->toMatchArray([
        'id'                    => 101,
        'uuid'                  => 'fixture-uuid',
        'company_uuid'          => 'company-uuid',
        'telematic_uuid'        => 'telematic-uuid',
        'attachable_uuid'       => 'vehicle-uuid',
        'attachable_type'       => 'fleet-ops:vehicle',
        'type'                  => 'gps',
        'device_id'             => 'DEV-101',
        'internal_id'           => 'INT-DEV-101',
        'imei'                  => 'imei-101',
        'firmware_version'      => '1.2.3',
        'provider'              => 'safee',
        'name'                  => 'Tracker 101',
        'model'                 => 'T100',
        'manufacturer'          => 'TrackerCo',
        'serial_number'         => 'SER-DEV-101',
        'meta'                  => ['vehicle' => 'Truck 101'],
        'data'                  => ['battery' => 87],
        'options'               => ['interval' => 60],
        'online'                => true,
        'status'                => 'active',
        'sensors_count'         => 2,
        'slug'                  => 'tracker-101',
    ]);
});

test('vendor resource serializes vendor identity and webhook address fields', function () {
    $request = fleetopsCompactResourceRequest(true);
    $place   = fleetopsCompactResourceFixture([
        'address' => '1 Vendor Way',
        'street1' => '1 Vendor Way',
    ]);
    $vendor  = fleetopsCompactResourceFixture([
        'place_uuid'           => 'place-uuid',
        'connect_company_uuid' => 'connect-company-uuid',
        'logo_uuid'            => 'logo-uuid',
        'type_uuid'            => 'type-uuid',
        'internal_id'          => 'VEN-101',
        'business_id'          => 'BRN-101',
        'name'                 => 'Vendor One',
        'email'                => 'vendor@example.test',
        'phone'                => '+6555551111',
        'logo_url'             => 'https://cdn.test/logo.png',
        'place'                => $place,
        'places'               => new Collection(),
        'personnels'           => new Collection(),
        'country'              => 'SG',
        'type'                 => 'maintenance',
        'meta'                 => ['tier' => 'gold'],
        'status'               => 'active',
        'slug'                 => 'vendor-one',
        'website_url'          => 'https://vendor.test',
    ]);

    $payload = (new VendorResource($vendor))->resolve($request);
    $webhook = (new VendorResource($vendor))->toWebhookPayload();

    expect($payload)->toMatchArray([
        'id'                   => 101,
        'uuid'                 => 'fixture-uuid',
        'public_id'            => 'fixture_public',
        'place_uuid'           => 'place-uuid',
        'connect_company_uuid' => 'connect-company-uuid',
        'logo_uuid'            => 'logo-uuid',
        'type_uuid'            => 'type-uuid',
        'internal_id'          => 'VEN-101',
        'business_id'          => 'BRN-101',
        'name'                 => 'Vendor One',
        'email'                => 'vendor@example.test',
        'phone'                => '+6555551111',
        'photo_url'            => 'https://cdn.test/logo.png',
        'address'              => '1 Vendor Way',
        'address_street'       => '1 Vendor Way',
        'country'              => 'SG',
        'type'                 => 'maintenance',
        'status'               => 'active',
        'website_url'          => 'https://vendor.test',
    ])
        ->and($webhook)->toMatchArray([
            'id'             => 'fixture_public',
            'internal_id'    => 'VEN-101',
            'name'           => 'Vendor One',
            'email'          => 'vendor@example.test',
            'phone'          => '+6555551111',
            'photo_url'      => 'https://cdn.test/logo.png',
            'address'        => '1 Vendor Way',
            'address_street' => '1 Vendor Way',
            'country'        => 'SG',
            'type'           => 'maintenance',
            'status'         => 'active',
            'website_url'    => 'https://vendor.test',
        ]);
});

test('contact resource serializes contact identity and webhook payloads', function () {
    $request = fleetopsCompactResourceRequest(true);
    $place   = fleetopsCompactResourceFixture([
        'address' => '1 Contact Way',
        'street1' => '1 Contact Way',
    ]);
    $contact = fleetopsCompactResourceFixture([
        'company_uuid'     => 'company-uuid',
        'user_uuid'        => 'user-uuid',
        'place_uuid'       => 'place-uuid',
        'photo_uuid'       => 'photo-uuid',
        'internal_id'      => 'CON-101',
        'name'             => 'Customer Contact',
        'title'            => 'Manager',
        'email'            => 'contact@example.test',
        'phone'            => '+6555552222',
        'photo_url'        => 'https://cdn.test/contact.png',
        'place'            => $place,
        'places'           => new Collection(),
        'type'             => 'customer',
        'customer_type'    => 'Fleetbase\\FleetOps\\Models\\Contact',
        'facilitator_type' => 'Fleetbase\\FleetOps\\Models\\Vendor',
        'meta'             => ['segment' => 'priority'],
        'slug'             => 'customer-contact',
    ]);

    $payload = (new ContactResource($contact))->resolve($request);
    $webhook = (new ContactResource($contact))->toWebhookPayload();

    expect($payload)->toMatchArray([
        'id'               => 101,
        'uuid'             => 'fixture-uuid',
        'company_uuid'     => 'company-uuid',
        'user_uuid'        => 'user-uuid',
        'place_uuid'       => 'place-uuid',
        'photo_uuid'       => 'photo-uuid',
        'public_id'        => 'fixture_public',
        'internal_id'      => 'CON-101',
        'name'             => 'Customer Contact',
        'title'            => 'Manager',
        'email'            => 'contact@example.test',
        'phone'            => '+6555552222',
        'address'          => '1 Contact Way',
        'address_street'   => '1 Contact Way',
        'type'             => 'customer',
        'customer_type'    => 'fleet-ops:contact',
        'facilitator_type' => 'fleet-ops:vendor',
        'meta'             => ['segment' => 'priority'],
        'slug'             => 'customer-contact',
    ])
        ->and($webhook)->toMatchArray([
            'id'          => 'fixture_public',
            'internal_id' => 'CON-101',
            'name'        => 'Customer Contact',
            'title'       => 'Manager',
            'email'       => 'contact@example.test',
            'phone'       => '+6555552222',
            'photo_url'   => 'https://cdn.test/contact.png',
            'type'        => 'customer',
            'meta'        => ['segment' => 'priority'],
            'slug'        => 'customer-contact',
        ]);
});

test('fleet resources serialize counters and webhook fleet identifiers', function () {
    $request = fleetopsCompactResourceRequest(true);
    $fleet   = fleetopsCompactResourceFixture([
        'name'                  => 'Downtown Fleet',
        'task'                  => 'delivery',
        'status'                => 'active',
        'drivers_count'         => 5,
        'drivers_online_count'  => 3,
        'vehicles_count'        => 4,
        'vehicles_online_count' => 2,
        'serviceArea'           => (object) ['public_id' => 'area_public'],
        'zone'                  => (object) ['public_id' => 'zone_public'],
        'parentFleet'           => (object) ['public_id' => 'parent_public'],
    ]);

    foreach ([FleetResource::class, ParentFleetResource::class, SubFleetResource::class] as $resourceClass) {
        $payload = (new $resourceClass($fleet))->resolve($request);
        $webhook = (new $resourceClass($fleet))->toWebhookPayload();

        expect($payload)->toMatchArray([
            'id'                    => 101,
            'uuid'                  => 'fixture-uuid',
            'public_id'             => 'fixture_public',
            'name'                  => 'Downtown Fleet',
            'task'                  => 'delivery',
            'status'                => 'active',
            'drivers_count'         => 5,
            'drivers_online_count'  => 3,
            'vehicles_count'        => 4,
            'vehicles_online_count' => 2,
        ])
            ->and($webhook)->toMatchArray([
                'id'           => 'fixture_public',
                'name'         => 'Downtown Fleet',
                'task'         => 'delivery',
                'status'       => 'active',
                'service_area' => 'area_public',
                'zone'         => 'zone_public',
            ]);
    }
});

test('tracking status resource serializes current scan and tracking number details', function () {
    $request = fleetopsCompactResourceRequest(true);
    $status  = fleetopsCompactResourceFixture([
        'tracking_number_uuid' => 'tracking-uuid',
        'proof_uuid'           => 'proof-uuid',
        'status'               => 'Out for delivery',
        'details'              => 'Driver departed hub.',
        'code'                 => 'out_for_delivery',
        'complete'             => false,
        'trackingNumber'       => fleetopsCompactResourceFixture([
            'tracking_number'  => 'TN-101',
            'region'           => 'SG',
            'last_status'      => 'Out for delivery',
            'last_status_code' => 'out_for_delivery',
            'owner_type'       => null,
        ]),
        'city'                 => 'Singapore',
        'province'             => 'Central',
        'postal_code'          => '100001',
        'country'              => 'SG',
        'location'             => null,
    ]);

    $payload = (new TrackingStatusResource($status))->resolve($request);
    $webhook = (new TrackingStatusResource($status))->toWebhookPayload();

    expect($payload)->toMatchArray([
        'id'                   => 101,
        'uuid'                 => 'fixture-uuid',
        'public_id'            => 'fixture_public',
        'tracking_number_uuid' => 'tracking-uuid',
        'proof_uuid'           => 'proof-uuid',
        'status'               => 'Out for delivery',
        'details'              => 'Driver departed hub.',
        'code'                 => 'out_for_delivery',
        'complete'             => false,
        'city'                 => 'Singapore',
        'country'              => 'SG',
    ])
        ->and($webhook)->toMatchArray([
            'id'      => 'fixture_public',
            'status'  => 'Out for delivery',
            'details' => 'Driver departed hub.',
            'code'    => 'out_for_delivery',
            'city'    => 'Singapore',
            'country' => 'SG',
        ]);
});

test('order config resource projects public flow activities', function () {
    $request = fleetopsCompactResourceRequest(true);
    $config  = fleetopsCompactResourceFixture([
        'company_uuid' => 'company-uuid',
        'key'          => 'delivery',
        'name'         => 'Delivery',
        'namespace'    => 'fleet-ops',
        'description'  => 'Delivery order flow.',
        'tags'         => ['last-mile'],
        'status'       => 'active',
        'version'      => 2,
        'flow'         => [
            [
                'key'         => 'created',
                'status'      => 'Created',
                'details'     => 'Order created.',
                'color'       => '#00ff00',
                'complete'    => false,
                'pod_method'  => 'photo',
                'require_pod' => true,
            ],
            'skip-me',
        ],
    ]);

    $payload = (new OrderConfigResource($config))->resolve($request);

    expect($payload)->toMatchArray([
        'id'           => 101,
        'uuid'         => 'fixture-uuid',
        'public_id'    => 'fixture_public',
        'company_uuid' => 'company-uuid',
        'key'          => 'delivery',
        'name'         => 'Delivery',
        'namespace'    => 'fleet-ops',
        'description'  => 'Delivery order flow.',
        'tags'         => ['last-mile'],
        'status'       => 'active',
        'version'      => 2,
        'flow'         => [
            [
                'code'        => 'created',
                'status'      => 'Created',
                'details'     => 'Order created.',
                'color'       => '#00ff00',
                'complete'    => false,
                'pod_method'  => 'photo',
                'require_pod' => true,
                // The flow's shape now rides along so consumers can sequence
                // it. An activity that declares none of it still publishes the
                // keys, so the payload shape does not vary per activity.
                'sequence'    => null,
                'activities'  => [],
                'logic'       => null,
            ],
        ],
    ]);
});

test('purchase rate resource serializes transaction references', function () {
    $request = fleetopsCompactResourceRequest(true);
    $rate    = fleetopsCompactResourceFixture([
        'service_quote_id' => 'quote_public',
        'order_id'         => 'order_public',
        'customer_id'      => 'customer_public',
        'transaction_id'   => 'txn_public',
        'amount'           => 25.75,
        'currency'         => 'SGD',
        'status'           => 'quoted',
    ]);

    $payload = (new PurchaseRateResource($rate))->resolve($request);
    $webhook = (new PurchaseRateResource($rate))->toWebhookPayload();

    expect($payload)->toMatchArray([
        'id'          => 101,
        'uuid'        => 'fixture-uuid',
        'public_id'   => 'fixture_public',
        'order'       => 'order_public',
        'customer'    => 'customer_public',
        'transaction' => 'txn_public',
        'amount'      => 25.75,
        'currency'    => 'SGD',
        'status'      => 'quoted',
    ])
        ->and($webhook)->toMatchArray([
            'id'            => 'fixture_public',
            'service_quote' => 'quote_public',
            'order'         => 'order_public',
            'customer'      => 'customer_public',
            'transaction'   => 'txn_public',
            'amount'        => 25.75,
            'currency'      => 'SGD',
            'status'        => 'quoted',
        ]);
});

test('service area and zone resources serialize geofence display fields', function () {
    $request = fleetopsCompactResourceRequest(true);
    $area    = fleetopsCompactResourceFixture([
        'name'                    => 'Central',
        'type'                    => 'service_area',
        'location'                => null,
        'border'                  => ['type' => 'Polygon'],
        'color'                   => '#00ff00',
        'stroke_color'            => '#004400',
        'trigger_on_entry'        => true,
        'trigger_on_exit'         => false,
        'dwell_threshold_minutes' => 15,
        'speed_limit_kmh'         => 50,
        'country'                 => 'SG',
        'status'                  => 'active',
    ]);
    $zone    = fleetopsCompactResourceFixture([
        'service_area_uuid' => 'area-uuid',
        'name'              => 'Downtown',
        'description'       => 'Downtown service zone.',
        'location'          => null,
        'border'            => ['type' => 'Polygon'],
        'color'             => '#0000ff',
        'stroke_color'      => '#000044',
        'status'            => 'active',
    ]);

    expect((new ServiceAreaResource($area))->resolve($request))->toMatchArray([
        'id'                      => 101,
        'uuid'                    => 'fixture-uuid',
        'public_id'               => 'fixture_public',
        'name'                    => 'Central',
        'type'                    => 'service_area',
        'border'                  => ['type' => 'Polygon'],
        'trigger_on_entry'        => true,
        'trigger_on_exit'         => false,
        'dwell_threshold_minutes' => 15,
        'speed_limit_kmh'         => 50,
        'country'                 => 'SG',
        'status'                  => 'active',
    ])
        ->and((new ServiceAreaResource($area))->toWebhookPayload())->toMatchArray([
            'id'      => 'fixture_public',
            'name'    => 'Central',
            'type'    => 'service_area',
            'country' => 'SG',
            'status'  => 'active',
        ])
        ->and((new ZoneResource($zone))->resolve($request))->toMatchArray([
            'id'                => 101,
            'public_id'         => 'fixture_public',
            'uuid'              => 'fixture-uuid',
            'service_area_uuid' => 'area-uuid',
            'name'              => 'Downtown',
            'description'       => 'Downtown service zone.',
            'border'            => ['type' => 'Polygon'],
            'color'             => '#0000ff',
            'stroke_color'      => '#000044',
            'status'            => 'active',
        ])
        ->and((new ZoneResource($zone))->toWebhookPayload())->toMatchArray([
            'id'          => 'fixture_public',
            'name'        => 'Downtown',
            'description' => 'Downtown service zone.',
            'status'      => 'active',
        ]);
});

test('payload resource serializes waypoint references and payment metadata', function () {
    $request = fleetopsCompactResourceRequest(true);
    $payload = fleetopsCompactResourceFixture([
        'current_waypoint_uuid'        => 'waypoint-current-uuid',
        'pickup_uuid'                  => 'pickup-uuid',
        'pickup_tracking_number_uuid'  => 'pickup-tracking-uuid',
        'dropoff_uuid'                 => 'dropoff-uuid',
        'dropoff_tracking_number_uuid' => 'dropoff-tracking-uuid',
        'return_uuid'                  => 'return-uuid',
        'return_tracking_number_uuid'  => 'return-tracking-uuid',
        'currentWaypoint'              => (object) ['public_id' => 'waypoint_current'],
        'pickup'                       => null,
        'dropoff'                      => null,
        'return'                       => null,
        'waypoints'                    => new Collection(),
        'entities'                     => new Collection(),
        'cod_amount'                   => 19.99,
        'cod_currency'                 => 'SGD',
        'cod_payment_method'           => 'cash',
        'payment_method'               => 'card',
        'meta'                         => ['fragile' => true],
    ]);

    $resolved = (new PayloadResource($payload))->resolve($request);

    expect($resolved)->toMatchArray([
        'id'                           => 101,
        'uuid'                         => 'fixture-uuid',
        'public_id'                    => 'fixture_public',
        'current_waypoint_uuid'        => 'waypoint-current-uuid',
        'pickup_uuid'                  => 'pickup-uuid',
        'pickup_tracking_number_uuid'  => 'pickup-tracking-uuid',
        'dropoff_uuid'                 => 'dropoff-uuid',
        'dropoff_tracking_number_uuid' => 'dropoff-tracking-uuid',
        'return_uuid'                  => 'return-uuid',
        'return_tracking_number_uuid'  => 'return-tracking-uuid',
        'cod_amount'                   => 19.99,
        'cod_currency'                 => 'SGD',
        'cod_payment_method'           => 'cash',
        'payment_method'               => 'card',
        'meta'                         => ['fragile' => true],
    ]);
});

test('work order resource serializes assignment and completion state', function () {
    $request   = fleetopsCompactResourceRequest(true);
    $workOrder = fleetopsCompactResourceFixture([
        'company_uuid'          => 'company-uuid',
        'schedule_uuid'         => 'schedule-uuid',
        'created_by_uuid'       => 'creator-uuid',
        'updated_by_uuid'       => 'updater-uuid',
        'target_uuid'           => 'vehicle-uuid',
        'target_type'           => 'Fleetbase\\FleetOps\\Models\\Vehicle',
        'assignee_uuid'         => 'vendor-uuid',
        'assignee_type'         => 'Fleetbase\\FleetOps\\Models\\Vendor',
        'code'                  => 'WO-101',
        'subject'               => 'Inspect brakes',
        'category'              => 'safety',
        'status'                => 'open',
        'priority'              => 'high',
        'instructions'          => 'Inspect and report.',
        'checklist'             => [['label' => 'Pads', 'done' => false]],
        'meta'                  => ['source' => 'schedule'],
        'slug'                  => 'inspect-brakes',
        'target_name'           => 'Truck 101',
        'assignee_name'         => 'Vendor One',
        'is_overdue'            => true,
        'days_until_due'        => -1,
        'completion_percentage' => 25,
        'opened_at'             => '2026-07-01 09:00:00',
        'due_at'                => '2026-07-05 09:00:00',
        'closed_at'             => null,
    ]);

    expect((new WorkOrderResource($workOrder))->resolve($request))->toMatchArray([
        'id'                    => 101,
        'uuid'                  => 'fixture-uuid',
        'public_id'             => 'fixture_public',
        'company_uuid'          => 'company-uuid',
        'schedule_uuid'         => 'schedule-uuid',
        'target_uuid'           => 'vehicle-uuid',
        'target_type'           => 'fleet-ops:vehicle',
        'assignee_uuid'         => 'vendor-uuid',
        'assignee_type'         => 'fleet-ops:vendor',
        'code'                  => 'WO-101',
        'subject'               => 'Inspect brakes',
        'category'              => 'safety',
        'status'                => 'open',
        'priority'              => 'high',
        'instructions'          => 'Inspect and report.',
        'checklist'             => [['label' => 'Pads', 'done' => false]],
        'target_name'           => 'Truck 101',
        'assignee_name'         => 'Vendor One',
        'is_overdue'            => true,
        'days_until_due'        => -1,
        'completion_percentage' => 25,
        'opened_at'             => '2026-07-01 09:00:00',
        'due_at'                => '2026-07-05 09:00:00',
    ]);
});

test('fuel transaction resource serializes provider transaction state', function () {
    $request     = fleetopsCompactResourceRequest(true);
    $transaction = fleetopsCompactResourceFixture([
        'fuel_provider_connection_uuid' => 'connection-uuid',
        'fuel_report_uuid'              => 'report-uuid',
        'vehicle_uuid'                  => 'vehicle-uuid',
        'driver_uuid'                   => 'driver-uuid',
        'order_uuid'                    => 'order-uuid',
        'provider'                      => 'wex',
        'provider_transaction_id'       => 'provider-txn-101',
        'provider_vehicle_id'           => 'provider-vehicle-101',
        'vehicle_card_id'               => 'card-101',
        'internal_number'               => 'INT-101',
        'structure_number'              => 'STRUCT-101',
        'plate_number'                  => 'ABC-101',
        'vin'                           => 'VIN-101',
        'serial_number'                 => 'SER-101',
        'call_sign'                     => 'TRUCK-101',
        'trip_number'                   => 'TRIP-101',
        'station_name'                  => 'Fuel Stop',
        'station_latitude'              => 1.29,
        'station_longitude'             => 103.85,
        'station_location'              => ['lat' => 1.29, 'lng' => 103.85],
        'transaction_at'                => '2026-07-01 11:00:00',
        'volume'                        => 30.5,
        'metric_unit'                   => 'liters',
        'amount'                        => 92.25,
        'currency'                      => 'SGD',
        'odometer'                      => 12000,
        'sync_status'                   => 'matched',
        'matched_at'                    => '2026-07-01 11:05:00',
        'vehicle_name'                  => 'Truck 101',
        'driver_name'                   => 'Jane Driver',
        'fuel_report_id'                => 'report_public',
        'normalized_payload'            => ['amount' => 92.25],
        'raw_payload'                   => ['raw' => true],
        'meta'                          => ['matched_by' => 'vin'],
    ]);

    expect((new Fleetbase\FleetOps\Http\Resources\v1\FuelTransaction($transaction))->resolve($request))->toMatchArray([
        'id'                            => 101,
        'uuid'                          => 'fixture-uuid',
        'public_id'                     => 'fixture_public',
        'fuel_provider_connection_uuid' => 'connection-uuid',
        'fuel_report_uuid'              => 'report-uuid',
        'vehicle_uuid'                  => 'vehicle-uuid',
        'driver_uuid'                   => 'driver-uuid',
        'order_uuid'                    => 'order-uuid',
        'provider'                      => 'wex',
        'provider_transaction_id'       => 'provider-txn-101',
        'plate_number'                  => 'ABC-101',
        'station_name'                  => 'Fuel Stop',
        'volume'                        => 30.5,
        'amount'                        => 92.25,
        'currency'                      => 'SGD',
        'sync_status'                   => 'matched',
        'vehicle_name'                  => 'Truck 101',
        'driver_name'                   => 'Jane Driver',
        'normalized_payload'            => ['amount' => 92.25],
        'raw_payload'                   => ['raw' => true],
        'meta'                          => ['matched_by' => 'vin'],
    ]);
});

test('sensor resource serializes device sensor thresholds and status', function () {
    $request = fleetopsCompactResourceRequest(true);
    $sensor  = fleetopsCompactResourceFixture([
        'company_uuid'         => 'company-uuid',
        'device_uuid'          => 'device-uuid',
        'warranty_uuid'        => 'warranty-uuid',
        'telematic_uuid'       => 'telematic-uuid',
        'photo_uuid'           => 'photo-uuid',
        'sensorable_uuid'      => 'vehicle-uuid',
        'sensorable_type'      => 'Fleetbase\\FleetOps\\Models\\Vehicle',
        'name'                 => 'Temperature Sensor',
        'type'                 => 'temperature',
        'internal_id'          => 'SEN-101',
        'imei'                 => 'imei-sensor',
        'imsi'                 => 'imsi-sensor',
        'firmware_version'     => '4.5.6',
        'serial_number'        => 'SER-SEN-101',
        'last_position'        => ['lat' => 1.29],
        'unit'                 => 'celsius',
        'min_threshold'        => 2,
        'max_threshold'        => 8,
        'threshold_inclusive'  => true,
        'last_reading_at'      => '2026-07-01 12:00:00',
        'last_value'           => 4.2,
        'calibration'          => ['offset' => 0.1],
        'report_frequency_sec' => 60,
        'status'               => 'active',
        'meta'                 => ['cargo' => 'chilled'],
        'is_active'            => true,
        'threshold_status'     => 'normal',
        'photo_url'            => 'https://cdn.test/sensor.png',
        'device_name'          => 'Tracker 101',
        'warranty_name'        => 'Standard',
        'attached_to_name'     => 'Truck 101',
        'slug'                 => 'temperature-sensor',
    ]);

    expect((new SensorResource($sensor))->resolve($request))->toMatchArray([
        'id'                   => 101,
        'uuid'                 => 'fixture-uuid',
        'public_id'            => 'fixture_public',
        'company_uuid'         => 'company-uuid',
        'device_uuid'          => 'device-uuid',
        'sensorable_uuid'      => 'vehicle-uuid',
        'sensorable_type'      => 'fleet-ops:vehicle',
        'name'                 => 'Temperature Sensor',
        'type'                 => 'temperature',
        'internal_id'          => 'SEN-101',
        'unit'                 => 'celsius',
        'min_threshold'        => 2,
        'max_threshold'        => 8,
        'threshold_inclusive'  => true,
        'last_value'           => 4.2,
        'calibration'          => ['offset' => 0.1],
        'report_frequency_sec' => 60,
        'status'               => 'active',
        'meta'                 => ['cargo' => 'chilled'],
        'is_active'            => true,
        'threshold_status'     => 'normal',
        'slug'                 => 'temperature-sensor',
    ]);
});

test('inventory and rate resources serialize scalar operational fields', function () {
    $request = fleetopsCompactResourceRequest(true);

    $rateFee = fleetopsCompactResourceFixture([
        'service_rate_uuid' => 'rate-uuid',
        'service_area_uuid' => 'area-uuid',
        'zone_uuid'         => 'zone-uuid',
        'label'             => 'Distance band',
        'priority'          => 10,
        'is_fallback'       => 1,
        'fee'               => 12.5,
        'currency'          => 'SGD',
        'min'               => 0,
        'max'               => 10,
        'unit'              => 'km',
        'distance'          => 10,
        'distance_unit'     => 'km',
        'size'              => 'medium',
        'length'            => 10,
        'height'            => 8,
        'dimensions_unit'   => 'cm',
        'weight'            => 2,
        'weight_unit'       => 'kg',
    ]);
    $parcelFee = fleetopsCompactResourceFixture([
        'service_rate_uuid' => 'rate-uuid',
        'fee'               => 9.5,
        'currency'          => 'SGD',
        'size'              => 'small',
        'length'            => 8,
        'width'             => 4,
        'height'            => 3,
        'dimensions_unit'   => 'cm',
        'weight'            => 1.5,
        'weight_unit'       => 'kg',
        'distance'          => 5,
    ]);
    $part = fleetopsCompactResourceFixture([
        'company_uuid'     => 'company-uuid',
        'vendor_uuid'      => 'vendor-uuid',
        'warranty_uuid'    => 'warranty-uuid',
        'photo_uuid'       => 'photo-uuid',
        'asset_uuid'       => 'vehicle-uuid',
        'asset_type'       => 'Fleetbase\\FleetOps\\Models\\Vehicle',
        'sku'              => 'PART-101',
        'name'             => 'Brake Pad',
        'manufacturer'     => 'PartsCo',
        'model'            => 'BP-1',
        'serial_number'    => 'SER-PART-101',
        'barcode'          => 'BAR-PART-101',
        'description'      => 'Front brake pad.',
        'quantity_on_hand' => 4,
        'unit_cost'        => 25,
        'msrp'             => 35,
        'currency'         => 'SGD',
        'type'             => 'brake',
        'status'           => 'active',
        'specs'            => ['axle' => 'front'],
        'meta'             => ['shelf' => 'A1'],
        'vendor_name'      => 'Vendor One',
        'warranty_name'    => 'Standard',
        'photo_url'        => 'https://cdn.test/part.png',
        'total_value'      => 100,
        'is_in_stock'      => true,
        'is_low_stock'     => false,
        'asset_name'       => 'Truck 101',
        'slug'             => 'brake-pad',
    ]);
    $equipment = fleetopsCompactResourceFixture([
        'company_uuid'      => 'company-uuid',
        'warranty_uuid'     => 'warranty-uuid',
        'photo_uuid'        => 'photo-uuid',
        'equipable_uuid'    => 'vehicle-uuid',
        'equipable_type'    => 'Fleetbase\\FleetOps\\Models\\Vehicle',
        'name'              => 'Lift Gate',
        'code'              => 'EQ-101',
        'type'              => 'liftgate',
        'status'            => 'active',
        'serial_number'     => 'SER-EQ-101',
        'manufacturer'      => 'EquipmentCo',
        'model'             => 'LG-1',
        'purchased_at'      => '2026-01-01',
        'purchase_price'    => 1200,
        'currency'          => 'SGD',
        'warranty_name'     => 'Standard',
        'photo_url'         => 'https://cdn.test/equipment.png',
        'equipped_to_name'  => 'Truck 101',
        'is_equipped'       => true,
        'age_in_days'       => 30,
        'depreciated_value' => 1100,
        'meta'              => ['bay' => 'north'],
        'slug'              => 'lift-gate',
    ]);

    expect((new Fleetbase\FleetOps\Http\Resources\v1\ServiceRateFee($rateFee))->resolve($request))->toMatchArray([
        'id'                => 101,
        'uuid'              => 'fixture-uuid',
        'service_rate_uuid' => 'rate-uuid',
        'service_area_uuid' => 'area-uuid',
        'zone_uuid'         => 'zone-uuid',
        'label'             => 'Distance band',
        'priority'          => 10,
        'is_fallback'       => true,
        'fee'               => 12.5,
        'currency'          => 'SGD',
        'distance_unit'     => 'km',
    ])
        ->and((new Fleetbase\FleetOps\Http\Resources\v1\ServiceRateFee($rateFee))->toWebhookPayload())->toMatchArray([
            'fee'             => 12.5,
            'currency'        => 'SGD',
            'size'            => 'medium',
            'dimensions_unit' => 'cm',
            'weight_unit'     => 'kg',
        ])
        ->and((new Fleetbase\FleetOps\Http\Resources\v1\ServiceRateParcelFee($parcelFee))->resolve($request))->toMatchArray([
            'id'                => 101,
            'uuid'              => 'fixture-uuid',
            'service_rate_uuid' => 'rate-uuid',
            'fee'               => 9.5,
            'currency'          => 'SGD',
            'size'              => 'small',
            'dimensions_unit'   => 'cm',
            'weight_unit'       => 'kg',
        ])
        ->and((new Fleetbase\FleetOps\Http\Resources\v1\ServiceRateParcelFee($parcelFee))->toWebhookPayload())->toMatchArray([
            'fee'      => 9.5,
            'currency' => 'SGD',
            'distance' => 5,
        ])
        ->and((new Fleetbase\FleetOps\Http\Resources\v1\Part($part))->resolve($request))->toMatchArray([
            'id'               => 101,
            'uuid'             => 'fixture-uuid',
            'company_uuid'     => 'company-uuid',
            'asset_type'       => 'fleet-ops:vehicle',
            'sku'              => 'PART-101',
            'name'             => 'Brake Pad',
            'quantity_on_hand' => 4,
            'unit_cost'        => 25,
            'total_value'      => 100,
            'is_in_stock'      => true,
            'is_low_stock'     => false,
            'asset_name'       => 'Truck 101',
        ])
        ->and((new Fleetbase\FleetOps\Http\Resources\v1\Equipment($equipment))->resolve($request))->toMatchArray([
            'id'                 => 101,
            'uuid'               => 'fixture-uuid',
            'company_uuid'       => 'company-uuid',
            'equipable_type'     => 'fleet-ops:vehicle',
            'name'               => 'Lift Gate',
            'code'               => 'EQ-101',
            'purchase_price'     => 1200,
            'currency'           => 'SGD',
            'equipped_to_name'   => 'Truck 101',
            'is_equipped'        => true,
            'age_in_days'        => 30,
            'depreciated_value'  => 1100,
        ]);
});

test('position fuel report and vehicle device resources serialize telemetry fields', function () {
    $request = fleetopsCompactResourceRequest(true);

    $position = fleetopsCompactResourceFixture([
        'order_uuid'       => 'order-uuid',
        'company_uuid'     => 'company-uuid',
        'destination_uuid' => 'place-uuid',
        'subject_uuid'     => 'vehicle-uuid',
        'subject_type'     => 'Fleetbase\\FleetOps\\Models\\Vehicle',
        'heading'          => 45,
        'bearing'          => 90,
        'speed'            => 55,
        'altitude'         => 12,
        'latitude'         => 1.29,
        'longitude'        => 103.85,
        'coordinates'      => null,
    ]);
    $fuelReport = fleetopsCompactResourceFixture([
        'reported_by_uuid'               => 'reporter-uuid',
        'driver_uuid'                    => 'driver-uuid',
        'vehicle_uuid'                   => 'vehicle-uuid',
        'reporter_name'                  => 'Dispatcher',
        'driver_name'                    => 'Jane Driver',
        'vehicle_name'                   => 'Truck 101',
        'odometer'                       => 12000,
        'amount'                         => 92.25,
        'currency'                       => 'SGD',
        'volume'                         => 30.5,
        'metric_unit'                    => 'liters',
        'type'                           => 'fuel',
        'status'                         => 'submitted',
        'source'                         => 'manual',
        'provider'                       => 'wex',
        'fuel_provider_transaction_uuid' => 'txn-uuid',
        'meta'                           => ['receipt' => true],
        'location'                       => null,
        'reportedBy'                     => (object) ['public_id' => 'reporter_public'],
        'driver'                         => (object) ['public_id' => 'driver_public'],
        'vehicle'                        => (object) ['public_id' => 'vehicle_public'],
        'report'                         => 'Fuel Stop',
    ]);
    $vehicleDevice = fleetopsCompactResourceFixture([
        'vehicle_uuid'          => 'vehicle-uuid',
        'device_id'             => 'device-101',
        'device_provider'       => 'safee',
        'device_type'           => 'gps',
        'device_name'           => 'Tracker 101',
        'device_model'          => 'T100',
        'device_location'       => ['lat' => 1.29],
        'manufacturer'          => 'TrackerCo',
        'serial_number'         => 'SER-DEV-101',
        'installation_date'     => '2026-01-01',
        'last_maintenance_date' => '2026-06-01',
        'meta'                  => ['mounted' => true],
        'data'                  => ['battery' => 87],
        'status'                => 'active',
        'data_frequency'        => 60,
        'notes'                 => 'Mounted under dash.',
    ]);

    expect((new Fleetbase\FleetOps\Http\Resources\v1\Position($position))->resolve($request))->toMatchArray([
        'id'               => 101,
        'uuid'             => 'fixture-uuid',
        'public_id'        => 'fixture_public',
        'order_uuid'       => 'order-uuid',
        'company_uuid'     => 'company-uuid',
        'destination_uuid' => 'place-uuid',
        'subject_uuid'     => 'vehicle-uuid',
        'subject_type'     => 'fleet-ops:vehicle',
        'heading'          => 45,
        'bearing'          => 90,
        'speed'            => 55,
        'altitude'         => 12,
        'latitude'         => 1.29,
        'longitude'        => 103.85,
    ])
        ->and((new Fleetbase\FleetOps\Http\Resources\v1\FuelReport($fuelReport))->resolve($request))->toMatchArray([
            'id'                             => 101,
            'uuid'                           => 'fixture-uuid',
            'reported_by_uuid'               => 'reporter-uuid',
            'driver_uuid'                    => 'driver-uuid',
            'vehicle_uuid'                   => 'vehicle-uuid',
            'reporter_name'                  => 'Dispatcher',
            'driver_name'                    => 'Jane Driver',
            'vehicle_name'                   => 'Truck 101',
            'odometer'                       => 12000,
            'amount'                         => 92.25,
            'currency'                       => 'SGD',
            'volume'                         => 30.5,
            'metric_unit'                    => 'liters',
            'source'                         => 'manual',
            'provider'                       => 'wex',
            'fuel_provider_transaction_uuid' => 'txn-uuid',
        ])
        ->and((new Fleetbase\FleetOps\Http\Resources\v1\FuelReport($fuelReport))->toWebhookPayload())->toMatchArray([
            'id'          => 'fixture_public',
            'reporter'    => 'reporter_public',
            'driver'      => 'driver_public',
            'vehicle'     => 'vehicle_public',
            'report_name' => 'Fuel Stop',
            'amount'      => 92.25,
            'currency'    => 'SGD',
            'provider'    => 'wex',
        ])
        ->and((new Fleetbase\FleetOps\Http\Resources\v1\VehicleDevice($vehicleDevice))->resolve($request))->toMatchArray([
            'id'                    => 101,
            'uuid'                  => 'fixture-uuid',
            'vehicle_uuid'          => 'vehicle-uuid',
            'device_id'             => 'device-101',
            'device_provider'       => 'safee',
            'device_type'           => 'gps',
            'device_name'           => 'Tracker 101',
            'device_model'          => 'T100',
            'manufacturer'          => 'TrackerCo',
            'serial_number'         => 'SER-DEV-101',
            'installation_date'     => '2026-01-01',
            'last_maintenance_date' => '2026-06-01',
            'meta'                  => ['mounted' => true],
            'data'                  => ['battery' => 87],
            'status'                => 'active',
            'data_frequency'        => 60,
            'notes'                 => 'Mounted under dash.',
        ]);
});

test('index driver payload and place resources serialize compact table fields', function () {
    $request = fleetopsCompactResourceRequest(true);
    $driver  = fleetopsCompactResourceFixture([
        'company_uuid'     => 'company-uuid',
        'user_uuid'        => 'user-uuid',
        'vehicle_uuid'     => 'vehicle-uuid',
        'vendor_uuid'      => 'vendor-uuid',
        'current_job_uuid' => 'order-uuid',
        'name'             => 'Jane Driver',
        'vehicle_name'     => 'Truck 101',
        'email'            => 'jane@example.test',
        'phone'            => '+6555553333',
        'photo_url'        => 'https://cdn.test/driver.png',
        'status'           => 'on_duty',
        'location'         => null,
        'heading'          => 135,
        'altitude'         => 9,
        'speed'            => 48,
        'online'           => true,
    ]);
    $payload = fleetopsCompactResourceFixture([
        'company_uuid'        => 'company-uuid',
        'pickup_uuid'         => 'pickup-uuid',
        'dropoff_uuid'        => 'dropoff-uuid',
        'return_uuid'         => 'return-uuid',
        'index_pickup_place'  => null,
        'index_dropoff_place' => null,
        'type'                => 'parcel',
    ], [
        'entities'  => new Collection([(object) ['public_id' => 'entity_public']]),
        'waypoints' => new Collection([(object) ['public_id' => 'waypoint_public']]),
    ]);
    $place   = fleetopsCompactResourceFixture([
        'company_uuid' => 'company-uuid',
        'owner_uuid'   => 'owner-uuid',
        'owner_type'   => 'Fleetbase\\FleetOps\\Models\\Contact',
        'name'         => 'Warehouse',
        'address'      => '1 Index Way',
        'street1'      => '1 Index Way',
        'city'         => 'Singapore',
        'country'      => 'SG',
        'avatar_url'   => 'https://cdn.test/place.png',
        'location'     => null,
    ]);

    expect((new TestFleetOpsIndexDriverResource($driver))->resolve($request))->toMatchArray([
        'id'                    => 101,
        'uuid'                  => 'fixture-uuid',
        'public_id'             => 'fixture_public',
        'company_uuid'          => 'company-uuid',
        'user_uuid'             => 'user-uuid',
        'vehicle_uuid'          => 'vehicle-uuid',
        'vendor_uuid'           => 'vendor-uuid',
        'current_job_uuid'      => 'order-uuid',
        'assigned_orders_count' => 5,
        'name'                  => 'Jane Driver',
        'vehicle_name'          => 'Truck 101',
        'email'                 => 'jane@example.test',
        'status'                => 'on_duty',
        'heading'               => 135,
        'altitude'              => 9,
        'speed'                 => 48,
        'online'                => true,
        'meta'                  => [
            '_index_resource'         => true,
            'location_coordinates'    => '0 0',
            'current_order_reference' => 'TRK-DRIVER',
            'speed_label'             => '48 km/h',
            'heading_label'           => '135 deg',
            'status_label'            => 'On Duty',
        ],
    ])
        ->and((new IndexPayloadResource($payload))->resolve($request))->toMatchArray([
            'id'              => 101,
            'uuid'            => 'fixture-uuid',
            'public_id'       => 'fixture_public',
            'company_uuid'    => 'company-uuid',
            'pickup_uuid'     => 'pickup-uuid',
            'dropoff_uuid'    => 'dropoff-uuid',
            'return_uuid'     => 'return-uuid',
            'entities_count'  => 1,
            'waypoints_count' => 1,
            'type'            => 'parcel',
        ])
        ->and((new IndexPlaceResource($place))->resolve($request))->toMatchArray([
            'id'           => 101,
            'uuid'         => 'fixture-uuid',
            'public_id'    => 'fixture_public',
            'company_uuid' => 'company-uuid',
            'owner_uuid'   => 'owner-uuid',
            'owner_type'   => 'Fleetbase\\FleetOps\\Models\\Contact',
            'name'         => 'Warehouse',
            'address'      => '1 Index Way',
            'street1'      => '1 Index Way',
            'city'         => 'Singapore',
            'country'      => 'SG',
            'avatar_url'   => 'https://cdn.test/place.png',
            'meta'         => ['_index_resource' => true],
        ]);
});

test('deleted resource marks internal and webhook payloads as deleted', function () {
    $request = fleetopsCompactResourceRequest(true);
    $model   = new class(['id' => 101, 'uuid' => 'fixture-uuid', 'public_id' => 'fixture_public', 'deleted_at' => '2026-07-01 00:00:00']) extends FleetOpsCompactResourceFixture {
    };

    $resource = new DeletedResource($model);
    $object   = $resource->getObjectType();

    expect($resource->resolve($request))->toMatchArray([
        'id'        => 101,
        'uuid'      => 'fixture-uuid',
        'public_id' => 'fixture_public',
        'object'    => $object,
        'time'      => '2026-07-01 00:00:00',
        'deleted'   => true,
    ])
        ->and($resource->toWebhookPayload())->toMatchArray([
            'id'      => 'fixture_public',
            'object'  => $object,
            'time'    => '2026-07-01 00:00:00',
            'deleted' => true,
        ])
        ->and($object)->toBeString()->not->toBe('');
});

test('quote proof current job and tiny index resources serialize simple payloads', function () {
    $request = fleetopsCompactResourceRequest(true);

    $quoteItem = fleetopsCompactResourceFixture([
        'service_quote_uuid' => 'quote-uuid',
        'amount'             => 7.5,
        'currency'           => 'SGD',
        'details'            => 'Handling fee',
        'code'               => 'handling',
    ]);
    $quote = fleetopsCompactResourceFixture([
        'service_rate_uuid' => 'rate-uuid',
        'payload_uuid'      => 'payload-uuid',
        'serviceRate'       => (object) ['service_name' => 'Same Day', 'public_id' => 'rate_public'],
        'integratedVendor'  => (object) ['public_id' => 'vendor_public'],
        'items'             => new Collection([$quoteItem]),
        'request_id'        => 'REQ-101',
        'amount'            => 25.5,
        'currency'          => 'SGD',
        'meta'              => ['quoted' => true],
    ]);
    $currentJob = fleetopsCompactResourceFixture([
        'company_uuid' => 'company-uuid',
        'internal_id'  => 'ORD-101',
        'payload'      => null,
        'type'         => 'transport',
        'status'       => 'dispatched',
        'meta'         => ['priority' => 'high'],
    ]);
    $proof = fleetopsCompactResourceFixture([
        'subject'  => (object) ['public_id' => 'subject_public'],
        'order'    => (object) ['public_id' => 'order_public'],
        'file_url' => 'https://cdn.test/proof.png',
        'remarks'  => 'Delivered at reception.',
        'raw_data' => ['signature' => true],
        'data'     => ['name' => 'Receiver'],
    ]);
    $person = fleetopsCompactResourceFixture([
        'company_uuid' => 'company-uuid',
        'name'         => 'Compact Person',
        'phone'        => '+6555554444',
        'email'        => 'person@example.test',
    ]);
    $trackingNumber = fleetopsCompactResourceFixture([
        'tracking_number' => 'TN-101',
        'qr_code'         => 'qr-data',
    ]);
    $internalConfig = fleetopsCompactResourceFixture([
        'company_uuid'  => 'company-uuid',
        'author_uuid'   => 'author-uuid',
        'category_uuid' => 'category-uuid',
        'icon_uuid'     => 'icon-uuid',
        'name'          => 'Delivery',
        'namespace'     => 'fleet-ops',
        'description'   => 'Delivery config.',
        'key'           => 'delivery',
        'status'        => 'active',
        'version'       => 3,
        'core_service'  => 1,
        'flow'          => [['code' => 'created']],
        'entities'      => [['type' => 'parcel']],
        'tags'          => ['last-mile'],
        'meta'          => ['default' => true],
        'deleted_at'    => null,
    ]);

    expect((new ServiceQuoteItemResource($quoteItem))->resolve($request))->toMatchArray([
        'id'                 => 101,
        'uuid'               => 'fixture-uuid',
        'service_quote_uuid' => 'quote-uuid',
        'amount'             => 7.5,
        'currency'           => 'SGD',
        'details'            => 'Handling fee',
        'code'               => 'handling',
    ])
        ->and((new ServiceQuoteResource($quote))->resolve($request))->toMatchArray([
            'id'                => 101,
            'uuid'              => 'fixture-uuid',
            'public_id'         => 'fixture_public',
            'service_rate_uuid' => 'rate-uuid',
            'payload_uuid'      => 'payload-uuid',
            'service_rate_name' => 'Same Day',
            'service_name'      => 'Same Day',
            'request_id'        => 'REQ-101',
            'amount'            => 25.5,
            'currency'          => 'SGD',
            'meta'              => ['quoted' => true],
        ])
        ->and((new ServiceQuoteResource($quote))->toWebhookPayload())->toMatchArray([
            'id'           => 'fixture_public',
            'service_rate' => 'rate_public',
            'facilitator'  => 'vendor_public',
            'request_id'   => 'REQ-101',
            'amount'       => 25.5,
            'currency'     => 'SGD',
        ])
        ->and((new CurrentJobResource($currentJob))->resolve($request))->toMatchArray([
            'id'           => 101,
            'uuid'         => 'fixture-uuid',
            'public_id'    => 'fixture_public',
            'company_uuid' => 'company-uuid',
            'internal_id'  => 'ORD-101',
            'type'         => 'transport',
            'status'       => 'dispatched',
            'meta'         => ['priority' => 'high'],
        ])
        ->and((new ProofResource($proof))->resolve($request))->toMatchArray([
            'id'         => 101,
            'uuid'       => 'fixture-uuid',
            'public_id'  => 'fixture_public',
            'subject_id' => 'subject_public',
            'order_id'   => 'order_public',
            'url'        => 'https://cdn.test/proof.png',
            'remarks'    => 'Delivered at reception.',
            'raw'        => ['signature' => true],
            'data'       => ['name' => 'Receiver'],
        ])
        ->and((new IndexCustomerResource($person))->resolve($request))->toMatchArray([
            'id'           => 101,
            'uuid'         => 'fixture-uuid',
            'public_id'    => 'fixture_public',
            'company_uuid' => 'company-uuid',
            'name'         => 'Compact Person',
            'phone'        => '+6555554444',
            'email'        => 'person@example.test',
        ])
        ->and((new IndexFacilitatorResource($person))->resolve($request))->toMatchArray([
            'id'           => 101,
            'uuid'         => 'fixture-uuid',
            'public_id'    => 'fixture_public',
            'company_uuid' => 'company-uuid',
            'name'         => 'Compact Person',
            'phone'        => '+6555554444',
            'email'        => 'person@example.test',
        ])
        ->and((new IndexTrackingNumberResource($trackingNumber))->resolve($request))->toMatchArray([
            'id'              => 101,
            'uuid'            => 'fixture-uuid',
            'tracking_number' => 'TN-101',
            'qr_code'         => 'qr-data',
        ])
        ->and((new InternalOrderConfigResource($internalConfig))->resolve($request))->toMatchArray([
            'id'            => 101,
            'uuid'          => 'fixture-uuid',
            'public_id'     => 'fixture_public',
            'company_uuid'  => 'company-uuid',
            'author_uuid'   => 'author-uuid',
            'category_uuid' => 'category-uuid',
            'icon_uuid'     => 'icon-uuid',
            'name'          => 'Delivery',
            'namespace'     => 'fleet-ops',
            'description'   => 'Delivery config.',
            'key'           => 'delivery',
            'status'        => 'active',
            'version'       => 3,
            'core_service'  => true,
            'flow'          => [['code' => 'created']],
            'entities'      => [['type' => 'parcel']],
            'tags'          => ['last-mile'],
            'meta'          => ['default' => true],
            'type'          => 'order-config',
        ]);
});

test('orchestrator order resource serializes loaded workbench relationships', function () {
    $request = fleetopsCompactResourceRequest(true);

    $customFieldValue = fleetopsCompactResourceFixture([
        'uuid'              => 'custom-value-uuid',
        'custom_field_uuid' => 'custom-field-uuid',
        'value'             => 'dock-door-4',
        'value_type'        => 'string',
    ], [
        'customField' => fleetopsCompactResourceFixture([
            'uuid'     => 'custom-field-uuid',
            'name'     => 'dock_door',
            'label'    => 'Dock Door',
            'type'     => 'text',
            'required' => 1,
        ]),
    ]);
    $order = fleetopsCompactResourceFixture([
        'internal_id'            => 'ORCH-101',
        'company_uuid'           => 'company-uuid',
        'payload_uuid'           => 'payload-uuid',
        'order_config_uuid'      => 'config-uuid',
        'driver_assigned_uuid'   => 'driver-uuid',
        'vehicle_assigned_uuid'  => 'vehicle-uuid',
        'trackingNumber'         => (object) ['tracking_number' => 'TN-ORCH'],
        'type'                   => 'transport',
        'status'                 => 'assigned',
        'notes'                  => 'Workbench card.',
        'adhoc'                  => 1,
        'dispatched'             => 1,
        'has_driver_assigned'    => true,
        'is_scheduled'           => true,
        'orchestrator_priority'  => 7,
        'time_window_start'      => '09:00',
        'time_window_end'        => '11:00',
        'required_skills'        => ['liftgate'],
        'scheduled_at'           => '2026-07-28 09:00:00',
        'dispatched_at'          => '2026-07-28 08:45:00',
        'started_at'             => null,
        'meta'                   => ['lane' => 'north'],
    ], [
        'orderConfig' => fleetopsCompactResourceFixture([
            'public_id' => 'config_public',
            'name'      => 'Delivery',
            'key'       => 'delivery',
        ]),
        'payload' => fleetopsCompactResourceFixture([
            'company_uuid'          => 'company-uuid',
            'pickup_uuid'           => 'pickup-uuid',
            'dropoff_uuid'          => 'dropoff-uuid',
            'return_uuid'           => 'return-uuid',
            'index_pickup_place'    => null,
            'index_dropoff_place'   => null,
            'type'                  => 'parcel',
        ], [
            'entities'  => new Collection([(object) ['public_id' => 'entity_public']]),
            'waypoints' => new Collection([(object) ['public_id' => 'waypoint_public']]),
        ]),
        'driverAssigned' => fleetopsCompactResourceFixture([
            'company_uuid'      => 'company-uuid',
            'user_uuid'         => 'user-uuid',
            'vehicle_uuid'      => 'vehicle-uuid',
            'vendor_uuid'       => 'vendor-uuid',
            'current_job_uuid'  => 'order-uuid',
            'name'              => 'Jane Driver',
            'vehicle_name'      => 'Truck 101',
            'email'             => 'jane@example.test',
            'status'            => 'on_duty',
            'location'          => null,
            'heading'           => 180,
            'altitude'          => 12,
            'speed'             => 42,
            'online'            => true,
        ]),
        'vehicleAssigned' => fleetopsCompactResourceFixture([
            'company_uuid'     => 'company-uuid',
            'vendor_uuid'      => 'vendor-uuid',
            'photo_uuid'       => 'photo-uuid',
            'internal_id'      => 'VEH-ORCH',
            'display_name'     => 'Orchestrator Truck',
            'driver_name'      => 'Jane Driver',
            'plate_number'     => 'ORCH-101',
            'serial_number'    => 'SER-ORCH',
            'fuel_card_number' => 'FUEL-ORCH',
            'vin'              => 'VIN-ORCH',
            'make'             => 'Ford',
            'model'            => 'Transit',
            'year'             => 2026,
            'photo_url'        => 'https://cdn.test/orch-truck.png',
            'status'           => 'assigned',
            'location'         => null,
            'heading'          => 90,
            'altitude'         => 10,
            'speed'            => 38,
            'online'           => true,
        ]),
        'customFieldValues' => new Collection([$customFieldValue]),
    ]);

    $payload = (new OrchestratorOrderResource($order))->resolve($request);

    expect($payload)->toMatchArray([
        'id'                    => 101,
        'uuid'                  => 'fixture-uuid',
        'public_id'             => 'fixture_public',
        'internal_id'           => 'ORCH-101',
        'company_uuid'          => 'company-uuid',
        'payload_uuid'          => 'payload-uuid',
        'order_config_uuid'     => 'config-uuid',
        'driver_assigned_uuid'  => 'driver-uuid',
        'vehicle_assigned_uuid' => 'vehicle-uuid',
        'tracking'              => 'TN-ORCH',
        'order_config'          => [
            'id'   => 'config_public',
            'name' => 'Delivery',
            'key'  => 'delivery',
        ],
        'type'                  => 'transport',
        'status'                => 'assigned',
        'notes'                 => 'Workbench card.',
        'adhoc'                 => true,
        'dispatched'            => true,
        'has_driver_assigned'   => true,
        'is_scheduled'          => true,
        'orchestrator_priority' => 7,
        'time_window_start'     => '09:00',
        'time_window_end'       => '11:00',
        'required_skills'       => ['liftgate'],
        'meta'                  => ['lane' => 'north'],
    ])
        ->and($payload['payload']->resolve($request))->toMatchArray([
            'id'              => 101,
            'uuid'            => 'fixture-uuid',
            'public_id'       => 'fixture_public',
            'company_uuid'    => 'company-uuid',
            'pickup_uuid'     => 'pickup-uuid',
            'dropoff_uuid'    => 'dropoff-uuid',
            'return_uuid'     => 'return-uuid',
            'entities_count'  => 1,
            'waypoints_count' => 1,
            'type'            => 'parcel',
        ])
        ->and($payload['custom_field_values']->all())->toMatchArray([
            [
                'id'                => 'custom-value-uuid',
                'uuid'              => 'custom-value-uuid',
                'custom_field_uuid' => 'custom-field-uuid',
                'value'             => 'dock-door-4',
                'value_type'        => 'string',
                'custom_field'      => [
                    'id'       => 'custom-field-uuid',
                    'uuid'     => 'custom-field-uuid',
                    'name'     => 'dock_door',
                    'label'    => 'Dock Door',
                    'type'     => 'text',
                    'required' => true,
                ],
            ],
        ]);
});

test('internal vehicle vendor and waypoint resources expose internal compact fields', function () {
    $request = fleetopsCompactResourceRequest(true);

    if (!Arr::hasMacro('insertAfterKey')) {
        Arr::macro('insertAfterKey', function (array $array, array $insert, string $afterKey): array {
            $offset = array_search($afterKey, array_keys($array), true);

            if ($offset === false) {
                return array_merge($array, $insert);
            }

            return array_slice($array, 0, $offset + 1, true) + $insert + array_slice($array, $offset + 1, null, true);
        });
    }

    $vehicle = fleetopsCompactResourceFixture([
        'internal_id'        => 'VEH-INTERNAL',
        'company_uuid'       => 'company-uuid',
        'vendor_uuid'        => 'vendor-uuid',
        'category_uuid'      => 'category-uuid',
        'warranty_uuid'      => 'warranty-uuid',
        'telematic_uuid'     => 'telematic-uuid',
        'photo_uuid'         => 'photo-uuid',
        'photo_url'          => 'https://cdn.test/vehicle.png',
        'avatar_url'         => 'https://cdn.test/avatar.png',
        'name'               => 'Internal Vehicle',
        'display_name'       => 'Internal Van',
        'driver_name'        => 'Jane Driver',
        'vendor_name'        => 'Vendor One',
        'description'        => 'Internal fleet vehicle.',
        'make'               => 'Ford',
        'model'              => 'Transit',
        'year'               => 2026,
        'plate_number'       => 'INT-101',
        'serial_number'      => 'SER-INT',
        'fuel_card_number'   => 'FUEL-INT',
        'vin'                => 'VIN-INT',
        'status'             => 'available',
        'online'             => true,
        'location'           => null,
        'heading'            => 15,
        'altitude'           => 4,
        'speed'              => 18,
        'measurement_system' => 'metric',
        'odometer'           => 1200,
        'odometer_unit'      => 'km',
        'fuel_type'          => 'diesel',
        'currency'           => 'SGD',
        'meta'               => ['yard' => 'east'],
        'notes'              => 'Washed weekly.',
    ], [
        'devices' => new Collection([(object) ['uuid' => 'device-uuid']]),
    ]);
    $vendor = fleetopsCompactResourceFixture([
        'name'      => 'Fuel Vendor',
        'photo_url' => 'https://cdn.test/vendor.png',
        'provider'  => 'shell',
        'options'   => ['region' => 'sg'],
        'sandbox'   => true,
        'type'      => 'fuel',
        'status'    => 'active',
    ]);
    $place = fleetopsCompactResourceFixture([
        'company_uuid' => 'company-uuid',
        'owner_uuid'   => 'owner-uuid',
        'owner_type'   => 'Fleetbase\\FleetOps\\Models\\Contact',
        'name'         => 'Waypoint Place',
        'address'      => '12 Route Way',
        'street1'      => '12 Route Way',
        'city'         => 'Singapore',
        'country'      => 'SG',
        'avatar_url'   => 'https://cdn.test/waypoint.png',
        'location'     => null,
        'meta'         => ['dock' => 'A'],
    ]);
    $waypoint = fleetopsCompactResourceFixture([
        'place'                => $place,
        'tracking_number_uuid' => 'tracking-uuid',
        'tracking'             => 'TN-WAYPOINT',
        'status'               => 'arrived',
        'status_code'          => 'arrived',
    ]);

    expect((new InternalVehicleResource($vehicle))->resolve($request))->toMatchArray([
        'id'                 => 101,
        'uuid'               => 'fixture-uuid',
        'public_id'          => 'fixture_public',
        'internal_id'        => 'VEH-INTERNAL',
        'company_uuid'       => 'company-uuid',
        'vendor_uuid'        => 'vendor-uuid',
        'category_uuid'      => 'category-uuid',
        'warranty_uuid'      => 'warranty-uuid',
        'telematic_uuid'     => 'telematic-uuid',
        'photo_uuid'         => 'photo-uuid',
        'display_name'       => 'Internal Van',
        'driver_name'        => 'Jane Driver',
        'vendor_name'        => 'Vendor One',
        'make'               => 'Ford',
        'model'              => 'Transit',
        'year'               => 2026,
        'plate_number'       => 'INT-101',
        'status'             => 'available',
        'online'             => true,
        'measurement_system' => 'metric',
        'odometer'           => 1200,
        'fuel_type'          => 'diesel',
        'currency'           => 'SGD',
        'heading'            => 15,
        'altitude'           => 4,
        'speed'              => 18,
        'meta'               => ['yard' => 'east'],
    ])
        ->and((new VehicleWithoutDriverResource($vehicle))->toWebhookPayload())->toMatchArray([
            'id'                 => 'fixture_public',
            'internal_id'        => 'VEH-INTERNAL',
            'name'               => 'Internal Vehicle',
            'display_name'       => 'Internal Van',
            'description'        => 'Internal fleet vehicle.',
            'vin'                => 'VIN-INT',
            'plate_number'       => 'INT-101',
            'serial_number'      => 'SER-INT',
            'fuel_card_number'   => 'FUEL-INT',
            'make'               => 'Ford',
            'model'              => 'Transit',
            'year'               => 2026,
            'photo_url'          => 'https://cdn.test/vehicle.png',
            'avatar_url'         => 'https://cdn.test/avatar.png',
            'status'             => 'available',
            'online'             => true,
            'measurement_system' => 'metric',
            'odometer'           => 1200,
            'odometer_unit'      => 'km',
            'fuel_type'          => 'diesel',
            'currency'           => 'SGD',
            'heading'            => 15,
            'altitude'           => 4,
            'speed'              => 18,
            'notes'              => 'Washed weekly.',
            'meta'               => ['yard' => 'east'],
        ])
        ->and((new InternalIntegratedVendorFacilitatorResource($vendor))->resolve($request))->toMatchArray([
            'id'               => 101,
            'uuid'             => 'fixture-uuid',
            'public_id'        => 'fixture_public',
            'name'             => 'Fuel Vendor',
            'photo_url'        => 'https://cdn.test/vendor.png',
            'provider'         => 'shell',
            'options'          => ['region' => 'sg'],
            'sandbox'          => true,
            'facilitator_type' => 'fuel',
            'type'             => 'facilitator',
            'status'           => 'active',
        ])
        ->and((new InternalWaypointResource($waypoint))->resolve($request))->toMatchArray([
            'uuid'                 => 'fixture-uuid',
            'waypoint_uuid'        => 'fixture-uuid',
            'waypoint_public_id'   => 'fixture_public',
            'tracking_number_uuid' => 'tracking-uuid',
            'tracking'             => 'TN-WAYPOINT',
            'status'               => 'arrived',
            'status_code'          => 'arrived',
            'name'                 => 'Waypoint Place',
            'address'              => '12 Route Way',
            'city'                 => 'Singapore',
            'country'              => 'SG',
            'meta'                 => ['dock' => 'A'],
        ]);
});

test('tracking number and waypoint webhook resources serialize tracking contracts', function () {
    $trackingNumber = fleetopsCompactResourceFixture([
        'status_uuid'      => 'status-uuid',
        'owner_uuid'       => 'owner-uuid',
        'owner_type'       => 'Fleetbase\\FleetOps\\Models\\Order',
        'owner'            => (object) ['public_id' => 'order_public'],
        'tracking_number'  => 'TN-ZERO',
        'region'           => 'sg',
        'last_status'      => 'Created',
        'last_status_code' => 'created',
        'qr_code'          => 'qr-data',
        'barcode'          => 'barcode-data',
    ]);
    $waypoint = fleetopsCompactResourceFixture([
        'internal_id'     => 'WAYPOINT-101',
        'name'            => 'Waypoint Stop',
        'type'            => 'dropoff',
        'destination'     => (object) ['public_id' => 'place_public'],
        'customer_type'   => null,
        'customer_uuid'   => null,
        'trackingNumber'  => $trackingNumber,
        'description'     => 'Dropoff at reception.',
        'photo_url'       => 'https://cdn.test/waypoint-proof.png',
        'length'          => 10,
        'width'           => 8,
        'height'          => 6,
        'dimensions_unit' => 'cm',
        'weight'          => 2.5,
        'weight_unit'     => 'kg',
        'declared_value'  => 125,
        'price'           => 140,
        'sale_price'      => 120,
        'sku'             => 'WAY-SKU',
        'currency'        => 'SGD',
        'meta'            => ['sequence' => 2],
    ]);
    $order = fleetopsCompactResourceFixture([
        'internal_id'        => 'ORDER-WEBHOOK',
        'customer'           => null,
        'payload'            => null,
        'facilitator'        => null,
        'driverAssigned'     => null,
        'trackingNumber'     => $trackingNumber,
        'purchaseRate'       => null,
        'notes'              => 'Webhook order.',
        'type'               => 'transport',
        'status'             => 'created',
        'adhoc'              => true,
        'meta'               => ['channel' => 'api'],
        'dispatched_at'      => null,
        'started_at'         => null,
        'scheduled_at'       => '2026-07-30 09:00:00',
    ]);

    // The decoded QR content is a debug-only field and must never ride along on a webhook,
    // which is a separate literal payload rather than a filtered toArray().
    expect(array_key_exists('qr_code_content', (new TrackingNumberResource($trackingNumber))->toWebhookPayload()))->toBeFalse();

    expect((new TrackingNumberResource($trackingNumber))->toWebhookPayload())->toMatchArray([
        'id'              => 'fixture_public',
        'tracking_number' => 'TN-ZERO',
        'subject'         => 'order_public',
        'region'          => 'sg',
        'qr_code'         => 'qr-data',
        'barcode'         => 'barcode-data',
        'type'            => 'order',
    ])
        ->and((new OrderResource($order))->toWebhookPayload())->toMatchArray([
            'id'             => 'fixture_public',
            'internal_id'    => 'ORDER-WEBHOOK',
            'customer'       => null,
            'facilitator'    => null,
            'notes'          => 'Webhook order.',
            'type'           => 'transport',
            'status'         => 'created',
            'adhoc'          => true,
            'meta'           => ['channel' => 'api'],
            'dispatched_at'  => null,
            'started_at'     => null,
            'scheduled_at'   => '2026-07-30 09:00:00',
        ])
        ->and((new WaypointResource($waypoint))->toWebhookPayload())->toMatchArray([
            'id'              => 'fixture_public',
            'internal_id'     => 'WAYPOINT-101',
            'name'            => 'Waypoint Stop',
            'type'            => 'dropoff',
            'destination'     => 'place_public',
            'customer'        => null,
            'description'     => 'Dropoff at reception.',
            'photo_url'       => 'https://cdn.test/waypoint-proof.png',
            'length'          => 10,
            'width'           => 8,
            'height'          => 6,
            'dimensions_unit' => 'cm',
            'weight'          => 2.5,
            'weight_unit'     => 'kg',
            'declared_value'  => 125,
            'price'           => 140,
            'sale_price'      => 120,
            'sku'             => 'WAY-SKU',
            'currency'        => 'SGD',
            'meta'            => ['sequence' => 2],
        ]);
});

test('tracking number resource publishes the qr code content only in debug mode', function () {
    // qr_code is a base64 PNG generated from owner_uuid, and the endpoints that consume a
    // scanned code match on that uuid. Debug builds publish the value beside the image so
    // an automated client can follow the flow without decoding a PNG; production must not.
    $trackingNumber = fleetopsCompactResourceFixture([
        'tracking_number'  => 'TN-DEBUG',
        'owner_uuid'       => 'owner-uuid-under-the-qr',
        'owner_type'       => 'Fleetbase\\FleetOps\\Models\\Order',
        'region'           => 'sg',
        'qr_code'          => 'qr-data',
        'barcode'          => 'barcode-data',
        'last_status'      => 'created',
        'last_status_code' => 'CREATED',
    ]);
    $request = Request::create('/v1/tracking-numbers', 'GET');

    // The full resource builds a console url, which needs app()->environment(). The bare
    // container this suite runs on has no such method, which is why the tests above only
    // cover the index resource and the webhook payload.
    $previousContainer = Illuminate\Container\Container::getInstance();
    $container         = new class extends Illuminate\Container\Container {
        public bool $debugMode = false;

        public function environment(...$environments)
        {
            return in_array('testing', $environments, true) || $environments === [] ? 'testing' : false;
        }

        // Stands in for the framework's Application::hasDebugModeEnabled().
        public function hasDebugModeEnabled()
        {
            return $this->debugMode;
        }
    };
    $container->instance('config', new Illuminate\Config\Repository([
        'fleetbase' => ['console' => ['host' => 'console.fleetbase.test', 'subdomain' => null, 'secure' => true]],
    ]));
    Illuminate\Container\Container::setInstance($container);

    // Drive the REAL accessor through the container rather than overriding it, so the
    // debug check itself is exercised and not just the resource's use of it.
    $container->debugMode = true;
    $withDebug            = new TrackingNumberResource($trackingNumber);

    try {
        $debugPayload = $withDebug->resolve($request);

        $container->debugMode = false;
        $productionPayload    = (new TrackingNumberResource($trackingNumber))->resolve($request);
    } finally {
        Illuminate\Container\Container::setInstance($previousContainer);
    }

    // Present and exactly the value the QR was generated from — not a public id.
    expect($debugPayload['qr_code_content'])->toBe('owner-uuid-under-the-qr')
        ->and($debugPayload['qr_code'])->toBe('qr-data')
        // Absent, not null: `when()` drops the key entirely, so a production response is
        // byte-identical to one from before the field existed.
        ->and(array_key_exists('qr_code_content', $productionPayload))->toBeFalse()
        ->and($productionPayload['qr_code'])->toBe('qr-data');
});

test('tracking number resource fails closed when the debug state cannot be determined', function () {
    // Any failure to resolve the debug state must answer false rather than defaulting to
    // exposure. The real accessor is exercised here with no usable application bound.
    $expose = new ReflectionMethod(TrackingNumberResource::class, 'exposesQrCodeContent');
    $expose->setAccessible(true);

    $previous = Illuminate\Container\Container::getInstance();

    try {
        // A container that is not an Application has no hasDebugModeEnabled().
        Illuminate\Container\Container::setInstance(new Illuminate\Container\Container());
        expect($expose->invoke(null))->toBeFalse();

        // And an accessor that throws must also answer false rather than propagating —
        // a resource must not be able to fail serialization over a debug check.
        Illuminate\Container\Container::setInstance(new class extends Illuminate\Container\Container {
            public function hasDebugModeEnabled()
            {
                throw new RuntimeException('debug state unavailable');
            }
        });
        expect($expose->invoke(null))->toBeFalse();
    } finally {
        Illuminate\Container\Container::setInstance($previous);
    }
});
