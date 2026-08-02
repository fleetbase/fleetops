<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Orchestration\Engines\RouteSequencingEngine;
use Fleetbase\FleetOps\Support\Telematics\Providers\AfaqyProvider;
use Fleetbase\FleetOps\Tracking\Providers\GoogleRoutesTrackingProvider;

/**
 * Covers the skip-this-row guards and value fallbacks that only fire on
 * malformed or partial input. Fixtures elsewhere are well-formed by
 * construction, so these arms are never taken.
 */
function fleetopsGuardInvoke(object $instance, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($instance, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($instance, ...$arguments);
}

test('afaqy sensor values prefer an explicit value over the last-known fallbacks', function () {
    $provider = new AfaqyProvider();

    // An explicit key wins even when it is falsy, which the ?? chain below
    // would otherwise skip straight past.
    expect(fleetopsGuardInvoke($provider, 'resolveSensorValue', [['value' => 0, 'last_val' => ['value' => 42]]]))->toBe(0)
        ->and(fleetopsGuardInvoke($provider, 'resolveSensorValue', [['last_val' => ['value' => 42]]]))->toBe(42);
});

test('the google routes provider reports no api key when none is configured', function () {
    config()->set('services.google_maps.api_key', null);

    $provider = new GoogleRoutesTrackingProvider();

    // The env() fallback is guarded on phpoption, which the vendor tree omits,
    // so an unconfigured install resolves to nothing rather than fataling.
    expect(fleetopsGuardInvoke($provider, 'apiKey'))->toBeNull();

    config()->set('services.google_maps.api_key', 'configured-key');
    expect(fleetopsGuardInvoke($provider, 'apiKey'))->toBe('configured-key');

    config()->set('services.google_maps.api_key', null);
});

test('route sequencing skips orders without a payload and waypoints without a place', function () {
    $engine = new RouteSequencingEngine();

    // Nothing to route at all
    $payloadless = new Order();
    $payloadless->setRawAttributes(['uuid' => 'order-guard-1', 'public_id' => 'order_guardone'], true);
    $payloadless->setRelation('payload', null);

    // A multi-drop payload whose only waypoint points at a place that is gone
    $orphanWaypoint = new Waypoint();
    $orphanWaypoint->setRawAttributes(['uuid' => 'wp-guard-1', 'order' => 1], true);
    $orphanWaypoint->setRelation('place', null);

    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-guard-1', 'pickup_uuid' => null, 'dropoff_uuid' => null], true);
    $payload->setRelation('waypoints', collect([$orphanWaypoint]));

    $placeless = new Order();
    $placeless->setRawAttributes(['uuid' => 'order-guard-2', 'public_id' => 'order_guardtwo'], true);
    $placeless->setRelation('payload', $payload);

    // Neither order contributes a stop, and neither aborts the sequencing run
    expect(fleetopsGuardInvoke($engine, '_sequenceOrdersForVehicle', [[$payloadless, $placeless], null, null]))->toBe([]);
});
