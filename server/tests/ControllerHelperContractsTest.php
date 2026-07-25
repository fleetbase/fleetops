<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\ContactController as ApiContactController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\CustomerController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\EntityController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController as ApiOrderController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\PayloadController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\PurchaseRateController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceAreaController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceRateController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\ZoneController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ContactController as InternalContactController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\DriverController as InternalDriverController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController as InternalOrderController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\PositionController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

class FleetOpsInternalOrderControllerProbe extends InternalOrderController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(InternalOrderController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    public function appendValues(mixed $value): array
    {
        $incoming = [];
        $this->appendProofPhotoInputs($incoming, $value);

        return $incoming;
    }
}

class FleetOpsInternalDriverControllerProbe extends InternalDriverController
{
    public function normalizeVehicleInput(Request $request, array &$input): void
    {
        $reflection = new ReflectionMethod(InternalDriverController::class, 'normalizeDriverVehicleInput');
        $reflection->setAccessible(true);

        $reflection->invokeArgs($this, [$request, &$input]);
    }
}

class FleetOpsEntityControllerProbe extends EntityController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(EntityController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsApiOrderControllerProbe extends ApiOrderController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ApiOrderController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsApiContactControllerProbe extends ApiContactController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ApiContactController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsPayloadControllerProbe extends PayloadController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(PayloadController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsPurchaseRateControllerProbe extends PurchaseRateController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(PurchaseRateController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsServiceAreaControllerProbe extends ServiceAreaController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ServiceAreaController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsServiceRateControllerProbe extends ServiceRateController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ServiceRateController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsZoneControllerProbe extends ZoneController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ZoneController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsInternalContactControllerProbe extends InternalContactController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(InternalContactController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsControllerStaticMethod(string $class, string $method): ReflectionMethod
{
    $reflection = new ReflectionMethod($class, $method);
    $reflection->setAccessible(true);

    return $reflection;
}

test('internal order controller collects proof photo upload inputs', function () {
    $controller = new FleetOpsInternalOrderControllerProbe();
    $tempPath   = tempnam(sys_get_temp_dir(), 'fleetops-proof-');
    file_put_contents($tempPath, 'uploaded image');

    $upload  = new UploadedFile($tempPath, 'proof.png', 'image/png', null, true);
    $request = new Request(
        [],
        [],
        [],
        [],
        [
            'photos' => [$upload],
        ]
    );

    $inputs = $controller->callHelper('collectProofPhotoInputs', $request);

    expect($inputs)->toHaveCount(1)
        ->and($inputs[0])->toBe($upload);
});

test('internal order controller fingerprints and validates proof photo payloads', function () {
    $controller = new FleetOpsInternalOrderControllerProbe();
    $payload    = base64_encode('same image');
    $other      = base64_encode('other image');
    $tempPath   = tempnam(sys_get_temp_dir(), 'fleetops-proof-');
    file_put_contents($tempPath, 'uploaded image');

    $upload = new UploadedFile($tempPath, 'proof.png', 'image/png', null, true);
    $nested = $controller->appendValues([
        'data:image/png;base64,' . $payload,
        [$payload, new stdClass(), null, $other],
    ]);
    $deduped = $controller->callHelper('dedupeProofPhotoInputs', $nested);

    expect($controller->callHelper('proofPhotoInputFingerprint', 'data:image/png;base64,' . $payload))
        ->toBe($controller->callHelper('proofPhotoInputFingerprint', $payload))
        ->and($nested)->toHaveCount(4)
        ->and($deduped)->toBe(['data:image/png;base64,' . $payload, $other])
        ->and($controller->callHelper('proofPhotoInputFingerprint', $upload))->toBeString()
        ->and($controller->callHelper('proofPhotoInputFingerprint', new stdClass()))->toBeNull()
        ->and($controller->callHelper('isValidBase64ProofPhoto', 'data:image/png;base64,' . $payload))->toBeTrue()
        ->and($controller->callHelper('isValidBase64ProofPhoto', 'not base64 !!'))->toBeFalse()
        ->and($controller->callHelper('isValidBase64ProofPhoto', $upload))->toBeFalse();
});

test('internal driver controller normalizes nested vehicle input before validation', function (array $vehicle, ?string $expected) {
    $controller = new FleetOpsInternalDriverControllerProbe();
    $input      = ['vehicle' => $vehicle, 'name' => 'Ada Driver'];
    $request    = new Request(['driver' => $input]);

    $controller->normalizeVehicleInput($request, $input);

    expect($input['vehicle'])->toBe($expected)
        ->and($request->input('driver.vehicle'))->toBe($expected)
        ->and($request->input('driver.name'))->toBe('Ada Driver');
})->with([
    'id wins'        => [['id' => 'vehicle-public', 'public_id' => 'vehicle-public-id', 'uuid' => 'vehicle-uuid'], 'vehicle-public'],
    'public id next' => [['public_id' => 'vehicle-public-id', 'uuid' => 'vehicle-uuid'], 'vehicle-public-id'],
    'uuid fallback'  => [['uuid' => 'vehicle-uuid'], 'vehicle-uuid'],
    'empty object'   => [['make' => 'Test'], null],
]);

test('internal driver controller leaves scalar or missing vehicle input unchanged', function (array $payload) {
    $controller = new FleetOpsInternalDriverControllerProbe();
    $input      = $payload;
    $request    = new Request(['driver' => $input]);

    $controller->normalizeVehicleInput($request, $input);

    expect($input)->toBe($payload)
        ->and($request->input('driver'))->toBe($payload);
})->with([
    'scalar vehicle'  => [['vehicle' => 'vehicle-public', 'name' => 'Ada Driver']],
    'missing vehicle' => [['name' => 'Ada Driver']],
]);

test('entity controller request input keeps only entity attributes', function () {
    $controller = new FleetOpsEntityControllerProbe();
    $request    = new Request([
        'name'             => 'Pallet',
        'type'             => 'cargo',
        'internal_id'      => 'SKU-1',
        'description'      => 'Fragile pallet',
        'meta'             => ['temperature' => 'ambient'],
        'length'           => 4,
        'width'            => 3,
        'height'           => 2,
        'weight'           => 40,
        'weight_unit'      => 'kg',
        'dimensions_unit'  => 'm',
        'declared_value'   => 1000,
        'price'            => 50,
        'sales_price'      => 75,
        'sku'              => 'PALLET-1',
        'currency'         => 'SGD',
        'supplier_uuid'    => 'supplier-uuid',
        'payload'          => 'payload-public',
        'customer'         => 'customer-public',
        'driver'           => 'driver-public',
        'company_uuid'     => 'spoofed-company',
        'destination_uuid' => 'spoofed-destination',
    ]);

    expect($controller->callHelper('entityInputFromRequest', $request))->toBe([
        'name'            => 'Pallet',
        'type'            => 'cargo',
        'internal_id'     => 'SKU-1',
        'description'     => 'Fragile pallet',
        'meta'            => ['temperature' => 'ambient'],
        'length'          => 4,
        'width'           => 3,
        'height'          => 2,
        'weight'          => 40,
        'weight_unit'     => 'kg',
        'dimensions_unit' => 'm',
        'declared_value'  => 1000,
        'price'           => 50,
        'sales_price'     => 75,
        'sku'             => 'PALLET-1',
        'currency'        => 'SGD',
        'supplier_uuid'   => 'supplier-uuid',
    ]);
});

test('api order controller request input separates create and update contracts', function () {
    $controller = new FleetOpsApiOrderControllerProbe();
    $request    = new Request([
        'internal_id'           => 'ORD-1',
        'payload'               => 'payload-public',
        'service_quote'         => 'quote-public',
        'purchase_rate'         => 'purchase-rate-public',
        'adhoc'                 => true,
        'adhoc_distance'        => 1200,
        'pod_method'            => 'scan',
        'pod_required'          => true,
        'scheduled_at'          => '2026-01-01T10:00:00Z',
        'status'                => 'created',
        'type'                  => 'transport',
        'meta'                  => ['source' => 'api'],
        'notes'                 => 'Handle with care',
        'time_window_start'     => '09:00',
        'time_window_end'       => '17:00',
        'required_skills'       => ['hazmat'],
        'orchestrator_priority' => 75,
        'driver'                => 'driver-public',
        'company_uuid'          => 'spoofed-company',
    ]);

    expect($controller->callHelper('orderCreateInputFromRequest', $request))->toBe([
        'internal_id'           => 'ORD-1',
        'payload'               => 'payload-public',
        'service_quote'         => 'quote-public',
        'purchase_rate'         => 'purchase-rate-public',
        'adhoc'                 => true,
        'adhoc_distance'        => 1200,
        'pod_method'            => 'scan',
        'pod_required'          => true,
        'scheduled_at'          => '2026-01-01T10:00:00Z',
        'status'                => 'created',
        'meta'                  => ['source' => 'api'],
        'notes'                 => 'Handle with care',
        'time_window_start'     => '09:00',
        'time_window_end'       => '17:00',
        'required_skills'       => ['hazmat'],
        'orchestrator_priority' => 75,
    ])->and($controller->callHelper('orderUpdateInputFromRequest', $request))->toBe([
        'internal_id'           => 'ORD-1',
        'payload'               => 'payload-public',
        'adhoc'                 => true,
        'adhoc_distance'        => 1200,
        'pod_method'            => 'scan',
        'pod_required'          => true,
        'scheduled_at'          => '2026-01-01T10:00:00Z',
        'meta'                  => ['source' => 'api'],
        'type'                  => 'transport',
        'status'                => 'created',
        'notes'                 => 'Handle with care',
        'time_window_start'     => '09:00',
        'time_window_end'       => '17:00',
        'required_skills'       => ['hazmat'],
        'orchestrator_priority' => 75,
    ]);
});

test('api order controller normalizes payload route shape metadata', function () {
    $controller = new FleetOpsApiOrderControllerProbe();

    $shape = $controller->callHelper('payloadShapeFromArray', [
        'pickup'    => ['name' => 'Pickup'],
        'waypoints' => [['name' => 'Middle']],
        'entities'  => [['name' => 'Box']],
    ]);

    expect($shape)->toMatchArray([
        'pickup'                    => ['name' => 'Pickup'],
        'dropoff'                   => null,
        'return'                    => null,
        'waypoints'                 => [['name' => 'Middle']],
        'entities'                  => [['name' => 'Box']],
        'has_pickup_field'          => true,
        'has_dropoff_field'         => false,
        'has_return_field'          => false,
        'has_waypoints_field'       => true,
        'has_route_endpoint_fields' => true,
    ]);

    $requestShape = $controller->callHelper('payloadShapeFromRequest', new Request([
        'dropoff' => ['name' => 'Dropoff'],
        'driver'  => 'driver-public',
    ]));

    expect($requestShape)->toMatchArray([
        'pickup'                    => null,
        'dropoff'                   => ['name' => 'Dropoff'],
        'waypoints'                 => [],
        'entities'                  => [],
        'has_pickup_field'          => false,
        'has_dropoff_field'         => true,
        'has_route_endpoint_fields' => true,
    ]);
});

test('api contact controller normalizes create input and keeps update input narrow', function () {
    $controller = new FleetOpsApiContactControllerProbe();
    $request    = new Request([
        'name'         => 'Ada Customer',
        'title'        => 'Manager',
        'email'        => 'ada@example.test',
        'phone'        => '15551234567',
        'meta'         => ['vip' => true],
        'photo'        => 'file-public',
        'company_uuid' => 'spoofed-company',
    ]);

    expect($controller->callHelper('contactCreateInputFromRequest', $request))->toBe([
        'name'  => 'Ada Customer',
        'title' => 'Manager',
        'email' => 'ada@example.test',
        'phone' => '15551234567',
        'meta'  => ['vip' => true],
        'type'  => 'contact',
    ])->and($controller->callHelper('contactUpdateInputFromRequest', $request))->toBe([
        'name'  => 'Ada Customer',
        'title' => 'Manager',
        'email' => 'ada@example.test',
        'phone' => '15551234567',
        'meta'  => ['vip' => true],
    ]);
});

test('api service area and zone controllers expose input whitelist and integer radius helpers', function () {
    $serviceArea = new FleetOpsServiceAreaControllerProbe();
    $zone        = new FleetOpsZoneControllerProbe();
    $request     = new Request([
        'name'                    => 'Downtown',
        'type'                    => 'delivery',
        'status'                  => 'active',
        'country'                 => 'SG',
        'border'                  => ['type' => 'MultiPolygon'],
        'description'             => 'Core zone',
        'color'                   => '#000000',
        'stroke_color'            => '#ffffff',
        'trigger_on_entry'        => true,
        'trigger_on_exit'         => false,
        'dwell_threshold_minutes' => 15,
        'speed_limit_kmh'         => 45,
        'radius'                  => '750',
        'company_uuid'            => 'spoofed-company',
        'service_area'            => 'service-area-public',
    ]);

    expect($serviceArea->callHelper('serviceAreaInputFromRequest', $request))->toBe([
        'name'                    => 'Downtown',
        'type'                    => 'delivery',
        'status'                  => 'active',
        'country'                 => 'SG',
        'border'                  => ['type' => 'MultiPolygon'],
        'color'                   => '#000000',
        'stroke_color'            => '#ffffff',
        'trigger_on_entry'        => true,
        'trigger_on_exit'         => false,
        'dwell_threshold_minutes' => 15,
        'speed_limit_kmh'         => 45,
    ])->and($serviceArea->callHelper('radiusFromRequest', $request))->toBe(750)
        ->and($serviceArea->callHelper('radiusFromRequest', new Request()))->toBe(500)
        ->and($zone->callHelper('zoneInputFromRequest', $request))->toBe([
            'name'                    => 'Downtown',
            'border'                  => ['type' => 'MultiPolygon'],
            'status'                  => 'active',
            'description'             => 'Core zone',
            'color'                   => '#000000',
            'stroke_color'            => '#ffffff',
            'trigger_on_entry'        => true,
            'trigger_on_exit'         => false,
            'dwell_threshold_minutes' => 15,
            'speed_limit_kmh'         => 45,
        ])
        ->and($zone->callHelper('radiusFromRequest', $request))->toBe(750)
        ->and($zone->callHelper('radiusFromRequest', new Request()))->toBe(500);
});

test('api payload controller normalizes route shape and fill input', function () {
    $controller = new FleetOpsPayloadControllerProbe();
    $shape      = $controller->callHelper('payloadRouteShapeFromInput', [
        'type'      => 'parcel',
        'provider'  => 'native',
        'pickup'    => ['uuid' => 'pickup-uuid'],
        'dropoff'   => null,
        'entities'  => [['name' => 'Box']],
        'waypoints' => [['uuid' => 'waypoint-uuid']],
        'ignored'   => 'nope',
    ]);

    expect($shape)->toMatchArray([
        'entities'                  => [['name' => 'Box']],
        'waypoints'                 => [['uuid' => 'waypoint-uuid']],
        'pickup'                    => ['uuid' => 'pickup-uuid'],
        'dropoff'                   => null,
        'return'                    => null,
        'has_pickup_field'          => true,
        'has_dropoff_field'         => true,
        'has_return_field'          => false,
        'has_waypoints_field'       => true,
        'has_route_endpoint_fields' => true,
    ])->and($controller->callHelper('payloadRouteShapeFromInput', []))->toMatchArray([
        'entities'                  => [],
        'waypoints'                 => [],
        'pickup'                    => null,
        'dropoff'                   => null,
        'return'                    => null,
        'has_pickup_field'          => false,
        'has_dropoff_field'         => false,
        'has_return_field'          => false,
        'has_waypoints_field'       => false,
        'has_route_endpoint_fields' => false,
    ])->and($controller->callHelper('payloadFillInputFromInput', [
        'type'               => 'parcel',
        'provider'           => 'native',
        'meta'               => ['fragile' => true],
        'cod_amount'         => 1250,
        'cod_currency'       => 'USD',
        'cod_payment_method' => 'cash',
        'company_uuid'       => 'spoofed-company',
        'pickup'             => ['uuid' => 'pickup-uuid'],
    ]))->toBe([
        'type'               => 'parcel',
        'provider'           => 'native',
        'meta'               => ['fragile' => true],
        'cod_amount'         => 1250,
        'cod_currency'       => 'USD',
        'cod_payment_method' => 'cash',
    ]);
});

test('api service rate controller exposes input and meter fee helpers', function () {
    $controller  = new FleetOpsServiceRateControllerProbe();
    $request     = new Request([
        'service_name'             => 'Same Day',
        'service_type'             => 'delivery',
        'rate_calculation_method'  => 'fixed_meter',
        'currency'                 => 'USD',
        'base_fee'                 => 500,
        'max_distance_unit'        => 'km',
        'max_distance'             => 15,
        'per_meter_unit'           => 'km',
        'per_meter_flat_rate_fee'  => 120,
        'meter_fees'               => [
            ['distance' => 5, 'fee' => 100],
        ],
        'algorithm'                     => 'simple',
        'has_cod_fee'                   => true,
        'cod_calculation_method'        => 'flat',
        'cod_flat_fee'                  => 50,
        'cod_percent'                   => 2.5,
        'has_peak_hours_fee'            => true,
        'peak_hours_calculation_method' => 'percent',
        'peak_hours_flat_fee'           => 25,
        'peak_hours_percent'            => 15,
        'peak_hours_start'              => '17:00',
        'peak_hours_end'                => '19:00',
        'duration_terms'                => 'same_day',
        'estimated_days'                => 1,
        'service_area'                  => 'area-public',
        'zone'                          => 'zone-public',
    ]);
    $serviceRate                          = new ServiceRate();
    $serviceRate->uuid                    = 'service-rate-uuid';
    $serviceRate->currency                = 'USD';
    $serviceRate->rate_calculation_method = 'fixed_meter';

    expect($controller->callHelper('serviceRateInputFromRequest', $request))->toBe([
        'service_name'             => 'Same Day',
        'service_type'             => 'delivery',
        'rate_calculation_method'  => 'fixed_meter',
        'currency'                 => 'USD',
        'base_fee'                 => 500,
        'max_distance_unit'        => 'km',
        'max_distance'             => 15,
        'per_meter_unit'           => 'km',
        'per_meter_flat_rate_fee'  => 120,
        'meter_fees'               => [
            ['distance' => 5, 'fee' => 100],
            '*' => [
                'distance' => [5],
                'fee'      => [100],
            ],
        ],
        'algorithm'                     => 'simple',
        'has_cod_fee'                   => true,
        'cod_calculation_method'        => 'flat',
        'cod_flat_fee'                  => 50,
        'cod_percent'                   => 2.5,
        'has_peak_hours_fee'            => true,
        'peak_hours_calculation_method' => 'percent',
        'peak_hours_flat_fee'           => 25,
        'peak_hours_percent'            => 15,
        'peak_hours_start'              => '17:00',
        'peak_hours_end'                => '19:00',
        'duration_terms'                => 'same_day',
        'estimated_days'                => 1,
    ])->and($controller->callHelper('shouldCreateMeterFees', $request, $serviceRate))->toBeTrue()
        ->and($controller->callHelper('shouldCreateMeterFees', new Request(['meter_fees' => 'bad']), $serviceRate))->toBeFalse()
        ->and($controller->callHelper('meterFeeInputFromRequest', $request, $serviceRate, ['distance' => 5, 'fee' => 100]))->toBe([
            'service_rate_uuid' => 'service-rate-uuid',
            'distance'          => 5,
            'distance_unit'     => 'km',
            'fee'               => 100,
            'currency'          => 'USD',
        ]);
});

test('api purchase rate controller derives request order and customer inputs', function () {
    $controller           = new FleetOpsPurchaseRateControllerProbe();
    $order                = new Order();
    $order->payload_uuid  = 'payload-uuid';
    $order->customer_uuid = 'customer-uuid';
    $order->customer_type = Contact::class;
    $order->company_uuid  = null;

    expect($controller->callHelper('purchaseRateInputFromRequest', new Request([
        'meta'         => ['source' => 'quote'],
        'create_order' => true,
    ])))->toBe([
        'meta' => ['source' => 'quote'],
    ])->and($controller->callHelper('purchaseRateInputFromOrder', $order, 'fallback-company'))->toBe([
        'payload_uuid'  => 'payload-uuid',
        'customer_uuid' => 'customer-uuid',
        'customer_type' => Contact::class,
        'company_uuid'  => 'fallback-company',
    ])->and($controller->callHelper('purchaseRateCustomerInputFromLookup', [
        'uuid'  => 'lookup-customer-uuid',
        'table' => 'contacts',
    ]))->toBe([
        'customer_uuid' => 'lookup-customer-uuid',
        'customer_type' => '\\' . Contact::class,
    ]);
});

test('internal contact controller detects the customer portal extension package', function () {
    $controller = new FleetOpsInternalContactControllerProbe();

    expect($controller->callHelper('containsCustomerPortalExtension', [
        ['name' => 'fleetbase/fleetops-api'],
        ['name' => 'fleetbase/customer-portal-api'],
    ]))->toBeTrue()
        ->and($controller->callHelper('containsCustomerPortalExtension', [
            ['name' => 'fleetbase/fleetops-api'],
            ['name'  => 'fleetbase/customer-portal'],
            ['extra' => ['name' => 'fleetbase/customer-portal-api']],
        ]))->toBeFalse()
        ->and($controller->callHelper('containsCustomerPortalExtension', []))->toBeFalse();
});

test('api controller phone helpers normalize explicit values', function () {
    $driverPhone   = fleetopsControllerStaticMethod(DriverController::class, 'phone');
    $customerPhone = fleetopsControllerStaticMethod(CustomerController::class, 'phone');

    expect($driverPhone->invoke(null, '15551234567'))->toBe('+15551234567')
        ->and($driverPhone->invoke(null, '+15551234567'))->toBe('+15551234567')
        ->and($customerPhone->invoke(null, ' 15551234567 '))->toBe('+15551234567')
        ->and($customerPhone->invoke(null, ''))->toBe('');
});

test('position controller calculates replay metrics from in-memory positions', function () {
    $controller       = new PositionController();
    $calculateMetrics = new ReflectionMethod(PositionController::class, 'calculateMetrics');
    $calculateMetrics->setAccessible(true);

    $positions = collect([
        (object) [
            'uuid'        => 'position-1',
            'speed'       => 0,
            'coordinates' => new Point(1.3000, 103.8000),
            'created_at'  => Carbon::parse('2026-01-01 10:00:00'),
        ],
        (object) [
            'uuid'        => 'position-2',
            'speed'       => 0,
            'coordinates' => new Point(1.3000, 103.8000),
            'created_at'  => Carbon::parse('2026-01-01 10:06:00'),
        ],
        (object) [
            'uuid'        => 'position-3',
            'speed'       => 30,
            'coordinates' => new Point(1.3010, 103.8010),
            'created_at'  => Carbon::parse('2026-01-01 10:06:01'),
        ],
    ]);

    $metrics = $calculateMetrics->invoke($controller, $positions);

    expect($metrics['total_positions'])->toBe(3)
        ->and($metrics['total_duration'])->toBe(361)
        ->and($metrics['max_speed'])->toBe(108.0)
        ->and($metrics['avg_speed'])->toBe(108.0)
        ->and($metrics['speeding_count'])->toBe(1)
        ->and($metrics['speeding_events'][0]['position_uuid'])->toBe('position-3')
        ->and($metrics['dwell_count'])->toBe(1)
        ->and($metrics['dwell_times'][0]['duration'])->toBe(360)
        ->and($metrics['acceleration_count'])->toBe(1)
        ->and($metrics['acceleration_events'][0])->toMatchArray([
            'position_uuid' => 'position-3',
            'acceleration'  => 30.0,
            'type'          => 'acceleration',
        ]);
});

test('position controller distance helper handles valid and incomplete coordinates', function () {
    $controller        = new PositionController();
    $calculateDistance = new ReflectionMethod(PositionController::class, 'calculateDistance');
    $calculateDistance->setAccessible(true);

    expect($calculateDistance->invoke($controller, (object) ['latitude' => null, 'longitude' => 0], (object) ['latitude' => 1, 'longitude' => 1]))->toBe(0)
        ->and($calculateDistance->invoke($controller, (object) ['latitude' => 0, 'longitude' => 0], (object) ['latitude' => 0, 'longitude' => 1]))->toBeGreaterThan(110000)
        ->and($calculateDistance->invoke($controller, (object) ['latitude' => 0, 'longitude' => 0], (object) ['latitude' => 0, 'longitude' => 1]))->toBeLessThan(112000);
});
