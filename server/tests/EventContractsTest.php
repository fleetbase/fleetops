<?php

use Fleetbase\FleetOps\Events\DriverLocationChanged;
use Fleetbase\FleetOps\Events\FuelProviderTransactionImported;
use Fleetbase\FleetOps\Events\FuelProviderTransactionMatched;
use Fleetbase\FleetOps\Events\FuelProviderTransactionUnmatched;
use Fleetbase\FleetOps\Events\FuelReportCreatedFromProvider;
use Fleetbase\FleetOps\Events\VehicleLocationChanged;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Vehicle;

function eventChannelNames(array $channels): array
{
    return array_map(fn ($channel) => $channel->name, $channels);
}

test('driver location changed broadcasts driver telemetry payload', function () {
    session([
        'company'        => 'company-1',
        'api_credential' => 'api-1',
    ]);

    $driver = new Driver();
    $driver->setRawAttributes([
        'uuid'        => 'driver-uuid',
        'public_id'   => 'driver_public',
        'internal_id' => 'driver_internal',
        'name'        => 'Jane Driver',
        'phone'       => '+15551234567',
        'location'    => ['type' => 'Point', 'coordinates' => [103.8, 1.3]],
        'altitude'    => 20,
        'heading'     => 180,
        'speed'       => 32,
    ], true);
    $driver->setRelation('user', (object) [
        'name'  => 'Jane Driver',
        'phone' => '+15551234567',
    ]);

    $event = new DriverLocationChanged($driver, ['source' => 'telematics']);

    expect($event->broadcastAs())->toBe('driver.location_changed')
        ->and(eventChannelNames($event->broadcastOn()))->toBe([
            'company.company-1',
            'api.api-1',
            'driver.driver_public',
            'driver.driver-uuid',
        ])
        ->and($event->broadcastWith())->toMatchArray([
            'event' => 'driver.location_changed',
            'data'  => [
                'id'             => 'driver_public',
                'internal_id'    => 'driver_internal',
                'name'           => 'Jane Driver',
                'phone'          => '+15551234567',
                'location'       => ['type' => 'Point', 'coordinates' => [103.8, 1.3]],
                'altitude'       => 20,
                'heading'        => 180,
                'speed'          => 32,
                'additionalData' => ['source' => 'telematics'],
            ],
        ])
        ->and($event->eventId)->toStartWith('event_')
        ->and($event->sentAt)->toBeString();
});

test('vehicle location changed broadcasts vehicle telemetry payload', function () {
    session([
        'company'        => 'company-1',
        'api_credential' => 'api-1',
    ]);

    $vehicle = new Vehicle();
    $vehicle->setRawAttributes([
        'uuid'         => 'vehicle-uuid',
        'public_id'    => 'vehicle_public',
        'plate_number' => 'ABC-123',
        'name'         => 'Truck 12',
        'location'     => ['type' => 'Point', 'coordinates' => [103.9, 1.4]],
        'altitude'     => 22,
        'heading'      => 90,
        'speed'        => 45,
    ], true);

    $event = new VehicleLocationChanged($vehicle, ['source' => 'device']);

    expect($event->broadcastAs())->toBe('vehicle.location_changed')
        ->and(eventChannelNames($event->broadcastOn()))->toBe([
            'company.company-1',
            'api.api-1',
            'vehicle.vehicle_public',
            'vehicle.vehicle-uuid',
        ])
        ->and($event->broadcastWith())->toMatchArray([
            'event' => 'vehicle.location_changed',
            'data'  => [
                'id'             => 'vehicle_public',
                'plate_number'   => 'ABC-123',
                'name'           => 'Truck 12',
                'location'       => ['type' => 'Point', 'coordinates' => [103.9, 1.4]],
                'altitude'       => 22,
                'heading'        => 90,
                'speed'          => 45,
                'additionalData' => ['source' => 'device'],
            ],
        ]);
});

test('fuel provider events retain transaction and generated fuel report references', function () {
    $transaction = new FuelProviderTransaction();
    $fuelReport  = new FuelReport();

    expect((new FuelProviderTransactionImported($transaction))->transaction)->toBe($transaction)
        ->and((new FuelProviderTransactionMatched($transaction))->transaction)->toBe($transaction)
        ->and((new FuelProviderTransactionUnmatched($transaction))->transaction)->toBe($transaction)
        ->and((new FuelReportCreatedFromProvider($transaction, $fuelReport))->transaction)->toBe($transaction)
        ->and((new FuelReportCreatedFromProvider($transaction, $fuelReport))->fuelReport)->toBe($fuelReport);
});
