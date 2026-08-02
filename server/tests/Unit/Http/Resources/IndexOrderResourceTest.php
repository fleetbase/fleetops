<?php

use Fleetbase\FleetOps\Http\Resources\v1\Index\Order as IndexOrderResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Http\Request;

/**
 * Covers the lightweight index Order resource relation closures: loaded
 * customers and facilitators resolving into typed lightweight payloads,
 * and payload/driver/vehicle/tracking-number pass-through resources.
 */
function fleetopsIndexOrderResourceOrder(): Order
{
    $order = new Order();
    $order->setRawAttributes([
        'uuid'                  => 'order-index-1',
        'public_id'             => 'order_indexone1',
        'company_uuid'          => 'company-1',
        'customer_uuid'         => 'contact-1',
        'customer_type'         => Contact::class,
        'facilitator_uuid'      => 'vendor-1',
        'facilitator_type'      => Vendor::class,
        'driver_assigned_uuid'  => 'driver-1',
        'vehicle_assigned_uuid' => 'vehicle-1',
        'tracking_number_uuid'  => 'tn-1',
        'status'                => 'created',
        'type'                  => 'transport',
    ], true);

    $customer = new Contact();
    $customer->setRawAttributes(['uuid' => 'contact-1', 'public_id' => 'contact_indexone', 'name' => 'Index Customer', 'type' => 'customer'], true);
    $facilitator = new Vendor();
    $facilitator->setRawAttributes(['uuid' => 'vendor-1', 'public_id' => 'vendor_indexone', 'name' => 'Index Facilitator'], true);
    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-1', 'public_id' => 'payload_indexone'], true);
    $driver = new Driver();
    $driver->setRawAttributes(['uuid' => 'driver-1', 'public_id' => 'driver_indexone'], true);
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_indexone'], true);
    $trackingNumber = new TrackingNumber();
    $trackingNumber->setRawAttributes(['uuid' => 'tn-1', 'public_id' => 'tracking_number_indexone', 'tracking_number' => 'FLB-INDEX-1'], true);

    $order->setRelation('customer', $customer);
    $order->setRelation('facilitator', $facilitator);
    $order->setRelation('payload', $payload);
    $order->setRelation('driverAssigned', $driver);
    $order->setRelation('vehicleAssigned', $vehicle);
    $order->setRelation('trackingNumber', $trackingNumber);
    $order->setRelation('trackingStatuses', collect([]));

    return $order;
}

test('index order resource resolves loaded relations into lightweight payloads', function () {
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    app()->instance('request', Request::create('/v1/orders', 'GET'));
    session(['company' => 'company-1']);

    $order    = fleetopsIndexOrderResourceOrder();
    $resource = new IndexOrderResource($order);
    $resolved = $resource->resolve(Request::create('/v1/orders', 'GET'));

    expect($resolved['tracking'])->toBe('FLB-INDEX-1')
        ->and($resolved['customer']['type'])->toBe('customer')
        ->and($resolved['customer']['customer_type'])->toContain('customer-')
        ->and($resolved['facilitator']['type'])->toBe('facilitator')
        ->and($resolved['facilitator']['facilitator_type'])->toContain('facilitator-')
        ->and($resolved['payload'])->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Index\Payload::class)
        ->and($resolved['driver_assigned'])->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Index\Driver::class)
        ->and($resolved['vehicle_assigned'])->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Index\Vehicle::class)
        ->and($resolved['tracking_number'])->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Index\TrackingNumber::class)
        ->and($resolved['latest_status'])->toBe('created')
        ->and($resolved['latest_status_code'])->toBeNull()
        ->and($resolved['meta'])->toBe(['_index_resource' => true]);
});

test('v1 order resource resolves loaded relation closures and generic morphs', function () {
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    $schema = $connection->getSchemaBuilder();
    $tables = [
        'places'              => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'owner_type', 'name', 'location', '_key'],
        'users'               => ['uuid', 'public_id', 'company_uuid', 'name', 'type', '_key'],
        'custom_fields'       => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'name', 'label', '_key'],
        'custom_field_values' => ['uuid', 'public_id', 'company_uuid', 'custom_field_uuid', 'subject_uuid', 'subject_type', 'value', 'value_type', '_key'],
        'vehicles'            => ['uuid', 'public_id', 'company_uuid', 'driver_uuid', 'name', '_key'],
        'drivers'             => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'current_job_uuid', 'status', '_key'],
        'orders'              => ['uuid', 'public_id', 'company_uuid', 'driver_assigned_uuid', 'status', '_key'],
        'contacts'            => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'type', '_key'],
        'vendor_personnels'   => ['uuid', 'vendor_uuid', 'contact_uuid', '_key'],
        'tracking_statuses'   => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'code', 'status', '_key'],
        'comments'            => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'parent_comment_uuid', 'author_uuid', 'content', '_key'],
        'proofs'              => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'order_uuid', 'file_uuid', '_key'],
        'purchase_rates'      => ['uuid', 'public_id', 'company_uuid', 'service_quote_uuid', 'order_uuid', 'status', '_key'],
        'companies'           => ['uuid', 'public_id', 'name', 'country', 'options'],
        'company_users'       => ['uuid', 'company_uuid', 'user_uuid', 'status', '_key'],
        'settings'            => ['uuid', 'key', 'value', '_key'],
        'files'               => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'name', 'path', '_key'],
        'payloads'            => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'meta', '_key'],
        'waypoints'           => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'order', 'type', '_key'],
        'entities'            => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'name', 'type', '_key'],
        'tracking_numbers'    => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'barcode', 'qr_code', '_key'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }
    app()->instance('request', Request::create('/v1/orders', 'GET'));
    session(['company' => 'company-1']);

    $order = fleetopsIndexOrderResourceOrder();

    $resource = new Fleetbase\FleetOps\Http\Resources\v1\Order($order);
    $resolved = $resource->resolve(Request::create('/v1/orders', 'GET'));

    expect($resolved['customer'])->not->toBeNull()
        ->and($resolved['facilitator'])->not->toBeNull()
        ->and($resolved['driver_assigned'])->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Driver::class)
        ->and($resolved['vehicle_assigned'])->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Vehicle::class)
        ->and($resolved['tracking_statuses'])->not->toBeNull();

    // Morphs without dedicated resources resolve through the generic wrapper
    $company = new Fleetbase\Models\Company();
    $company->setRawAttributes(['uuid' => 'company-morph-1', 'name' => 'Morph Co'], true);
    $reflection = new ReflectionMethod(Fleetbase\FleetOps\Http\Resources\v1\Order::class, 'transformMorphResource');
    $reflection->setAccessible(true);
    $generic = $reflection->invoke($resource, $company);
    expect($generic)->toBeArray()->toHaveKey('name');
});
