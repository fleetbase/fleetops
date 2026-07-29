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
