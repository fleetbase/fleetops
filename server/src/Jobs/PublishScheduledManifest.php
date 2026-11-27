<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Support\Dispatch\ManifestPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Carries out a publish booked with `publish_at`. Queued with a delay to that
 * time; it publishes only if the draft is still booked for the same time.
 */
class PublishScheduledManifest implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $manifestUuid, public string $publishAt)
    {
    }

    public function handle(ManifestPublisher $publisher): void
    {
        $manifest = $this->findManifest();
        if (!$manifest) {
            return;
        }

        $publisher->publishIfDue($manifest, $this->publishAt);
    }

    protected function findManifest(): ?Manifest
    {
        /** @var Manifest|null $manifest */
        $manifest = Manifest::where('uuid', $this->manifestUuid)->first();

        return $manifest;
    }
}
