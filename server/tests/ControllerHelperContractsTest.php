<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\ContactController as ApiContactController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\CustomerController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\EntityController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController as ApiOrderController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\PayloadController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\PlaceController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\PurchaseRateController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceAreaController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceQuoteController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceRateController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\VehicleController as ApiVehicleController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\ZoneController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ContactController as InternalContactController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\DriverController as InternalDriverController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\FleetController as InternalFleetController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\MaintenanceController as InternalMaintenanceController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController as InternalOrderController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\PositionController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ServiceQuoteController as InternalServiceQuoteController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\VendorController as InternalVendorController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Models\VendorPersonnel;
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

class FleetOpsApiDriverDelegationFake
{
    public array $calls = [];

    public function track(string $id, Request $request): array
    {
        $this->calls[] = ['track', $id, $request];

        return ['delegated' => 'track', 'id' => $id];
    }

    public function registerDevice(Request $request): array
    {
        $this->calls[] = ['registerDevice', $request];

        return ['delegated' => 'register-device'];
    }

    public function login(Request $request): array
    {
        $this->calls[] = ['login', $request];

        return ['delegated' => 'login'];
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

class FleetOpsPlaceControllerProbe extends PlaceController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(PlaceController::class, $method);
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

class FleetOpsApiVehicleControllerProbe extends ApiVehicleController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ApiVehicleController::class, $method);
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

class FleetOpsServiceQuoteControllerProbe extends ServiceQuoteController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ServiceQuoteController::class, $method);
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

class FleetOpsInternalContactHookFake extends Contact
{
    public bool $normalized          = false;
    public array $syncedCustomFields = [];

    public function normalizeCustomerUser(?Fleetbase\Models\User $user = null, bool $quiet = false): ?Fleetbase\Models\User
    {
        $this->normalized = true;

        return null;
    }

    public function syncCustomFieldValues(array $payload, array $options = []): array
    {
        $this->syncedCustomFields = $payload;

        return $payload;
    }
}

class FleetOpsInternalFleetControllerProbe extends InternalFleetController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(InternalFleetController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$arguments);
    }
}

