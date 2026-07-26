<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\LiveController;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FleetOpsLiveQueryRecorder
{
    public array $calls = [];

    public function whereNotNull(string $column): self
    {
        $this->calls[] = ['whereNotNull', $column];

        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->calls[] = ['whereRaw', trim($sql), $bindings];

        return $this;
    }
}

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
    $controller = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Internal/v1/LiveController.php');

    expect($controller)->toContain('protected function applyLiveLocationGuards')
        ->and($controller)->toContain('protected function applyLiveViewportBounds')
        ->and($controller)->toContain('ST_Y(location) BETWEEN ? AND ?')
        ->and($controller)->toContain('ST_X(location) BETWEEN ? AND ?')
        ->and($controller)->not->toContain('ST_MakeEnvelope')
        ->and($controller)->not->toContain('ST_GeomFromText');
});

test('live controller applies location guards and optional viewport bounds', function () {
    $query = new FleetOpsLiveQueryRecorder();

    callLiveControllerMethod('applyLiveLocationGuards', [$query]);
    callLiveControllerMethod('applyLiveViewportBounds', [$query, [1.1, 103.2, 1.4, 103.9]]);
    callLiveControllerMethod('applyLiveViewportBounds', [$query, null]);

    expect($query->calls)->toBe([
        ['whereNotNull', 'location'],
        [
            'whereRaw',
            'ST_Y(location) BETWEEN -90 AND 90
                AND ST_X(location) BETWEEN -180 AND 180
                AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)',
            [],
        ],
        [
            'whereRaw',
            'ST_Y(location) BETWEEN ? AND ? AND ST_X(location) BETWEEN ? AND ?',
            [1.1, 1.4, 103.2, 103.9],
        ],
    ]);
});

test('live controller builds operations monitor fleet trees', function () {
    $fleetNodes = new Collection([
        'root' => [
            'uuid'              => 'root',
            'parent_fleet_uuid' => null,
            'subfleets'         => [],
        ],
        'child' => [
            'uuid'              => 'child',
            'parent_fleet_uuid' => 'root',
            'subfleets'         => [],
        ],
        'orphan' => [
            'uuid'              => 'orphan',
            'parent_fleet_uuid' => 'missing',
            'subfleets'         => [],
        ],
    ]);

    expect(callLiveControllerMethod('buildOperationsMonitorFleetTree', [$fleetNodes]))->toBe([
        [
            'uuid'              => 'root',
            'parent_fleet_uuid' => null,
            'subfleets'         => [
                [
                    'uuid'              => 'child',
                    'parent_fleet_uuid' => 'root',
                    'subfleets'         => [],
                ],
            ],
        ],
        [
            'uuid'              => 'orphan',
            'parent_fleet_uuid' => 'missing',
            'subfleets'         => [],
        ],
    ]);
});
