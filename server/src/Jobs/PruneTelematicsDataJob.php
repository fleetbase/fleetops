<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Console\Commands\PruneTelematicsData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs a bounded telematics prune for one company or orphaned data.
 *
 * The command is executed directly rather than through the console kernel so a
 * worker started before this package version can still run it.
 */
class PruneTelematicsDataJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $uniqueFor      = 900;
    public int $tries          = 1;
    // Finish or fail before Laravel's baseline Redis retry_after of 90 seconds.
    public int $timeout        = 80;
    public bool $failOnTimeout = true;
    public bool $orphansOnly   = false;

    public function __construct(public ?string $companyUuid = null, public int $maxBatches = 200, bool $orphansOnly = false)
    {
        $this->orphansOnly = $orphansOnly;
        $this->onQueue(config('telematics.telemetry.ingestion_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return $this->orphansOnly ? 'orphans' : ($this->companyUuid ?? 'all');
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        $parameters = ['--no-lock' => true, '--max-batches' => $this->maxBatches];
        if ($this->companyUuid) {
            $parameters['--company'] = $this->companyUuid;
        }
        if ($this->orphansOnly) {
            $parameters['--orphans-only'] = true;
        }

        return $parameters;
    }

    public function handle(): void
    {
        $command = $this->command();
        $command->setLaravel(app());
        $exitCode = $command->run(new ArrayInput($this->parameters()), new BufferedOutput());
        if ($exitCode !== PruneTelematicsData::SUCCESS) {
            throw new \RuntimeException('Telematics cleanup failed with exit code ' . $exitCode . '.');
        }
    }

    protected function command(): PruneTelematicsData
    {
        return app(PruneTelematicsData::class);
    }
}
