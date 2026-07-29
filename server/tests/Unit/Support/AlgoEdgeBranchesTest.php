<?php

use Fleetbase\FleetOps\Support\Algo;

/**
 * Covers the Algo expression evaluator edge branches: unrounded results,
 * min/ceil/floor/round helper functions, and non-numeric helper arguments
 * falling back to float casts.
 */
test('algo evaluates unrounded expressions and helper function arms', function () {
    // Unrounded evaluation returns the raw numeric result
    expect((float) Algo::exec('3.14159 * 2', [], false))->toBeGreaterThan(6.28)
        ->and(Algo::exec('1 + 1', [], true))->toBe(2.0);

    // Helper functions cover the min/ceil/floor/round arms
    expect(Algo::exec('min(4, 9)', [], true))->toBe(4.0)
        ->and(Algo::exec('max(4, 9)', [], true))->toBe(9.0)
        ->and(Algo::exec('ceil(4.2)', [], true))->toBe(5.0)
        ->and(Algo::exec('floor(4.8)', [], true))->toBe(4.0)
        ->and(Algo::exec('round(4.567, 2)', [], true))->toBe(4.57);

    // Non-evaluable helper arguments fall back to float casts
    expect(Algo::exec('max(oops, 3)', [], true))->toBe(3.0);
});
