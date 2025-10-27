<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Models\Position;
use Fleetbase\Support\SocketCluster\SocketClusterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPositionReplay implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected string $channelId;
    protected Position $position;
    protected int $index;
    protected ?string $subjectUuid;

    public $tries   = 3;
    public $timeout = 60;

    public function __construct(string $channelId, Position $position, int $index, ?string $subjectUuid = null)
    {
        $this->channelId   = $channelId;
        $this->position    = $position;
        $this->index       = $index;
        $this->subjectUuid = $subjectUuid;
    }

    public function handle(): void
    {
        $socket = new SocketClusterService();

        $eventData = [
            'id'          => uniqid('event_'),
            'api_version' => config('api.version'),
            'event'       => 'position.simulated',
            'created_at'  => $this->position->created_at->toDateTimeString(),
            'data'        => [
                'id'             => $this->subjectUuid ?? $this->position->subject_uuid,
                'location'       => $this->position->coordinates,
                'heading'        => $this->position->heading ?? 0,
                'speed'          => $this->position->speed ?? 0,
                'altitude'       => $this->position->altitude ?? 0,
                'additionalData' => [
                    'index'         => $this->index,
                    'position_uuid' => $this->position->uuid,
                ],
            ],
        ];

        try {
            $socket->send($this->channelId, $eventData);
        } catch (\Throwable $e) {
            Log::error("Failed to send replay event [{$this->position->uuid}]: {$e->getMessage()}");
        }
    }
}
