<?php

use Fleetbase\FleetOps\Support\VehicleData;

class VehicleDataHarness extends VehicleData
{
    public static function getMakeModel2015Data(): array
    {
        return [
            'Ford'   => [
                ['model' => 'Transit', 2015],
                ['model' => 'F-150', 2015],
            ],
            'Toyota' => [
                ['model' => 'Hiace', 2015],
            ],
        ];
    }
}

test('vehicle data parser extracts known makes models and years', function () {
    expect(VehicleDataHarness::parse('2018 Ford Transit refrigerated van'))->toBe([
        'make'  => 'Ford',
        'model' => 'Transit',
        'year'  => '2018',
    ])
        ->and(VehicleDataHarness::parse('Toyota Hiace 2020'))->toBe([
            'make'  => 'Toyota',
            'model' => 'Hiace',
            'year'  => '2020',
        ]);
});

test('vehicle data parser falls back to remaining text as model', function () {
    expect(VehicleDataHarness::parse('2017 Ford custom box truck'))->toBe([
        'make'  => 'Ford',
        'model' => 'Custom Box Truck',
        'year'  => '2017',
    ])
        ->and(VehicleDataHarness::parse('Unlisted Yard Tractor'))->toBe([
            'make'  => null,
            'model' => 'Unlisted Yard Tractor',
            'year'  => null,
        ]);
});
