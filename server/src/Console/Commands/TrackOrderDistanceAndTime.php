<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class TrackOrderDistanceAndTime extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Options:
     *  --provider=           : distance/time provider (calculate, google, osrm). Defaults to config(fleetops.distance_matrix.provider)
     *  --days=               : only include orders created in last N days (default: 2)
     *  --chunk=              : records per chunk (default: 250)
     *  --no-lock             : skip single-run lock (not recommended)
     *  --dry                 : dry-run, do not mutate anything
     */
    protected $signature = 'fleetops:update-estimations
        {--provider= : The distance and time calculation provider (calculate, google, or osrm)}
        {--days=2 : Only include orders created in the last N days}
        {--chunk=250 : How many orders to process per chunk}
        {--no-lock : Skip process locking}
        {--dry : Dry run (don\'t persist changes)}';

    protected $description = 'Track and update order distance and time estimations for recent, active orders';

    public function handle(): int
    {
        // Always operate in UTC
        date_default_timezone_set('UTC');

        $provider = $this->option('provider') ?: (string) config('fleetops.distance_matrix.provider');
        $days     = max(1, (int) $this->option('days'));           // guardrail
        $perChunk = max(50, (int) $this->option('chunk'));         // sane lower bound
        $dryRun   = (bool) $this->option('dry');
        $useLock  = !$this->option('no-lock');

        $this->info("Using provider: {$provider}");
        $this->info("Looking back: last {$days} day(s)");
        $this->info("Chunk size: {$perChunk}" . ($dryRun ? ' (dry-run)' : ''));

        // Prevent overlapping executions (e.g., cron overlap)
        $lock = null;
        if ($useLock) {
            $lock = Cache::lock('fleetops:update-estimations', 3600); // 1h lock window
            if (!$lock->get()) {
                $this->warn('Another run appears to be in progress (lock active). Use --no-lock to bypass.');

                return self::SUCCESS;
            }
        }

        try {
            $cutoff = Carbon::now()->subDays($days);

            // Build the base query (no data loaded yet)
            $base = $this->activeOrdersQuery($cutoff);

            // Count total to set up a progress bar without loading all rows
            $total = (clone $base)->count('id');
            if ($total === 0) {
                $this->info('No qualifying orders found. Exiting.');

                return self::SUCCESS;
            }

            $this->alert('Found ' . number_format($total) . ' orders to update. Current Time: ' . Carbon::now()->toDateTimeString());
            $bar = $this->output->createProgressBar($total);
            $bar->start();

            $updated = 0;
            $errors  = 0;

            // Stream through the dataset in stable primary-key order
            $base->orderBy('id')->chunkById($perChunk, function ($orders) use ($provider, $dryRun, $bar, &$updated, &$errors) {
                // Eager-load relationships per chunk to avoid N+1
                $orders->load(['payload', 'payload.waypoints', 'payload.pickup', 'payload.dropoff']);

                foreach ($orders as $order) {
                    try {
                        if (!$dryRun) {
                            $order->setDistanceAndTime(['provider' => $provider]);
                        }
                        $updated++;
                    } catch (\Throwable $e) {
                        $errors++;
                        $this->error("Order {$order->id} failed: {$e->getMessage()}");
                    } finally {
                        $bar->advance();
                    }
                }

                // Optional: small micro-sleep can smooth out DB/API burstiness
                // usleep(20000); // 20ms
            });

            $bar->finish();
            $this->newLine(2);

            $this->info(($dryRun ? '[Dry Run] ' : '') . "Updated {$updated}/" . number_format($total) . ' orders.');
            if ($errors > 0) {
                $this->warn("Encountered {$errors} error(s). Check logs for details.");
            }

            return self::SUCCESS;
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    /**
     * Base query for "active" orders in the last N days.
     *
     * Conditions:
     * - Not completed/canceled
     * - Not soft-deleted
     * - Has company & started_at
     * - Has payload
     * - created_at >= cutoff
     * - Without global scopes (as in original)
     */
    protected function activeOrdersQuery(Carbon $cutoff)
    {
        return Order::query()
            ->withoutGlobalScopes()
            ->whereNotIn('status', ['completed', 'canceled'])
            ->whereNull('deleted_at')
            ->whereNotNull('company_uuid')
            ->whereNotNull('started_at')
            ->where('created_at', '>=', $cutoff)
            ->whereHas('payload');
    }
}
