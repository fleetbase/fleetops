<?php

use Fleetbase\FleetOps\Models\Asset;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\PlaceSearch;

class FleetOpsUpdatingAssetFake extends Asset
{
    public array $updates = [];

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return false;
    }
}

class FleetOpsSearchPlaceFake extends Place
{
    public ?float $latitudeFake  = null;
    public ?float $longitudeFake = null;
    public ?object $locationFake = null;

    public function getAddressAttribute()
    {
        return $this->attributes['address'] ?? $this->attributes['street1'] ?? null;
    }

    public function getAttribute($key)
    {
        if ($key === 'latitude') {
            return $this->latitudeFake;
        }

        if ($key === 'longitude') {
            return $this->longitudeFake;
        }

        if ($key === 'location') {
            return $this->locationFake;
        }

        return parent::getAttribute($key);
    }

    public function toAddressString($except = [], $useHtml = false)
    {
        return collect([
            in_array('name', $except, true) ? null : $this->name,
            $this->street1,
            $this->city,
            $this->postal_code,
        ])->filter()->implode(', ');
    }
}

function fleetopsPlaceSearchInvoke(string $method, array $arguments = [])
{
    $reflection = new ReflectionMethod(PlaceSearch::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs(null, $arguments);
}

function fleetopsSearchPlace(array $attributes, ?float $latitude = null, ?float $longitude = null): FleetOpsSearchPlaceFake
{
    $place                = new FleetOpsSearchPlaceFake($attributes);
    $place->latitudeFake  = $latitude;
    $place->longitudeFake = $longitude;

    return $place;
}

test('asset accessors display location online and update guards are stable', function () {
    $asset = new FleetOpsUpdatingAssetFake([
        'public_id'    => 'asset_public',
        'make'         => 'Freightliner',
        'model'        => 'Cascadia',
        'year'         => 2024,
        'code'         => 'TRK-24',
        'odometer'     => 1000,
        'engine_hours' => 250,
    ]);

    $asset->setRelation('category', (object) ['name' => 'Tractors']);
    $asset->setRelation('vendor', (object) ['name' => 'Fleet Vendor']);
    $asset->setRelation('warranty', (object) ['name' => 'Warranty Plan']);
    $asset->setRelation('photo', (object) ['url' => 'https://cdn.example/asset.png']);
    $asset->setRelation('telematic', (object) [
        'is_online'     => true,
        'last_location' => ['latitude' => 1.30, 'longitude' => 103.80],
    ]);
    $asset->setRelation('currentPlace', (object) [
        'name'      => 'Depot',
        'address'   => '1 Depot Road',
        'latitude'  => 1.31,
        'longitude' => 103.81,
    ]);

    expect($asset->category_name)->toBe('Tractors')
        ->and($asset->vendor_name)->toBe('Fleet Vendor')
        ->and($asset->warranty_name)->toBe('Warranty Plan')
        ->and($asset->photo_url)->toBe('https://cdn.example/asset.png')
        ->and($asset->display_name)->toBe('Freightliner Cascadia 2024 TRK-24')
        ->and($asset->is_online)->toBeTrue()
        ->and($asset->current_location)->toMatchArray([
            'name'      => 'Depot',
            'address'   => '1 Depot Road',
            'latitude'  => 1.31,
            'longitude' => 103.81,
        ])
        ->and($asset->getUtilizationRate(10))->toBe(100.0)
        ->and($asset->updateOdometer(900))->toBeFalse()
        ->and($asset->updateEngineHours(200))->toBeFalse();

    $asset->setRelation('currentPlace', null);
    expect($asset->current_location)->toBe(['latitude' => 1.30, 'longitude' => 103.80])
        ->and($asset->updateOdometer(1200))->toBeFalse()
        ->and($asset->updateEngineHours(300))->toBeFalse()
        ->and($asset->updates)->toBe([
            ['odometer' => 1200],
            ['engine_hours' => 300],
        ]);

    $unnamed = new Asset();
    $unnamed->forceFill(['public_id' => 'asset_fallback']);
    expect($unnamed->display_name)->toBe('Asset #asset_fallback');
});

test('place search normalizes ranks deduplicates and prefers strong saved matches', function () {
    $exact = fleetopsSearchPlace([
        'name'        => 'Central Depot',
        'street1'     => '1 Depot Road',
        'city'        => 'Singapore',
        'postal_code' => '10001',
    ], 1.300001, 103.800001);
    $prefix = fleetopsSearchPlace([
        'name'        => 'Central Annex',
        'street1'     => '2 Depot Road',
        'city'        => 'Singapore',
        'postal_code' => '10002',
    ], 1.31, 103.81);
    $contains = fleetopsSearchPlace([
        'name'        => 'North Central Yard',
        'street1'     => '3 Depot Road',
        'city'        => 'Singapore',
        'postal_code' => '10003',
    ], 1.32, 103.82);
    $miss = fleetopsSearchPlace([
        'name'        => 'Remote Yard',
        'street1'     => '4 Far Road',
        'city'        => 'Singapore',
        'postal_code' => '10004',
    ], 1.33, 103.83);

    expect(fleetopsPlaceSearchInvoke('normalizeSearchQuery', ['  CENTRAL   Depot  ']))->toBe('central depot')
        ->and(fleetopsPlaceSearchInvoke('placeQueryRank', [$exact, 'central depot']))->toBe(0)
        ->and(fleetopsPlaceSearchInvoke('placeQueryRank', [$prefix, 'central']))->toBe(1)
        ->and(fleetopsPlaceSearchInvoke('placeQueryRank', [$contains, 'central']))->toBe(2)
        ->and(fleetopsPlaceSearchInvoke('placeQueryRank', [$miss, 'central']))->toBe(3)
        ->and(fleetopsPlaceSearchInvoke('placeQueryRank', [$miss, null]))->toBe(4)
        ->and(fleetopsPlaceSearchInvoke('isStrongSavedMatch', [$exact, 'central depot']))->toBeTrue()
        ->and(fleetopsPlaceSearchInvoke('isStrongSavedMatch', [$contains, 'central']))->toBeFalse();

    $ranked = fleetopsPlaceSearchInvoke('rankPlacesByQuery', [collect([$miss, $contains, $prefix, $exact]), 'central']);
    expect($ranked->values()->all())->toBe([$prefix, $exact, $contains, $miss]);

    $geoDuplicate = fleetopsSearchPlace([
        'name'        => 'Geo Depot',
        'street1'     => '1 Depot Road',
        'city'        => 'Singapore',
        'postal_code' => '10001',
    ], 1.300001, 103.800001);

    $merged = fleetopsPlaceSearchInvoke('mergeResults', [
        collect([$geoDuplicate, $prefix]),
        collect([$exact, $contains]),
        'central depot',
    ]);

    expect($merged->values()->all())->toBe([$exact, $prefix, $contains])
        ->and(fleetopsPlaceSearchInvoke('uniquePlaces', [collect([$exact, $geoDuplicate, null, $prefix])])->values()->all())
        ->toBe([$exact, $prefix]);
});

test('place search keys support coordinates location fallback and empty geocode input', function () {
    $place = fleetopsSearchPlace([
        'name'    => 'Coordinate Place',
        'street1' => '1 Coordinate Road',
    ], 1.123456, 103.987654);

    expect(fleetopsPlaceSearchInvoke('placeKey', [$place]))->toBe('1 coordinate road|1.12346,103.98765');

    $placeWithoutDirectCoordinates = fleetopsSearchPlace([
        'name'    => 'Location Place',
        'street1' => null,
    ]);
    $placeWithoutDirectCoordinates->locationFake = new class {
        public function getLat(): float
        {
            return 2.111119;
        }

        public function getLng(): float
        {
            return 104.222229;
        }
    };

    expect(fleetopsPlaceSearchInvoke('placeKey', [$placeWithoutDirectCoordinates]))->toBe('location place|2.11112,104.22223')
        ->and(fleetopsPlaceSearchInvoke('placeKey', [fleetopsSearchPlace([])]))->toBeNull()
        ->and(PlaceSearch::geocode())->toHaveCount(0);
});
