<?php

use Fleetbase\FleetOps\Events\TrailerLocationChanged;
use Fleetbase\FleetOps\Jobs\SyncTelematicDevicesJob;
use Fleetbase\FleetOps\Jobs\TestTelematicConnectionJob;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Configuration;

/**
 * Covers per-instance queue routing for connection-level telematics jobs and
 * telematics-originated broadcasts. Unset configuration must keep the defaults.
 */
afterEach(fn () => config(['telematics.telemetry.poll_queue' => 'default', 'telematics.telemetry.broadcast_queue' => null]));

test('legacy discovery and connection test jobs follow the configured poll queue', function (?string $configured, string $expected) {
    config(['telematics.telemetry.poll_queue' => $configured]);
    $telematic = new Telematic();

    expect((new SyncTelematicDevicesJob($telematic))->queue)->toBe($expected)
        ->and((new TestTelematicConnectionJob($telematic))->queue)->toBe($expected)
        ->and(Configuration::pollQueue())->toBe($expected);
})->with([
    'unset'     => [null, 'default'],
    'default'   => ['default', 'default'],
    'dedicated' => ['telematics', 'telematics'],
]);

test('broadcast queue is applied only when configured', function () {
    $event = (new ReflectionClass(TrailerLocationChanged::class))->newInstanceWithoutConstructor();

    expect(Configuration::withBroadcastQueue($event)->broadcastQueue)->toBeNull();

    config(['telematics.telemetry.broadcast_queue' => 'telematics-broadcasts']);
    expect(Configuration::withBroadcastQueue($event))->toBe($event)
        ->and($event->broadcastQueue)->toBe('telematics-broadcasts');
});
