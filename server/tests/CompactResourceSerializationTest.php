<?php

use Fleetbase\FleetOps\Http\Resources\v1\Contact as ContactResource;
use Fleetbase\FleetOps\Http\Resources\v1\Device as DeviceResource;
use Fleetbase\FleetOps\Http\Resources\v1\Entity as EntityResource;
use Fleetbase\FleetOps\Http\Resources\v1\Fleet as FleetResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Order as IndexOrderResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Vehicle as IndexVehicleResource;
use Fleetbase\FleetOps\Http\Resources\v1\Issue as IssueResource;
use Fleetbase\FleetOps\Http\Resources\v1\Maintenance as MaintenanceResource;
use Fleetbase\FleetOps\Http\Resources\v1\MaintenanceSchedule as MaintenanceScheduleResource;
use Fleetbase\FleetOps\Http\Resources\v1\OrderConfig as OrderConfigResource;
use Fleetbase\FleetOps\Http\Resources\v1\ParentFleet as ParentFleetResource;
use Fleetbase\FleetOps\Http\Resources\v1\Place as PlaceResource;
use Fleetbase\FleetOps\Http\Resources\v1\PurchaseRate as PurchaseRateResource;
use Fleetbase\FleetOps\Http\Resources\v1\ServiceArea as ServiceAreaResource;
use Fleetbase\FleetOps\Http\Resources\v1\ServiceRate as ServiceRateResource;
use Fleetbase\FleetOps\Http\Resources\v1\SubFleet as SubFleetResource;
use Fleetbase\FleetOps\Http\Resources\v1\TrackingStatus as TrackingStatusResource;
use Fleetbase\FleetOps\Http\Resources\v1\Vendor as VendorResource;
use Fleetbase\FleetOps\Http\Resources\v1\Zone as ZoneResource;
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
