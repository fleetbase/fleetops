<?php

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\FleetOps;
use Fleetbase\FleetOps\Support\Geocoding;
use Illuminate\Support\Str;

function fleetopsTransportConfigDefaults(): array
{
    $reflection = new ReflectionMethod(FleetOps::class, 'transportConfigDefaults');
    $reflection->setAccessible(true);

    return $reflection->invoke(null);
}

class FleetOpsGeocodingResultFake
{
    public function __construct(private array $addresses)
    {
    }

    public function all(): array
    {
        return $this->addresses;
    }
}

class FleetOpsGeocodingClientFake
{
    public array $geocodeQueries = [];
    public array $reverseQueries = [];

    public function __construct(private array $geocodeResults = [], private array $reverseResults = [])
    {
    }

    public function geocodeQuery($query): FleetOpsGeocodingResultFake
    {
        $this->geocodeQueries[] = $query;

        return new FleetOpsGeocodingResultFake($this->geocodeResults);
    }

    public function reverseQuery($query): FleetOpsGeocodingResultFake
    {
        $this->reverseQueries[] = $query;

        return new FleetOpsGeocodingResultFake($this->reverseResults);
    }
}

class FleetOpsGeocodingProbe extends Geocoding
{
    public static ?FleetOpsGeocodingClientFake $client = null;

    protected static function makeGeocoder(): object
    {
        return static::$client ??= new FleetOpsGeocodingClientFake();
    }

    protected static function makePlaceFromGoogleAddress($googleAddress): Place
    {
        $place = new Place();
        $place->setRawAttributes(['street1' => $googleAddress], true);

        return $place;
    }
}

test('fleet ops support builds the default transport config metadata', function () {
    $defaults = fleetopsTransportConfigDefaults();

    expect($defaults)->toMatchArray([
        'name'         => 'Transport',
        'key'          => 'transport',
        'namespace'    => 'system:order-config:transport',
        'description'  => 'Default order configuration for transport',
        'core_service' => 1,
        'status'       => 'private',
        'version'      => '0.0.1',
        'tags'         => ['transport', 'delivery'],
        'entities'     => [],
        'meta'         => [],
    ])
        ->and(array_keys($defaults['flow']))->toBe([
            'created',
            'enroute',
            'started',
            'completed',
            'dispatched',
        ]);
});

test('fleet ops support default transport flow links statuses in order', function () {
    $flow = fleetopsTransportConfigDefaults()['flow'];

    expect($flow['created'])->toMatchArray([
        'key'         => 'created',
        'code'        => 'created',
        'status'      => 'Order Created',
        'details'     => 'New order was created.',
        'complete'    => false,
        'activities'  => ['dispatched'],
        'pod_method'  => 'scan',
        'require_pod' => false,
    ])
        ->and($flow['dispatched']['activities'])->toBe(['started'])
        ->and($flow['started']['activities'])->toBe(['enroute'])
        ->and($flow['enroute']['activities'])->toBe(['completed'])
        ->and($flow['completed'])->toMatchArray([
            'status'     => 'Order Completed',
            'complete'   => true,
            'activities' => [],
        ]);
});

test('fleet ops support assigns unique internal ids to every default transport status', function () {
    $flow        = fleetopsTransportConfigDefaults()['flow'];
    $internalIds = array_column($flow, 'internalId');

    expect($internalIds)->toHaveCount(5)
        ->and(array_unique($internalIds))->toHaveCount(5);

    foreach ($internalIds as $internalId) {
        expect(Str::isUuid((string) $internalId))->toBeTrue();
    }
});

test('geocoding support handles disabled and empty coordinate queries without external calls', function () {
    app('config')->set('services.google_maps.api_key', null);

    expect(Geocoding::canGoogleGeocode())->toBeFalse();
});

test('geocoding support maps forward and reverse provider results to places', function () {
    FleetOpsGeocodingProbe::$client = new FleetOpsGeocodingClientFake(
        ['1 Forward Street', '2 Forward Street'],
        ['1 Reverse Street', '2 Reverse Street']
    );

    $forward          = FleetOpsGeocodingProbe::geocode('Depot', 1.3, 103.8);
    $reverseFromQuery = FleetOpsGeocodingProbe::reverseFromQuery('Depot', 1.3, 103.8);
    $reverseNearby    = FleetOpsGeocodingProbe::reverseFromCoordinates(1.3, 103.8, 'Depot');
    $reverseBare      = FleetOpsGeocodingProbe::reverseFromCoordinates(1.3, 103.8);

    expect($forward->pluck('street1')->all())->toBe(['1 Forward Street', '2 Forward Street'])
        ->and($reverseFromQuery->pluck('street1')->all())->toBe(['1 Reverse Street', '2 Reverse Street'])
        ->and($reverseNearby->pluck('street1')->all())->toBe(['1 Reverse Street', '2 Reverse Street'])
        ->and($reverseBare->pluck('street1')->all())->toBe(['1 Reverse Street', '2 Reverse Street'])
        ->and(FleetOpsGeocodingProbe::$client->geocodeQueries)->toHaveCount(1)
        ->and(FleetOpsGeocodingProbe::$client->reverseQueries)->toHaveCount(3);
});

test('geocoding support returns empty collections before reverse provider calls when input is missing', function () {
    FleetOpsGeocodingProbe::$client = new FleetOpsGeocodingClientFake(
        ['1 Forward Street'],
        ['1 Reverse Street']
    );

    expect(FleetOpsGeocodingProbe::reverseFromQuery('', 1.3, 103.8))->toHaveCount(0)
        ->and(FleetOpsGeocodingProbe::reverseFromQuery('Depot', null, null))->toHaveCount(0)
        ->and(FleetOpsGeocodingProbe::reverseFromCoordinates(null, null))->toHaveCount(0)
        ->and(FleetOpsGeocodingProbe::$client->reverseQueries)->toHaveCount(0);
});

test('geocoding support merges locate and query results by unique street', function () {
    FleetOpsGeocodingProbe::$client = new FleetOpsGeocodingClientFake(
        ['Shared Street', 'Forward Only Street'],
        ['Shared Street', 'Reverse Only Street']
    );

    $locate = FleetOpsGeocodingProbe::locate('Depot', 1.3, 103.8);
    $query  = FleetOpsGeocodingProbe::query('Depot', 1.3, 103.8);

    expect($locate->pluck('street1')->values()->all())->toBe([
        'Shared Street',
        'Reverse Only Street',
        'Forward Only Street',
    ])
        ->and($query->pluck('street1')->values()->all())->toBe([
            'Shared Street',
            'Reverse Only Street',
            'Forward Only Street',
        ])
        ->and(FleetOpsGeocodingProbe::$client->geocodeQueries)->toHaveCount(2)
        ->and(FleetOpsGeocodingProbe::$client->reverseQueries)->toHaveCount(2);
});
