<?php

use Fleetbase\FleetOps\Jobs\FinalizeApiOrderCreation;
use Fleetbase\FleetOps\Jobs\FinalizeInternalOrderCreation;
use Fleetbase\FleetOps\Jobs\NotifyBulkAssignedDriver;
use Illuminate\Contracts\Queue\ShouldQueue;

test('order workflows dispatch named queue jobs instead of signed closures', function () {
    $apiController      = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Api/v1/OrderController.php');
    $internalController = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Internal/v1/OrderController.php');

    expect($apiController)
        ->not->toContain('dispatch(function')
        ->toContain('FinalizeApiOrderCreation::dispatch(')
        ->toContain(')->afterCommit()')
        ->and($internalController)
        ->not->toContain('dispatch(function')
        ->toContain('FinalizeInternalOrderCreation::dispatch($order->uuid)->afterCommit()')
        ->toContain('NotifyBulkAssignedDriver::dispatch($orderUuids->all(), $driver->uuid)->afterCommit()');
});

test('order queue jobs serialize only scalar identifiers and options', function () {
    $apiJob      = new FinalizeApiOrderCreation('order-uuid', 'service-quote-uuid', true);
    $internalJob = new FinalizeInternalOrderCreation('order-uuid');
    $bulkJob     = new NotifyBulkAssignedDriver(['order-uuid-1', 'order-uuid-2'], 'driver-uuid');

    expect($apiJob)->toBeInstanceOf(ShouldQueue::class)
        ->and($apiJob->orderUuid)->toBe('order-uuid')
        ->and($apiJob->serviceQuoteUuid)->toBe('service-quote-uuid')
        ->and($apiJob->shouldDispatch)->toBeTrue()
        ->and($internalJob)->toBeInstanceOf(ShouldQueue::class)
        ->and($internalJob->orderUuid)->toBe('order-uuid')
        ->and($bulkJob)->toBeInstanceOf(ShouldQueue::class)
        ->and($bulkJob->orderUuids)->toBe(['order-uuid-1', 'order-uuid-2'])
        ->and($bulkJob->driverUuid)->toBe('driver-uuid');
});
