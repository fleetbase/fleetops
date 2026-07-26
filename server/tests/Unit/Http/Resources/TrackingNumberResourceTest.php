<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Support\config')) {
    eval('namespace Fleetbase\FleetOps\Support; function config($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\Support\app')) {
    eval('namespace Fleetbase\Support; function app() { return new class { public function environment(array $environments) { return true; } }; }');
}

if (!function_exists('Fleetbase\Support\config')) {
    eval('namespace Fleetbase\Support; function config($key = null, $default = null) { return match ($key) { "fleetbase.console.host" => "console.test", "fleetbase.console.subdomain" => null, "fleetbase.console.secure" => false, default => $default }; }');
}

use Fleetbase\FleetOps\Http\Resources\v1\TrackingNumber as TrackingNumberResource;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

class FleetOpsTrackingNumberResourceRequestState
{
    public static ?Request $request = null;
}

if (!class_exists('FleetOpsSupportRequestState')) {
    class FleetOpsSupportRequestState
    {
        public static ?Request $request = null;
    }
}

if (!function_exists('request')) {
    function request($key = null, $default = null)
    {
        return FleetOpsTrackingNumberResourceRequestState::$request;
    }
}

if (!function_exists('Fleetbase\Support\request')) {
    eval('namespace Fleetbase\Support; function request($key = null, $default = null) { return \FleetOpsSupportRequestState::$request; }');
}

class FleetOpsTrackingNumberResourceRouteFixture
{
    public function __construct(private string $uri)
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }
}

class FleetOpsTrackingNumberResourceModelFake extends TrackingNumber
{
    public function getLastStatusAttribute(): string
    {
        return 'In Transit';
    }

    public function getLastStatusCodeAttribute(): string
    {
        return 'in_transit';
    }
}

function fleetopsTrackingNumberResourceRequest(bool $internal): Request
{
    fleetopsTrackingNumberResourceUseConnection();

    $uri     = $internal ? 'api/int/v1/fleet-ops/tracking-numbers/trk_public' : 'api/v1/fleet-ops/tracking-numbers/trk_public';
    $request = Request::create('/' . $uri, 'GET');
    $request->setRouteResolver(fn () => new FleetOpsTrackingNumberResourceRouteFixture($uri));
    FleetOpsTrackingNumberResourceRequestState::$request = $request;
    FleetOpsSupportRequestState::$request                = $request;

    if (function_exists('app')) {
        app()->instance('request', $request);
    }

    return $request;
}

function fleetopsTrackingNumberResourceUseConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

function fleetopsTrackingNumberResourceFixture(): TrackingNumber
{
    $trackingNumber = new FleetOpsTrackingNumberResourceModelFake();
    $trackingNumber->setRawAttributes([
        'id'              => 42,
        'uuid'            => 'tracking-uuid',
        'public_id'       => 'trk_public',
        'status_uuid'     => 'status-uuid',
        'owner_uuid'      => 'order-uuid',
        'owner_type'      => 'Fleetbase\\FleetOps\\Models\\Order',
        'tracking_number' => 'TN-RESOURCE',
        'region'          => 'sg',
        'qr_code'         => 'qr-data',
        'barcode'         => 'barcode-data',
        'created_at'      => '2026-07-27 08:00:00',
        'updated_at'      => '2026-07-27 09:00:00',
    ], true);
    $trackingNumber->setRelation('owner', (object) ['public_id' => 'order_public']);

    return $trackingNumber;
}

test('tracking number resource serializes public tracking fields', function () {
    $request = fleetopsTrackingNumberResourceRequest(false);
    $payload = (new TrackingNumberResource(fleetopsTrackingNumberResourceFixture()))->resolve($request);

    expect($payload)->toMatchArray([
        'id'              => 'trk_public',
        'tracking_number' => 'TN-RESOURCE',
        'subject'         => 'order_public',
        'region'          => 'sg',
        'status'          => 'In Transit',
        'status_code'     => 'in_transit',
        'qr_code'         => 'qr-data',
        'barcode'         => 'barcode-data',
        'type'            => 'order',
    ])
        ->and($payload)->not->toHaveKeys(['uuid', 'public_id', 'status_uuid', 'owner_uuid', 'owner_type'])
        ->and($payload['url'])->toContain('TN-RESOURCE');
});

test('tracking number resource includes internal identifiers for internal requests', function () {
    $request = fleetopsTrackingNumberResourceRequest(true);
    $payload = (new TrackingNumberResource(fleetopsTrackingNumberResourceFixture()))->resolve($request);

    expect($payload)->toMatchArray([
        'id'          => 42,
        'uuid'        => 'tracking-uuid',
        'public_id'   => 'trk_public',
        'status_uuid' => 'status-uuid',
        'owner_uuid'  => 'order-uuid',
        'owner_type'  => 'fleet-ops:order',
    ]);
});

test('tracking number resource serializes webhook payload fields', function () {
    $payload = (new TrackingNumberResource(fleetopsTrackingNumberResourceFixture()))->toWebhookPayload();

    expect($payload)->toMatchArray([
        'id'              => 'trk_public',
        'tracking_number' => 'TN-RESOURCE',
        'subject'         => 'order_public',
        'region'          => 'sg',
        'qr_code'         => 'qr-data',
        'barcode'         => 'barcode-data',
        'type'            => 'order',
    ]);
});
