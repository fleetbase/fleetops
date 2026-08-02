<?php

use Fleetbase\FleetOps\Jobs\ReplayPositions;
use Fleetbase\FleetOps\Jobs\SendPositionReplay;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\TestSupport\DispatchRecorder;

/**
 * Covers the ReplayPositions job: relative offset computation from the first
 * position, per-position replay dispatching with speed scaling, and the
 * completion log.
 */
function fleetopsReplayBoot(): void
{
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
}

function fleetopsReplayPosition(string $createdAt): Position
{
    $position = new Position();
    $position->setRawAttributes(['uuid' => 'position-' . md5($createdAt), 'created_at' => $createdAt], true);

    return $position;
}

test('handle dispatches a replay job per position with scaled offsets', function () {
    fleetopsReplayBoot();
    DispatchRecorder::$dispatched = [];

    $positions = collect([
        fleetopsReplayPosition('2026-08-01 10:00:00'),
        fleetopsReplayPosition('2026-08-01 10:00:30'),
        fleetopsReplayPosition('2026-08-01 10:01:00'),
    ]);

    $job = new ReplayPositions($positions, 'channel-1', 2.0, 'subject-1');
    $job->handle();

    expect(DispatchRecorder::$dispatched)->toHaveCount(3)
        ->and(DispatchRecorder::$dispatched[0]['job'])->toBe(SendPositionReplay::class)
        ->and(DispatchRecorder::$dispatched[0]['arguments'][0])->toBe('channel-1')
        ->and(DispatchRecorder::$dispatched[1]['arguments'][3])->toBe('subject-1');
});

test('constructor clamps the replay speed to a minimum', function () {
    fleetopsReplayBoot();
    DispatchRecorder::$dispatched = [];
    $positions                    = collect([fleetopsReplayPosition('2026-08-01 10:00:00')]);

    $job = new ReplayPositions($positions, 'channel-1', 0.0);
    $job->handle();

    expect(DispatchRecorder::$dispatched)->toHaveCount(1);
});
