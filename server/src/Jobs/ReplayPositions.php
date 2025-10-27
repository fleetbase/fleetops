<?php

namespace Fleetbase\FleetOps\Jobs;

use Carbon\Carbon;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\Support\SocketCluster\SocketClusterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReplayPositions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var \Illuminate\Support\Collection<int, Position> */
    protected $positions;
    protected string $channelId;
    protected float $speed;
    protected ?string $subjectUuid;

    /** Max runtime per worker (seconds) */
    public $timeout = 3600;

    /**
     * @param \Illuminate\Support\Collection<int, Position> $positions
     */
    public function __construct($positions, string $channelId, float $speed = 1, ?string $subjectUuid = null)
    {
        $this->positions   = $positions;
        $this->channelId   = $channelId;
        $this->speed       = max($speed, 0.1);
        $this->subjectUuid = $subjectUuid;
    }

    public function handle(): void
    {
        $socket = new SocketClusterService();

        // Base timestamp to compute relative offsets
        $baseTime = Carbon::parse($this->positions->first()->created_at);

        foreach ($this->positions as $index => $position) {
            $currentTime = Carbon::parse($position->created_at);
            $offset      = $baseTime->diffInSeconds($currentTime, false) / $this->speed;

            // Schedule a small job for each event with its own delay
            SendPositionReplay::dispatch(
                $this->channelId,
                $position,
                $index,
                $this->subjectUuid
            )->delay(now()->addSeconds(max(0, $offset)));
        }

        Log::info("Replay scheduled for {$this->positions->count()} positions on channel {$this->channelId}");
    }
}
