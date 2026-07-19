<?php

use Fleetbase\FleetOps\Casts\Point;
use Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor;
use Fleetbase\FleetOps\Support\Encoding\Polyline;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderDescriptor;
use Fleetbase\FleetOps\Tracking\TrackingProviderCapabilities;

test('fuel provider descriptors apply defaults and serialize configured metadata', function () {
    $descriptor = new FuelProviderDescriptor([
        'key'                => 'fleet_card',
        'description'        => 'Fleet card transactions.',
        'required_fields'    => [['name' => 'api_key']],
        'capabilities'       => ['vehicles', 'transactions'],
        'sync_defaults'      => ['window_days' => 14],
        'setup_instructions' => ['Connect the account.'],
        'metadata'           => ['region' => 'mena'],
    ]);

    expect($descriptor->label)->toBe('fleet_card')
        ->and($descriptor->type)->toBe('native')
        ->and($descriptor->driverClass)->toBeNull()
        ->and($descriptor->docsUrl)->toBeNull()
        ->and($descriptor->category)->toBeNull()
        ->and($descriptor->icon)->toBe('gas-pump')
        ->and($descriptor->toArray())->toMatchArray([
            'key'                => 'fleet_card',
            'label'              => 'fleet_card',
            'type'               => 'native',
            'description'        => 'Fleet card transactions.',
            'required_fields'    => [['name' => 'api_key']],
            'capabilities'       => ['vehicles', 'transactions'],
            'sync_defaults'      => ['window_days' => 14],
            'setup_instructions' => ['Connect the account.'],
            'metadata'           => ['region' => 'mena'],
        ]);
});

test('telematic provider descriptors include defaults and webhook metadata', function () {
    $descriptor = new TelematicProviderDescriptor([
        'key'                => 'safee',
        'label'              => 'Safee',
        'driver_class'       => TestTelematicDriver::class,
        'required_fields'    => [['name' => 'token']],
        'supports_webhooks'  => true,
        'supports_discovery' => true,
        'metadata'           => ['region' => 'global'],
    ]);

    $array = $descriptor->toArray();

    expect($descriptor->type)->toBe('native')
        ->and($descriptor->icon)->toBe(TelematicProviderDescriptor::DEFAULT_ICON)
        ->and($array)->toMatchArray([
            'key'                => 'safee',
            'label'              => 'Safee',
            'type'               => 'native',
            'required_fields'    => [['name' => 'token']],
            'supports_webhooks'  => true,
            'supports_discovery' => true,
            'metadata'           => ['region' => 'global'],
        ])
        ->and($array['webhook_url'])->toContain('webhooks/telematics/safee')
        ->and(json_decode($descriptor->toJson(), true))->toMatchArray($array);
});

test('tracking provider capabilities merge standard and provider-specific capabilities', function () {
    $capabilities = new TrackingProviderCapabilities(
        traffic: true,
        perLegEta: true,
        mapMatching: false,
        routeGeometry: true,
        extras: ['snap_to_road' => true],
    );

    expect($capabilities->toArray())->toBe([
        'traffic'        => true,
        'per_leg_eta'    => true,
        'map_matching'   => false,
        'route_geometry' => true,
        'snap_to_road'   => true,
    ])->and($capabilities->jsonSerialize())->toBe($capabilities->toArray());
});

test('point cast helpers normalize coordinate geometry and raw binary detection', function () {
    expect(Point::coordinatesBboxToFloat([
        'type'        => 'Point',
        'coordinates' => ['106.917', '47.918'],
        'bbox'        => ['106', '47', '107', '48'],
    ]))->toBe([
        'type'        => 'Point',
        'coordinates' => [106.917, 47.918],
        'bbox'        => [106.0, 47.0, 107.0, 48.0],
    ])
        ->and(Point::isRawPoint("abc\x00def"))->toBeTrue()
        ->and(Point::isRawPoint('plain text'))->toBeFalse()
        ->and(Point::isRawPoint(['not' => 'a string']))->toBeFalse()
        ->and(Point::hex2str('48656c6c6f'))->toBe('Hello');
});

test('polyline encoding round trips flattened and paired coordinate lists', function () {
    $points  = [[38.5, -120.2], [40.7, -120.95], [43.252, -126.453]];
    $encoded = Polyline::encode($points);

    expect($encoded)->toBe('_p~iF~ps|U_ulLnnqC_mqNvxq`@')
        ->and(Polyline::flatten($points))->toBe([38.5, -120.2, 40.7, -120.95, 43.252, -126.453])
        ->and(Polyline::pair([1, 2, 3, 4]))->toBe([[1, 2], [3, 4]])
        ->and(Polyline::pair('not a list'))->toBe([]);
});

class TestTelematicDriver
{
}

