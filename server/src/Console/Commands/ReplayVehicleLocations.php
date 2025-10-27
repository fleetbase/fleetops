<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Carbon\Carbon;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Support\SocketCluster\SocketClusterService;
use Illuminate\Console\Command;

class ReplayVehicleLocations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vehicle:replay-locations 
                            {file : Path to the JSON file containing vehicle location data}
                            {--speed=1 : Speed multiplier for replay (1 = real-time, 2 = 2x speed, 0.5 = half speed)}
                            {--vehicle= : Filter by specific vehicle ID (optional)}
                            {--limit= : Limit the number of events to process (optional)}
                            {--sleep= : Set a manual sleep for replay (in seconds)}
                            {--skip-sleep : Skip sleep delays and send all events immediately}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Replay vehicle location events from JSON file with timing simulation via SocketCluster';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $filePath        = $this->argument('file');
        $speedMultiplier = (float) $this->option('speed');
        $vehicleFilter   = $this->option('vehicle');
        $limit           = $this->option('limit') ? (int) $this->option('limit') : null;
        $skipSleep       = $this->option('skip-sleep');
        $sleep           = $this->option('sleep') ? (int) $this->option('sleep') : null;

        // Validate file exists
        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return Command::FAILURE;
        }

        // Validate speed multiplier
        if ($speedMultiplier <= 0) {
            $this->error('Speed multiplier must be greater than 0');

            return Command::FAILURE;
        }

        $this->info('Starting vehicle location replay...');
        $this->info("File: {$filePath}");
        $this->info("Speed: {$speedMultiplier}x");

        if ($vehicleFilter) {
            $this->info("Filtering for vehicle: {$vehicleFilter}");
        }

        if ($skipSleep) {
            $this->warn('Sleep delays disabled - sending all events immediately');
        }

        // Load and parse JSON data
        $this->info('Loading location data...');
        $jsonContent    = file_get_contents($filePath);
        $locationEvents = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Failed to parse JSON: ' . json_last_error_msg());

            return Command::FAILURE;
        }

        if (!is_array($locationEvents) || empty($locationEvents)) {
            $this->error('Invalid or empty location data');

            return Command::FAILURE;
        }

        // Filter by vehicle if specified
        if ($vehicleFilter) {
            $locationEvents = array_filter($locationEvents, function ($event) use ($vehicleFilter) {
                return isset($event['data']['id']) && $event['data']['id'] === $vehicleFilter;
            });
            $locationEvents = array_values($locationEvents); // Re-index array
        }

        $totalEvents = count($locationEvents);

        if ($totalEvents === 0) {
            $this->warn('No events found matching the criteria');

            return Command::SUCCESS;
        }

        // Apply limit if specified
        if ($limit && $limit < $totalEvents) {
            $locationEvents = array_slice($locationEvents, 0, $limit);
            $totalEvents    = $limit;
        }

        $this->info("Total events to process: {$totalEvents}");
        $this->newLine();

        // Initialize SocketCluster client
        $socketClusterClient = new SocketClusterService();

        // Statistics tracking
        $successCount      = 0;
        $errorCount        = 0;
        $startTime         = microtime(true);
        $previousTimestamp = null;

        // Process each location event
        foreach ($locationEvents as $index => $event) {
            $eventNumber = $index + 1;
            $vehicleId   = $event['data']['id'] ?? 'unknown';
            $eventId     = $event['id'] ?? 'unknown';
            $createdAt   = $event['created_at'] ?? null;

            // Get vehicle record
            $vehicle = Vehicle::where('public_id', $vehicleId)->first();
            if (!$vehicle) {
                continue;
            }

            // Calculate sleep duration based on timestamp difference
            if (!$skipSleep && $previousTimestamp !== null && $createdAt !== null) {
                try {
                    $currentTime   = Carbon::parse($createdAt);
                    $previousTime  = Carbon::parse($previousTimestamp);
                    $diffInSeconds = $currentTime->diffInSeconds($previousTime);

                    // Apply speed multiplier
                    $sleepDuration = $diffInSeconds / $speedMultiplier;

                    if ($sleep) {
                        $this->info("[{$eventNumber}/{$totalEvents}] Waiting {$sleep}s (real: {$diffInSeconds}s)...");
                        sleep((int) $sleep);
                    } elseif ($sleepDuration > 0) {
                        $this->info("[{$eventNumber}/{$totalEvents}] Waiting {$sleepDuration}s (real: {$diffInSeconds}s)...");
                        sleep((int) $sleepDuration);

                        // Handle fractional seconds
                        $fractional = $sleepDuration - floor($sleepDuration);
                        if ($fractional > 0) {
                            usleep((int) ($fractional * 1000000));
                        }
                    }
                } catch (\Exception $e) {
                    $this->warn("Failed to calculate time difference: {$e->getMessage()}");
                }
            }

            // Update previous timestamp
            $previousTimestamp = $createdAt;

            // Prepare channel names
            $channels = ["vehicle.{$vehicleId}", "vehicle.{$vehicle->uuid}"];

            foreach ($channels as $channel) {
                // Send event via SocketCluster
                try {
                    $sent = $socketClusterClient->send($channel, $event);

                    $location = $event['data']['location']['coordinates'] ?? ['N/A', 'N/A'];
                    $speed    = $event['data']['speed'] ?? 'N/A';
                    $heading  = $event['data']['heading'] ?? 'N/A';

                    $this->line(sprintf(
                        "[{$eventNumber}/{$totalEvents}] ✓ Sent event %s for vehicle %s | Channel: %s | Coords: [%.6f, %.6f] | Speed: %s | Heading: %s | Time: %s",
                        $eventId,
                        $vehicleId,
                        $channel,
                        $location[0],
                        $location[1],
                        $speed,
                        $heading,
                        $createdAt ?? 'N/A'
                    ));

                    $successCount++;
                } catch (\WebSocket\ConnectionException $e) {
                    $this->error("[{$eventNumber}/{$totalEvents}] ✗ Connection error for event {$eventId}: {$e->getMessage()}");
                    $errorCount++;
                } catch (\WebSocket\TimeoutException $e) {
                    $this->error("[{$eventNumber}/{$totalEvents}] ✗ Timeout error for event {$eventId}: {$e->getMessage()}");
                    $errorCount++;
                } catch (\Throwable $e) {
                    $this->error("[{$eventNumber}/{$totalEvents}] ✗ Error for event {$eventId}: {$e->getMessage()}");
                    $errorCount++;
                }
            }
        }

        // Summary
        $endTime  = microtime(true);
        $duration = round($endTime - $startTime, 2);

        $this->newLine();
        $this->info('=== Replay Complete ===');
        $this->info("Total events processed: {$totalEvents}");
        $this->info("Successful: {$successCount}");

        if ($errorCount > 0) {
            $this->error("Failed: {$errorCount}");
        } else {
            $this->info("Failed: {$errorCount}");
        }

        $this->info("Duration: {$duration}s");
        $this->info('Average rate: ' . round($totalEvents / max($duration, 0.001), 2) . ' events/second');

        return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
