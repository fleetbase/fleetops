<?php

use Fleetbase\FleetOps\Support\Geocoding;

/**
 * Covers Geocoding::locate(): provider failures are re-thrown to the caller
 * rather than swallowed, so a broken geocoder surfaces instead of silently
 * returning an empty result set.
 */
test('locate rethrows geocoder provider failures', function () {
    app()->instance('fleetops.geocoder', new class {
        public function geocodeQuery($query)
        {
            throw new RuntimeException('geocoder provider unavailable');
        }

        public function reverseQuery($query)
        {
            throw new RuntimeException('geocoder provider unavailable');
        }

        public function __call($method, $arguments)
        {
            throw new RuntimeException('geocoder provider unavailable');
        }
    });

    expect(fn () => Geocoding::locate('1 Marina Bay', 1.30, 103.80))
        ->toThrow(RuntimeException::class, 'geocoder provider unavailable');

    app()->forgetInstance('fleetops.geocoder');
});
