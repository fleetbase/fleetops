<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Carbon\Carbon;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Waypoint;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\SQLiteConnection;

class FleetOpsManifestStopModelProbe extends ManifestStop
{
    public array $updates = [];

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

class FleetOpsManifestStopManifestProbe extends Manifest
{
    public int $autoCompleteChecks = 0;

    public function checkAndAutoComplete(): void
    {
        $this->autoCompleteChecks++;
    }
}

function fleetopsManifestStopUseRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

test('manifest stop relationship contracts resolve expected models', function () {
    fleetopsManifestStopUseRelationConnection();

    $stop = new ManifestStop();

    expect($stop->manifest())->toBeInstanceOf(BelongsTo::class)
        ->and($stop->manifest()->getRelated())->toBeInstanceOf(Manifest::class)
        ->and($stop->order())->toBeInstanceOf(BelongsTo::class)
        ->and($stop->order()->getRelated())->toBeInstanceOf(Order::class)
        ->and($stop->place())->toBeInstanceOf(BelongsTo::class)
        ->and($stop->place()->getRelated())->toBeInstanceOf(Place::class)
        ->and($stop->waypoint())->toBeInstanceOf(BelongsTo::class)
        ->and($stop->waypoint()->getRelated())->toBeInstanceOf(Waypoint::class);
});

test('manifest stop exposes tracking number and address fallbacks', function () {
    $trackingNumber = (object) ['tracking_number' => 'TRACK-001'];
    $dropoff        = (object) ['address' => 'Payload dropoff address'];
    $payload        = (object) ['dropoff' => $dropoff];

    $order = new Order();
    $order->setRelation('trackingNumber', $trackingNumber);
    $order->setRelation('payload', $payload);

    $place = new Place();
    $place->setRawAttributes(['street1' => 'Place address'], true);

    $stop = new ManifestStop();
    $stop->setRelation('order', $order);
    $stop->setRelation('place', $place);

    expect($stop->tracking_number)->toBe('TRACK-001')
        ->and($stop->address)->toBe('PLACE ADDRESS');

    $stop->unsetRelation('place');

    expect($stop->address)->toBe('Payload dropoff address')
        ->and((new ManifestStop())->tracking_number)->toBeNull()
        ->and((new ManifestStop())->address)->toBeNull();
});

test('manifest stop status helpers update state and trigger manifest checks', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-04 09:30:00'));

    $manifest = new FleetOpsManifestStopManifestProbe();
    $stop     = new FleetOpsManifestStopModelProbe();
    $stop->setRelation('manifest', $manifest);

    expect($stop->markArrived())->toBe($stop)
        ->and($stop->updates[0]['status'])->toBe('arrived')
        ->and($stop->updates[0]['actual_arrival']->toDateTimeString())->toBe('2026-08-04 09:30:00')
        ->and($stop->status)->toBe('arrived');

    expect($stop->markCompleted())->toBe($stop)
        ->and($stop->updates[1])->toBe(['status' => 'completed'])
        ->and($manifest->autoCompleteChecks)->toBe(1)
        ->and($stop->status)->toBe('completed');

    expect($stop->markSkipped())->toBe($stop)
        ->and($stop->updates[2])->toBe(['status' => 'skipped'])
        ->and($manifest->autoCompleteChecks)->toBe(2)
        ->and($stop->status)->toBe('skipped');

    Carbon::setTestNow();
});
