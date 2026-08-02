<?php

if (!function_exists('config')) {
    function config($key = null, $default = null)
    {
        static $values = [];

        if (is_array($key)) {
            $values = array_merge($values, $key);

            return null;
        }

        return $values[$key] ?? $default;
    }
}

if (!function_exists('session')) {
    function session($key = null, $default = null)
    {
        static $values = [];

        if (is_array($key)) {
            $values = array_merge($values, $key);

            return null;
        }

        if ($key === null) {
            return new class($values) {
                public function __construct(private array $values)
                {
                }

                public function missing($key): bool
                {
                    return !array_key_exists($key, $this->values);
                }
            };
        }

        return $values[$key] ?? $default;
    }
}

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return \config($key, $default); }');
}

if (!function_exists('Fleetbase\Support\app')) {
    eval('namespace Fleetbase\Support; function app($abstract = null) { return $abstract ? \Illuminate\Container\Container::getInstance()->make($abstract) : \Illuminate\Container\Container::getInstance(); }');
}

if (!function_exists('Fleetbase\Support\config')) {
    eval('namespace Fleetbase\Support; function config($key = null, $default = null) { return match ($key) { "fleetbase.console.host" => "console.fleetbase.test", "fleetbase.console.secure" => true, "fleetbase.console.subdomain" => null, default => \config($key, $default) }; }');
}

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Notifications\OrderCompleted;
use Fleetbase\FleetOps\Notifications\OrderDispatched;
use Fleetbase\FleetOps\Notifications\OrderFailed;
use Illuminate\Container\Container;

class FleetOpsTerminalNotificationOrderFake extends Order
{
    public string $uuid      = 'order-uuid';
    public string $public_id = 'order_public';
    public string $tracking  = 'ORDER-TRACK';
}

function fleetopsTerminalNotificationOrder(): Order
{
    $order = new FleetOpsTerminalNotificationOrderFake();
    $order->setRelation('company', (object) [
        'uuid'      => 'company-uuid',
        'public_id' => 'company-public',
    ]);

    return $order;
}

function fleetopsTerminalNotificationWaypoint(string $tracking = 'WP-TRACK'): Waypoint
{
    $waypoint = new Waypoint();
    $waypoint->setRawAttributes([
        'uuid'      => 'waypoint-uuid',
        'public_id' => 'waypoint_public',
        'tracking'  => $tracking,
    ], true);
    $waypoint->setRelation('trackingNumber', (object) [
        'tracking_number' => $tracking,
    ]);

    return $waypoint;
}

function fleetopsTerminalNotificationWithConsoleConfig(callable $callback): mixed
{
    $previousApp = Container::getInstance();
    $app         = new class extends Container {
        public function environment(...$environments)
        {
            return false;
        }
    };

    if ($previousApp->bound('config')) {
        $app->instance('config', $previousApp->make('config'));
    }

    Container::setInstance($app);

    try {
        return $callback();
    } finally {
        Container::setInstance($previousApp);
    }
}

test('completed order notification formats mail and array payloads from order tracking fallback', function () {
    session([
        'company'        => 'company-session',
        'api_credential' => 'api-credential',
    ]);

    $notification = new OrderCompleted(fleetopsTerminalNotificationOrder());
    $mail         = fleetopsTerminalNotificationWithConsoleConfig(fn () => $notification->toMail(null));

    expect($notification->title)->toBe('Order ORDER-TRACK has been completed.')
        ->and($notification->message)->toBe('Order ORDER-TRACK has been completed by agent.')
        ->and($notification->data)->toBe(['id' => 'order_public', 'type' => 'order_completed'])
        ->and($notification->toArray())->toMatchArray([
            'event' => 'order.completed_notification',
            'title' => 'Order ORDER-TRACK has been completed.',
            'body'  => 'Order ORDER-TRACK has been completed by agent.',
        ])
        ->and($mail->subject)->toBe('Order ORDER-TRACK has been completed.')
        ->and($mail->introLines)->toContain(
            'Order ORDER-TRACK has been completed by agent.',
            'No further action is necessary.'
        )
        ->and($mail->actionText)->toBe('Track Order')
        ->and($mail->actionUrl)->toContain('order=ORDER-TRACK');
});

test('failed order notification formats mail and array payloads from waypoint tracking', function () {
    $order        = fleetopsTerminalNotificationOrder();
    $waypoint     = fleetopsTerminalNotificationWaypoint('WP-FAILED');
    $notification = new OrderFailed($order, 'recipient unavailable', $waypoint);
    $mail         = fleetopsTerminalNotificationWithConsoleConfig(fn () => $notification->toMail(null));

    expect($notification->title)->toBe('Order WP-FAILED delivery has has failed')
        ->and($notification->message)->toBe('Order WP-FAILED delivery has failed.')
        ->and($notification->reason)->toBe('recipient unavailable')
        ->and($notification->data)->toBe(['id' => 'order_public', 'type' => 'order_canceled'])
        ->and($notification->toArray())->toMatchArray([
            'event' => 'order.failed_notification',
            'title' => 'Order WP-FAILED delivery has has failed',
            'body'  => 'Order WP-FAILED delivery has failed. recipient unavailable',
        ])
        ->and($mail->subject)->toBe('Order WP-FAILED delivery has has failed')
        ->and($mail->introLines)->toContain(
            'Order WP-FAILED delivery has failed.',
            'recipient unavailable',
            'No further action is necessary.'
        )
        ->and($mail->actionText)->toBe('Track Order')
        ->and($mail->actionUrl)->toContain('order=WP-FAILED');
});

test('dispatched order notification formats mail from waypoint tracking fallback', function () {
    $order        = fleetopsTerminalNotificationOrder();
    $waypoint     = fleetopsTerminalNotificationWaypoint('WP-DISPATCHED');
    $notification = new OrderDispatched($order, $waypoint);
    $mail         = fleetopsTerminalNotificationWithConsoleConfig(fn () => $notification->toMail(null));

    expect($notification->title)->toBe('Order WP-DISPATCHED has been dispatched!')
        ->and($notification->message)->toBe('An order has just been dispatched to you and is ready to be started.')
        ->and($notification->data)->toBe(['id' => 'order_public', 'type' => 'order_dispatched'])
        ->and($mail->subject)->toBe('Order WP-DISPATCHED has been dispatched!')
        ->and($mail->introLines)->toBe(['An order has just been dispatched to you and is ready to be started.'])
        ->and($mail->actionText)->toBe('Track Order')
        ->and($mail->actionUrl)->toContain('order=WP-DISPATCHED');
});
