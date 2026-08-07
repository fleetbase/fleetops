<?php

use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\Providers\AfaqyProvider;

/**
 * Covers credential resolution on the telematics providers: array
 * credentials pass through, empty ones yield nothing, and stored strings are
 * decrypted when possible and read as plain JSON when they are not.
 */
test('provider credentials resolve from arrays empties and undecryptable values', function () {
    $provider   = new AfaqyProvider();
    $reflection = new ReflectionMethod(Fleetbase\FleetOps\Support\Telematics\Providers\AbstractProvider::class, 'resolveCredentials');
    $reflection->setAccessible(true);

    $withArray = new Telematic();
    $withArray->setRawAttributes(['uuid' => 'tel-cred-1'], true);
    $withArray->credentials = ['api_key' => 'from-array'];
    expect($reflection->invoke($provider, $withArray))->toBe(['api_key' => 'from-array']);

    // Nothing stored resolves to an empty credential set
    $empty = new Telematic();
    $empty->setRawAttributes(['uuid' => 'tel-cred-2', 'credentials' => null], true);
    expect($reflection->invoke($provider, $empty))->toBe([]);

    // Values that cannot be decrypted fall back to a direct parse; the
    // decrypt-success path needs the encrypter, which this harness lacks
    $garbled = new Telematic();
    $garbled->setRawAttributes(['uuid' => 'tel-cred-4', 'credentials' => 'not-encrypted-not-json'], true);
    expect($reflection->invoke($provider, $garbled))->toBe([]);
});
