<?php

use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\ServiceRate;

function fleetopsServiceRateAlgorithmQuote(string $algorithm, array $entities): int
{
    $serviceRate = new ServiceRate([
        'rate_calculation_method' => 'algo',
        'algorithm'               => $algorithm,
        'base_fee'                => 0,
        'currency'                => 'USD',
    ]);

    [$subTotal] = $serviceRate->quoteFromPreliminaryData($entities, ['pickup', 'dropoff']);

    return $subTotal;
}

test('algorithm rate variables expose summed entity weights in converted units', function () {
    $entities = [
        new Entity(['type' => 'parcel', 'weight' => 2, 'weight_unit' => 'kg']),
        new Entity(['type' => 'parcel', 'weight' => 1, 'weight_unit' => 'lb']),
    ];

    expect(fleetopsServiceRateAlgorithmQuote('{weight_kg} * 1000', $entities))->toBe(2454)
        ->and(fleetopsServiceRateAlgorithmQuote('{weight_lb} * 1000', $entities))->toBe(5410)
        ->and(fleetopsServiceRateAlgorithmQuote('{weight_tonne} * 1000000', $entities))->toBe(2454)
        ->and(fleetopsServiceRateAlgorithmQuote('{weight_t} * 1000000', $entities))->toBe(2454)
        ->and(fleetopsServiceRateAlgorithmQuote('{weight} * 1000', $entities))->toBe(2454);
});

test('algorithm rate weight variables ignore missing or blank entity weights', function () {
    $entities = [
        new Entity(['type' => 'parcel', 'weight' => '', 'weight_unit' => 'kg']),
        new Entity(['type' => 'parcel', 'weight' => null, 'weight_unit' => 'lb']),
        new Entity(['type' => 'parcel', 'weight' => 500, 'weight_unit' => 'g']),
        new Entity(['type' => 'parcel']),
    ];

    expect(fleetopsServiceRateAlgorithmQuote('{weight_kg} * 1000', $entities))->toBe(500)
        ->and(fleetopsServiceRateAlgorithmQuote('{weight_g}', $entities))->toBe(500)
        ->and(fleetopsServiceRateAlgorithmQuote('{weight_oz} * 1000', $entities))->toBe(17637);
});