class FleetOpsInternalMaintenanceControllerProbe extends InternalMaintenanceController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(InternalMaintenanceController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsInternalServiceQuoteControllerProbe extends InternalServiceQuoteController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(InternalServiceQuoteController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsInternalVendorControllerProbe extends InternalVendorController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(InternalVendorController::class, $method);
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

test('internal driver controller delegates mobile API flows to the public driver controller', function () {
    $delegate = new FleetOpsApiDriverDelegationFake();
    app()->instance(DriverController::class, $delegate);

    $controller = new InternalDriverController();
    $request    = Request::create('/internal/v1/drivers/driver_123/track', 'POST', [
        'location' => [103.8, 1.3],
    ]);

    expect($controller->track('driver_123', $request))->toBe(['delegated' => 'track', 'id' => 'driver_123'])
        ->and($controller->registerDevice($request))->toBe(['delegated' => 'register-device'])
        ->and($controller->login($request))->toBe(['delegated' => 'login'])
        ->and($delegate->calls[0])->toBe(['track', 'driver_123', $request])
        ->and($delegate->calls[1])->toBe(['registerDevice', $request])
        ->and($delegate->calls[2])->toBe(['login', $request]);

    app()->forgetInstance(DriverController::class);
});

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

test('api place controller exposes address input and search option helpers', function () {
    $controller = new FleetOpsPlaceControllerProbe();
    $request    = new Request([
        'name'                 => 'Warehouse',
        'street1'              => '1 Depot Road',
        'street2'              => 'Unit 2',
        'city'                 => 'Singapore',
        'location'             => ['latitude' => 1.30, 'longitude' => 103.80],
        'province'             => 'SG',
        'postal_code'          => '100001',
        'neighborhood'         => 'Central',
        'district'             => 'District 1',
        'building'             => 'Depot',
        'security_access_code' => '1234',
        'country'              => 'SG',
        'phone'                => '+15551234567',
        'type'                 => 'warehouse',
        'meta'                 => ['dock' => true],
        'owner'                => 'customer-public',
        'vendor'               => 'vendor-public',
    ]);

    $coordinateRequest = new Request([
        'latitude'  => 1.2816,
        'longitude' => 103.851,
        'location'  => ['latitude' => 0.1, 'longitude' => 0.2],
    ]);
    $pointInput        = $controller->callHelper('withLocationFromRequest', [], $coordinateRequest)['location'];
    $reversePoint      = $controller->callHelper('pointFromCoordinateRequest', $coordinateRequest);

    expect($controller->callHelper('placeInputFromRequest', $request))->toBe([
        'name'                 => 'Warehouse',
        'street1'              => '1 Depot Road',
        'street2'              => 'Unit 2',
        'city'                 => 'Singapore',
        'location'             => ['latitude' => 1.30, 'longitude' => 103.80],
        'province'             => 'SG',
        'postal_code'          => '100001',
        'neighborhood'         => 'Central',
        'district'             => 'District 1',
        'building'             => 'Depot',
        'security_access_code' => '1234',
        'country'              => 'SG',
        'phone'                => '+15551234567',
        'type'                 => 'warehouse',
        'meta'                 => ['dock' => true],
    ])->and($controller->callHelper('isNotAddressObject', new Request(['address' => '1 Depot Road'])))->toBeTrue()
        ->and($controller->callHelper('isNotAddressObject', $request))->toBeFalse()
        ->and($pointInput)->toBeInstanceOf(Point::class)
        ->and($pointInput->getLat())->toBe(1.2816)
        ->and($pointInput->getLng())->toBe(103.851)
        ->and($reversePoint)->toBeInstanceOf(Point::class)
        ->and($reversePoint->getLat())->toBe(1.2816)
        ->and($reversePoint->getLng())->toBe(103.851)
        ->and($controller->callHelper('pointFromCoordinateRequest', new Request()))->toBeNull()
        ->and($controller->callHelper('placeSearchOptionsFromRequest', new Request([
            'limit'     => 25,
            'geo'       => true,
            'latitude'  => 1.2816,
            'longitude' => 103.851,
        ])))->toBe([
            'limit'          => 25,
            'geo'            => true,
            'latitude'       => 1.2816,
            'longitude'      => 103.851,
            'no_query_order' => 'name_desc',
        ]);
});

test('api vehicle controller exposes input defaults and tracking payload helpers', function () {
    $controller = new FleetOpsApiVehicleControllerProbe();
    $request    = new Request([
        'status'                   => 'active',
        'make'                     => 'Toyota',
        'model'                    => 'HiAce',
        'year'                     => 2025,
        'trim'                     => 'DX',
        'type'                     => 'van',
        'plate_number'             => 'SG-1234',
        'vin'                      => 'VIN123',
        'meta'                     => ['temperature' => 'ambient'],
        'online'                   => false,
        'location'                 => ['latitude' => 1.30, 'longitude' => 103.80],
        'altitude'                 => 10,
        'heading'                  => 90,
        'speed'                    => 45,
        'payload_capacity'         => 1200,
        'payload_capacity_volume'  => 8,
        'payload_capacity_pallets' => 2,
        'payload_capacity_parcels' => 80,
        'skills'                   => ['refrigerated'],
        'max_tasks'                => 5,
        'time_window_start'        => '08:00',
        'time_window_end'          => '17:00',
        'return_to_depot'          => true,
        'vendor'                   => 'vendor-public',
        'driver'                   => 'driver-public',
    ]);
    $locationInput = $controller->callHelper('withCoordinateLocation', [], new Request([
        'latitude'  => 1.2816,
        'longitude' => 103.851,
    ]));
    $tracking      = $controller->callHelper('positionDataFromTrackingInput', 1.2816, 103.851, 12, 180, 55);

    expect($controller->callHelper('vehicleInputFromRequest', $request))->toBe([
        'status'                   => 'active',
        'make'                     => 'Toyota',
        'model'                    => 'HiAce',
        'year'                     => 2025,
        'trim'                     => 'DX',
        'type'                     => 'van',
        'plate_number'             => 'SG-1234',
        'vin'                      => 'VIN123',
        'meta'                     => ['temperature' => 'ambient'],
        'online'                   => false,
        'location'                 => ['latitude' => 1.30, 'longitude' => 103.80],
        'altitude'                 => 10,
        'heading'                  => 90,
        'speed'                    => 45,
        'payload_capacity'         => 1200,
        'payload_capacity_volume'  => 8,
        'payload_capacity_pallets' => 2,
        'payload_capacity_parcels' => 80,
        'skills'                   => ['refrigerated'],
        'max_tasks'                => 5,
        'time_window_start'        => '08:00',
        'time_window_end'          => '17:00',
        'return_to_depot'          => true,
    ])->and($controller->callHelper('withDefaultOnline', ['make' => 'Toyota']))->toBe([
        'make'   => 'Toyota',
        'online' => 0,
    ])->and($controller->callHelper('withDefaultOnline', ['online' => false]))->toBe(['online' => false])
        ->and($locationInput['location'])->toBeInstanceOf(Point::class)
        ->and($locationInput['location']->getLat())->toBe(1.2816)
        ->and($locationInput['location']->getLng())->toBe(103.851)
        ->and($tracking['location'])->toBeInstanceOf(Point::class)
        ->and($tracking['location']->getLat())->toBe(1.2816)
        ->and($tracking['location']->getLng())->toBe(103.851)
        ->and($tracking)->toMatchArray([
            'latitude'  => 1.2816,
            'longitude' => 103.851,
            'altitude'  => 12,
            'heading'   => 180,
            'speed'     => 55,
        ]);
});

test('api service quote controller exposes preliminary and quote item helpers', function () {
    $controller = new FleetOpsServiceQuoteControllerProbe();
    $request    = new Request([
        'payload' => [
            'pickup'    => ['name' => 'Pickup'],
            'dropoff'   => ['name' => 'Dropoff'],
            'return'    => ['name' => 'Return'],
            'waypoints' => [
                ['name' => 'Waypoint'],
            ],
            'entities' => [
                ['name' => 'Box'],
            ],
        ],
        'cod'      => true,
        'currency' => 'USD',
    ]);
    $pickup      = new Place();
    $dropoff     = new Place();
    $quote       = new ServiceQuote();
    $quote->uuid = 'service-quote-uuid';
    $cheap       = (object) ['amount' => 1200, 'id' => 'cheap'];
    $expensive   = (object) ['amount' => 2500, 'id' => 'expensive'];

    expect($controller->callHelper('preliminaryDataFromRequest', $request))->toBe([
        'pickup'    => ['name' => 'Pickup'],
        'dropoff'   => ['name' => 'Dropoff'],
        'return'    => ['name' => 'Return'],
        'waypoints' => [
            ['name' => 'Waypoint'],
        ],
        'entities' => [
            ['name' => 'Box'],
        ],
        'cod'      => true,
        'currency' => true,
    ])->and($controller->callHelper('preliminaryStops', $pickup, [null, 'waypoint-public'], $dropoff)->values()->all())->toBe([
        $pickup,
        'waypoint-public',
        $dropoff,
    ])->and($controller->callHelper('endpointCount', $pickup, $dropoff))->toBe(2)
        ->and($controller->callHelper('endpointCount', $pickup, 'dropoff-public'))->toBe(1)
        ->and($controller->callHelper('serviceQuoteItemInput', $quote, [
            'amount'   => 1200,
            'currency' => 'USD',
            'details'  => ['distance' => 10],
            'code'     => 'base_fee',
        ]))->toBe([
            'service_quote_uuid' => 'service-quote-uuid',
            'amount'             => 1200,
            'currency'           => 'USD',
            'details'            => ['distance' => 10],
            'code'               => 'base_fee',
        ])
        ->and($controller->callHelper('bestQuote', collect([$expensive, $cheap])))->toBe($cheap);
});

test('internal service quote controller exposes preliminary checkout and quote item helpers', function () {
    $controller = new FleetOpsInternalServiceQuoteControllerProbe();
    $request    = new Request([
        'payload' => [
            'pickup_uuid'  => 'pickup-uuid',
            'dropoff_uuid' => 'dropoff-uuid',
            'waypoints'    => ['waypoint-uuid', null, ''],
            'entities'     => ['entity-uuid', null],
        ],
    ]);
    $pickup       = new Place();
    $quote        = new ServiceQuote();
    $quote->uuid  = 'internal-service-quote-uuid';
    $purchaseRate = (object) ['uuid' => 'purchase-rate-uuid'];
    $cheap        = ['amount' => 250, 'id' => 'cheap'];
    $expensive    = ['amount' => 950, 'id' => 'expensive'];

    expect($controller->callHelper('preliminaryInputFromRequest', $request))->toBe([
        'pickup'    => 'pickup-uuid',
        'dropoff'   => 'dropoff-uuid',
        'waypoints' => ['waypoint-uuid', null, ''],
        'entities'  => ['entity-uuid', null],
    ])->and($controller->callHelper('requestFirst', new Request(['fallback' => 'value']), ['missing', 'fallback']))->toBe('value')
        ->and($controller->callHelper('requestFirst', new Request(), ['missing'], 'default'))->toBe('default')
        ->and($controller->callHelper('filterPreliminaryCollectionInput', ['one', null, '', 'two']))->toBe([
            0 => 'one',
            3 => 'two',
        ])
        ->and($controller->callHelper('preliminaryStops', $pickup, [null, 'middle'], null)->values()->all())->toBe([
            $pickup,
            'middle',
        ])
        ->and($controller->callHelper('endpointCount', $pickup, 'dropoff-uuid'))->toBe(1)
        ->and($controller->callHelper('serviceQuoteItemInput', $quote, [
            'amount'   => 250,
            'currency' => 'SGD',
            'details'  => ['duration' => 20],
            'code'     => 'minimum_fee',
        ]))->toBe([
            'service_quote_uuid' => 'internal-service-quote-uuid',
            'amount'             => 250,
            'currency'           => 'SGD',
            'details'            => ['duration' => 20],
            'code'               => 'minimum_fee',
        ])
        ->and($controller->callHelper('bestQuote', [$expensive, $cheap]))->toBe($cheap)
        ->and($controller->callHelper('purchaseCompletePayload', $quote))->toBe([
            'status'        => 'purchase_complete',
            'service_quote' => $quote,
        ])
        ->and($controller->callHelper('checkoutStatusPayload', 'complete', $quote, $purchaseRate))->toBe([
            'status'       => 'complete',
            'serviceQuote' => $quote,
            'purchaseRate' => $purchaseRate,
        ]);
});

test('internal fleet controller exposes assignment and import payload helpers', function () {
    $controller = new FleetOpsInternalFleetControllerProbe();

    expect($controller->callHelper('assignmentPayload', false, new stdClass()))->toBe([
        'status' => 'ok',
        'exists' => false,
        'added'  => true,
    ])->and($controller->callHelper('assignmentPayload', true, false))->toBe([
        'status' => 'ok',
        'exists' => true,
        'added'  => false,
    ])->and($controller->callHelper('removedAssignmentPayload', 3))->toBe([
        'status'  => 'ok',
        'deleted' => 3,
    ])->and($controller->callHelper('importCompletedPayload', 7))->toBe([
        'status'   => 'ok',
        'message'  => 'Import completed',
        'imported' => 7,
    ]);
});

test('internal maintenance controller exposes line item response payload', function () {
    $controller              = new FleetOpsInternalMaintenanceControllerProbe();
    $maintenance             = new Maintenance();
    $maintenance->line_items = [
        ['description' => 'Oil filter', 'quantity' => 2, 'unit_cost' => 1500],
    ];
    $maintenance->total_cost = 3000;

    expect($controller->callHelper('lineItemPayload', $maintenance))->toBe([
        'status'     => 'ok',
        'line_items' => [
            ['description' => 'Oil filter', 'quantity' => 2, 'unit_cost' => 1500],
        ],
        'total_cost' => 3000,
    ]);
});

test('internal vendor controller serializes personnel payload defaults without contact details', function () {
    $controller                 = new FleetOpsInternalVendorControllerProbe();
    $personnel                  = new VendorPersonnel();
    $personnel->invited_by_uuid = 'user-uuid';
    $personnel->setRelation('contact', null);

    $payload = $controller->callHelper('vendorPersonnelPayload', $personnel);

    expect($payload)->toMatchArray([
        'id'              => null,
        'uuid'            => null,
        'contact_uuid'    => null,
        'public_id'       => null,
        'name'            => null,
        'email'           => null,
        'phone'           => null,
        'photo_url'       => null,
        'role'            => 'member',
        'status'          => 'active',
        'invited_by_uuid' => 'user-uuid',
        'contact'         => null,
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

test('internal contact controller create update and after save hooks protect customer contacts', function () {
    $controller = new FleetOpsInternalContactControllerProbe();
    $request    = new Request([
        'contact' => [
            'custom_field_values' => [
                ['key' => 'tier', 'value' => 'gold'],
            ],
        ],
    ]);
    $input      = ['type' => 'contact'];

    $controller->onBeforeCreate($request, $input);

    $contact = new FleetOpsInternalContactHookFake();
    $contact->setRawAttributes([
        'type' => 'customer',
        'meta' => [],
    ], true);
    $updateInput = ['type' => 'vendor'];

    expect(fn () => $controller->onBeforeUpdate($request, $contact, $updateInput))
        ->toThrow(Exception::class, 'Customer contact type cannot be changed.');

    $controller->afterSave($request, $contact);

    expect($input)->toBe(['type' => 'contact'])
        ->and($contact->normalized)->toBeTrue()
        ->and($contact->syncedCustomFields)->toBe([
            ['key' => 'tier', 'value' => 'gold'],
        ]);
});

test('api controller phone helpers normalize explicit values', function () {
    $driverPhone         = fleetopsControllerStaticMethod(DriverController::class, 'phone');
    $internalDriverPhone = fleetopsControllerStaticMethod(InternalDriverController::class, 'phone');
    $customerPhone       = fleetopsControllerStaticMethod(CustomerController::class, 'phone');

    expect($driverPhone->invoke(null, '15551234567'))->toBe('+15551234567')
        ->and($driverPhone->invoke(null, '+15551234567'))->toBe('+15551234567')
        ->and($internalDriverPhone->invoke(null, '15559876543'))->toBe('+15559876543')
        ->and($internalDriverPhone->invoke(null, '+15559876543'))->toBe('+15559876543')
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
