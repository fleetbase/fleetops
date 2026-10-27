<?php

use Fleetbase\FleetOps\Models\Order;

/**
 * scheduled_at and the time window keep the instant a caller sends: a timestamp with a UTC offset
 * is converted to the application timezone before the datetime cast drops the offset.
 */
test('scheduled_at with an offset is stored as the same instant in the app timezone', function () {
    config()->set('app.timezone', 'UTC');
    $order = new Order();

    $order->scheduled_at = '2026-10-21T11:00:00-07:00';
    expect($order->getAttributes()['scheduled_at'])->toBe('2026-10-21 18:00:00');

    $order->scheduled_at = '2026-10-21T18:00:00Z';
    expect($order->getAttributes()['scheduled_at'])->toBe('2026-10-21 18:00:00');

    // Values without an offset are read in the app timezone and stored unchanged
    $order->scheduled_at = '2026-10-21 09:30:00';
    expect($order->getAttributes()['scheduled_at'])->toBe('2026-10-21 09:30:00');

    $order->scheduled_at = new DateTimeImmutable('2026-10-21 11:00:00', new DateTimeZone('America/Los_Angeles'));
    expect($order->getAttributes()['scheduled_at'])->toBe('2026-10-21 18:00:00');

    // Unix timestamps, as an integer or a numeric string from a form body, are the same instant
    $order->scheduled_at = 1792605600;
    expect($order->getAttributes()['scheduled_at'])->toBe('2026-10-21 18:00:00');

    $order->scheduled_at = '1792605600';
    expect($order->getAttributes()['scheduled_at'])->toBe('2026-10-21 18:00:00');

    $order->scheduled_at = '';
    expect($order->getAttributes()['scheduled_at'])->toBeNull()
        ->and(Order::toStorageDateTime(null))->toBeNull();
});

test('time windows with an offset are converted too, and epoch-dated times take the order date', function () {
    config()->set('app.timezone', 'UTC');
    $order = new Order();

    $order->time_window_start = '2026-10-21T09:00:00-07:00';
    expect($order->getAttributes()['time_window_start'])->toBe('2026-10-21 16:00:00');

    $order->scheduled_at    = '2026-10-23 08:00:00';
    $order->time_window_end = '1970-01-01 11:00:00';
    expect($order->getAttributes()['time_window_end'])->toBe('2026-10-23 11:00:00');
});
