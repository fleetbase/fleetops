<?php

use Fleetbase\FleetOps\Events\OrderDispatchFailed;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Str;

class FleetOpsOrderDispatchFailedUnitProbe extends OrderDispatchFailed
{
    public function getEventData(): array
    {
        return [];
    }
}

test('order dispatch failed event stores dispatch reason and lifecycle identity', function () {
    if (!Str::hasMacro('humanize')) {
        Str::macro('humanize', fn (string $value): string => str_replace('_', ' ', Str::snake($value)));
    }

    $order = new Order();
    $order->setRawAttributes([
        'uuid'      => 'order-dispatch-failed',
        'public_id' => 'order_public',
    ], true);

    $event = new FleetOpsOrderDispatchFailedUnitProbe($order, 'no_driver_available');

    expect($event->eventName)->toBe('dispatch_failed')
        ->and($event->getReason())->toBe('no_driver_available')
        ->and($event->reason)->toBe('no_driver_available')
        ->and($event->modelUuid)->toBe('order-dispatch-failed')
        ->and($event->modelName)->toBe('order')
        ->and($event->broadcastAs())->toBe('order.dispatch_failed');
});
