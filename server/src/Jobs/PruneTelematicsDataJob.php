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
 * Runs the telematics prune for one company on demand ("Run cleanup now").
 *
 * The command is executed directly rather than through the console kernel so a
 * worker started before this package version can still run it.
 */
class PruneTelematicsDataJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $uniqueFor = 900;
    public int $tries     = 1;
    public int $timeout   = 600;

    public function __construct(public ?string $companyUuid = null, public int $maxBatches = 200)
    {
    }

    public function uniqueId(): string
    {
        return $this->companyUuid ?? 'all';
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

        return $parameters;
    }

    public function handle(): void
    {
        $command = $this->command();
        $command->setLaravel(app());
        $command->run(new ArrayInput($this->parameters()), new BufferedOutput());
    }

    protected function command(): PruneTelematicsData
    {
        return app(PruneTelematicsData::class);
    }
}
