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
        if (!$this->fileExists($filePath)) {
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

        $this->info('Loading location data...');
        [$locationEvents, $parseError] = $this->loadLocationEventsFromFile($filePath);
        if ($parseError) {
            $this->error($parseError);

            return Command::FAILURE;
        }

        if (empty($locationEvents)) {
            $this->error('Invalid or empty location data');

            return Command::FAILURE;
        }

        $locationEvents = $this->filterEventsForVehicle($locationEvents, $vehicleFilter);
        $totalEvents    = count($locationEvents);

        if ($totalEvents === 0) {
            $this->warn('No events found matching the criteria');

            return Command::SUCCESS;
        }

        $locationEvents = $this->applyEventLimit($locationEvents, $limit);
        $totalEvents    = count($locationEvents);

        $this->info("Total events to process: {$totalEvents}");
        $this->newLine();

        // Initialize SocketCluster client
        $socketClusterClient = $this->socketClusterClient();

        // Statistics tracking
        $successCount      = 0;
        $errorCount        = 0;
        $startTime         = $this->currentMicrotime();
        $previousTimestamp = null;

        // Process each location event
        foreach ($locationEvents as $index => $event) {
            $eventNumber = $index + 1;
            $vehicleId   = $event['data']['id'] ?? 'unknown';
            $eventId     = $event['id'] ?? 'unknown';
            $createdAt   = $event['created_at'] ?? null;

            // Get vehicle record
            $vehicle = $this->vehicleForPublicId($vehicleId);
            if (!$vehicle) {
                continue;
            }

            // Calculate sleep duration based on timestamp difference
            if (!$skipSleep && $previousTimestamp !== null && $createdAt !== null) {
                try {
                    [$diffInSeconds, $sleepDuration] = $this->calculateReplayDelay($previousTimestamp, $createdAt, $speedMultiplier);

                    if ($sleep) {
                        $this->info("[{$eventNumber}/{$totalEvents}] Waiting {$sleep}s (real: {$diffInSeconds}s)...");
                        $this->sleepSeconds((int) $sleep);
                    } elseif ($sleepDuration > 0) {
                        $this->info("[{$eventNumber}/{$totalEvents}] Waiting {$sleepDuration}s (real: {$diffInSeconds}s)...");
                        $this->sleepSeconds((int) $sleepDuration);

                        // Handle fractional seconds
                        $fractional = $sleepDuration - floor($sleepDuration);
                        if ($fractional > 0) {
                            $this->sleepMicroseconds((int) ($fractional * 1000000));
                        }
                    }
                } catch (\Exception $e) {
                    $this->warn("Failed to calculate time difference: {$e->getMessage()}");
                }
            }

            // Update previous timestamp
            $previousTimestamp = $createdAt;

            $channels = $this->channelsForVehicle($vehicleId, $vehicle);

            foreach ($channels as $channel) {
                // Send event via SocketCluster
                try {
                    $sent = $socketClusterClient->send($channel, $event);

                    $this->line($this->formatSentLine($eventNumber, $totalEvents, $eventId, $vehicleId, $channel, $event, $createdAt));

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
        $endTime  = $this->currentMicrotime();
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

    protected function loadLocationEventsFromFile(string $filePath): array
    {
        $locationEvents = json_decode(file_get_contents($filePath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [[], 'Failed to parse JSON: ' . json_last_error_msg()];
        }

        if (!is_array($locationEvents)) {
            return [[], null];
        }

        return [$locationEvents, null];
    }

    protected function fileExists(string $filePath): bool
    {
        return file_exists($filePath);
    }

    protected function socketClusterClient(): mixed
    {
        return new SocketClusterService();
    }

    protected function vehicleForPublicId(string $vehicleId): ?Vehicle
    {
        return Vehicle::where('public_id', $vehicleId)->first();
    }

    protected function sleepSeconds(int $seconds): void
    {
        sleep($seconds);
    }

    protected function sleepMicroseconds(int $microseconds): void
    {
        usleep($microseconds);
    }

    protected function currentMicrotime(): float
    {
        return microtime(true);
    }

    protected function filterEventsForVehicle(array $locationEvents, ?string $vehicleFilter): array
    {
        if (!$vehicleFilter) {
            return array_values($locationEvents);
        }

        return array_values(array_filter($locationEvents, function ($event) use ($vehicleFilter) {
            return isset($event['data']['id']) && $event['data']['id'] === $vehicleFilter;
        }));
    }

    protected function applyEventLimit(array $locationEvents, ?int $limit): array
    {
        if ($limit && $limit < count($locationEvents)) {
            return array_slice($locationEvents, 0, $limit);
        }

        return $locationEvents;
    }

    protected function calculateReplayDelay(string $previousTimestamp, string $createdAt, float $speedMultiplier): array
    {
        $currentTime   = Carbon::parse($createdAt);
        $previousTime  = Carbon::parse($previousTimestamp);
        $diffInSeconds = $currentTime->diffInSeconds($previousTime);

        return [$diffInSeconds, $diffInSeconds / $speedMultiplier];
    }

    protected function channelsForVehicle(string $vehicleId, Vehicle $vehicle): array
    {
        return ["vehicle.{$vehicleId}", "vehicle.{$vehicle->uuid}"];
    }

    protected function formatSentLine(int $eventNumber, int $totalEvents, string $eventId, string $vehicleId, string $channel, array $event, ?string $createdAt): string
    {
        $location = $event['data']['location']['coordinates'] ?? ['N/A', 'N/A'];
        $speed    = $event['data']['speed'] ?? 'N/A';
        $heading  = $event['data']['heading'] ?? 'N/A';

        return sprintf(
            "[{$eventNumber}/{$totalEvents}] ✓ Sent event %s for vehicle %s | Channel: %s | Coords: [%.6f, %.6f] | Speed: %s | Heading: %s | Time: %s",
            $eventId,
            $vehicleId,
            $channel,
            $location[0],
            $location[1],
            $speed,
            $heading,
            $createdAt ?? 'N/A'
        );
    }
}
