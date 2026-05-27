<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\LiveController;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Http\Request;

function callLiveControllerMethod(string $method, array $arguments = [])
{
    $reflection = new ReflectionMethod(LiveController::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs(new LiveController(), $arguments);
}

test('live viewport bounds are normalized for stable cache keys', function () {
    $request = new Request([
        'bounds' => ['1.234567', '103.876543', '1.345678', '103.987654'],
    ]);

    expect(callLiveControllerMethod('normalizeLiveBounds', [$request]))
        ->toBe([1.2346, 103.8765, 1.3457, 103.9877]);
});

test('invalid live viewport bounds fall back to unbounded queries', function ($bounds) {
    $request = new Request(['bounds' => $bounds]);

    expect(callLiveControllerMethod('normalizeLiveBounds', [$request]))->toBeNull();
})->with([
    'missing coordinate' => [[1, 2, 3]],
    'non numeric'        => [[1, 'west', 3, 4]],
    'invalid latitude'   => [[-91, 103, 1, 104]],
    'invalid longitude'  => [[1, -181, 2, 104]],
    'inverted latitude'  => [[2, 103, 1, 104]],
    'inverted longitude' => [[1, 104, 2, 103]],
]);

test('live viewport limit defaults and clamps', function () {
    expect(callLiveControllerMethod('normalizeLiveLimit', [new Request()]))->toBe(500)
        ->and(callLiveControllerMethod('normalizeLiveLimit', [new Request(['limit' => 25])]))->toBe(25)
        ->and(callLiveControllerMethod('normalizeLiveLimit', [new Request(['limit' => 0])]))->toBe(500)
        ->and(callLiveControllerMethod('normalizeLiveLimit', [new Request(['limit' => 5000])]))->toBe(1000);
});

test('live viewport query avoids spatial constructors with fixed srids', function () {
    $query = Driver::query();

    callLiveControllerMethod('applyLiveLocationGuards', [$query]);
    callLiveControllerMethod('applyLiveViewportBounds', [$query, [1.2, 103.8, 1.4, 104.0]]);
    $query->orderByDesc('updated_at')->orderByDesc('id')->limit(25);

    $sql = $query->toSql();

    expect($sql)->toContain('ST_Y(location) BETWEEN ? AND ?')
        ->and($sql)->toContain('ST_X(location) BETWEEN ? AND ?')
        ->and($sql)->toContain('limit 25')
        ->and($sql)->not->toContain('ST_MakeEnvelope')
        ->and($sql)->not->toContain('ST_GeomFromText');

    expect($query->getBindings())->toContain(1.2, 1.4, 103.8, 104.0);
});
