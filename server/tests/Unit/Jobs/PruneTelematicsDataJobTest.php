<?php

use Fleetbase\FleetOps\Console\Commands\PruneTelematicsData;
use Fleetbase\FleetOps\Jobs\PruneTelematicsDataJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The on-demand cleanup job is unique per company and runs the prune command
 * directly, without the console kernel's command registry.
 */
class FleetOpsPruneCommandRunRecorder extends PruneTelematicsData
{
    public array $runs = [];

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $this->runs[] = (string) $input;

        return 0;
    }
}

test('prune job is unique per company and runs the command for that company without the process lock', function () {
    $recorder = new FleetOpsPruneCommandRunRecorder();
    app()->instance(PruneTelematicsData::class, $recorder);
    try {
        $job = new PruneTelematicsDataJob('company-1');
        expect($job)->toBeInstanceOf(ShouldQueue::class)->toBeInstanceOf(ShouldBeUnique::class)
            ->and($job->uniqueId())->toBe('company-1')
            ->and($job->uniqueFor)->toBe(900)
            ->and($job->tries)->toBe(1)
            ->and($job->parameters())->toBe(['--no-lock' => true, '--max-batches' => 200, '--company' => 'company-1']);
        $job->handle();

        (new PruneTelematicsDataJob(null, 25))->handle();
        expect((new PruneTelematicsDataJob())->uniqueId())->toBe('all')
            ->and($recorder->runs)->toBe([
                '--no-lock=1 --max-batches=200 --company=company-1',
                '--no-lock=1 --max-batches=25',
            ])
            ->and($recorder->getLaravel())->toBe(app());
    } finally {
        app()->forgetInstance(PruneTelematicsData::class);
    }
});
