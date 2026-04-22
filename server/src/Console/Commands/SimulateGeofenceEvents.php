<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Carbon\Carbon;
use Fleetbase\FleetOps\Events\GeofenceDwelled;
use Fleetbase\FleetOps\Events\GeofenceEntered;
use Fleetbase\FleetOps\Events\GeofenceExited;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SimulateGeofenceEvents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:simulate-geofence
                            {subject : Subject public_id or uuid}
                            {geofence : Geofence public_id or uuid}
                            {events=sequence : entered|exited|dwelled|sequence or comma-separated list}
                            {--repeat=1 : Number of times to repeat the event sequence}
                            {--sleep=0 : Seconds to sleep between events}
                            {--dwell-minutes=10 : Minutes to use for simulated dwell timing}
                            {--reset-state : Reset the geofence state before running}
                            {--no-log : Skip dispatching Laravel geofence events, update state only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate geofence events for a driver or vehicle against a zone or service area';

    public function handle(): int
    {
        $events        = $this->parseEvents($this->argument('events'));
        $repeat        = max(1, (int) $this->option('repeat'));
        $sleep         = max(0, (int) $this->option('sleep'));
        $dwellMinutes  = max(1, (int) $this->option('dwell-minutes'));
        $dispatchEvent = !$this->option('no-log');
        $subjectId     = $this->argument('subject');
        $geofenceId    = $this->argument('geofence');

        if (empty($events)) {
            $this->error('Invalid arguments. Events must be entered|exited|dwelled|sequence or a comma-separated combination.');

            return Command::FAILURE;
        }

        [$subjectType, $subject]   = $this->resolveSubject($subjectId);
        [$geofenceType, $geofence] = $this->resolveGeofence($geofenceId);

        if (!$subject) {
            $this->error("Unable to resolve subject [{$subjectId}]. Expected a `driver_` or `vehicle_` public ID, or a UUID matching one of those resources.");

            return Command::FAILURE;
        }

        if (!$geofence) {
            $this->error("Unable to resolve geofence [{$geofenceId}]. Expected a `zone_` or `sa_` public ID, or a UUID matching one of those resources.");

            return Command::FAILURE;
        }

        session(['company' => $subject->company_uuid]);
        $location = new Point($geofence->getLatitudeAttribute(), $geofence->getLongitudeAttribute());

        if ($this->option('reset-state')) {
            $this->resetState($subjectType, $subject, $geofence);
        }

        $this->info(sprintf(
            'Testing geofence events: %s [%s] -> %s [%s] | events=%s | repeat=%d',
            $subjectType,
            $subject->public_id ?? $subject->uuid,
            $geofenceType,
            $geofence->public_id ?? $geofence->uuid,
            implode(',', $events),
            $repeat
        ));

        for ($iteration = 1; $iteration <= $repeat; $iteration++) {
            $this->line("Run {$iteration}/{$repeat}");

            foreach ($events as $eventName) {
                $enteredAt = Carbon::now()->subMinutes($dwellMinutes);

                switch ($eventName) {
                    case 'entered':
                        $this->markInside($subjectType, $subject, $geofence, Carbon::now());
                        if ($dispatchEvent) {
                            event(new GeofenceEntered($subject, $geofence, $geofenceType, $location));
                        }
                        $this->info('  -> dispatched geofence.entered');
                        break;

                    case 'dwelled':
                        $this->markInside($subjectType, $subject, $geofence, $enteredAt);
                        if ($dispatchEvent) {
                            event(new GeofenceDwelled($subject, $geofence, $geofenceType, $enteredAt));
                        }
                        $this->info("  -> dispatched geofence.dwelled ({$dwellMinutes} min)");
                        break;

                    case 'exited':
                        $this->ensureEnteredAt($subjectType, $subject, $geofence, $enteredAt);
                        $dwellDuration = $this->calculateDwellMinutes($subjectType, $subject, $geofence, $dwellMinutes);
                        $this->markOutside($subjectType, $subject, $geofence);
                        if ($dispatchEvent) {
                            event(new GeofenceExited($subject, $geofence, $geofenceType, $location, $dwellDuration));
                        }
                        $this->info("  -> dispatched geofence.exited ({$dwellDuration} min)");
                        break;
                }

                if ($sleep > 0) {
                    sleep($sleep);
                }
            }
        }

        $this->newLine();
        $this->info('Geofence simulation complete.');

        return Command::SUCCESS;
    }

    protected function parseEvents(string $events): array
    {
        $events = Str::lower(trim($events));

        if ($events === 'sequence') {
            return ['entered', 'dwelled', 'exited'];
        }

        $parsed = collect(explode(',', $events))
            ->map(fn ($event) => trim($event))
            ->filter(fn ($event) => in_array($event, ['entered', 'exited', 'dwelled'], true))
            ->values()
            ->all();

        return $parsed;
    }

    protected function resolveSubject(string $identifier): array
    {
        if (Str::startsWith($identifier, 'driver_')) {
            return ['driver', Driver::withoutGlobalScopes()->where('public_id', $identifier)->first()];
        }

        if (Str::startsWith($identifier, 'vehicle_')) {
            return ['vehicle', Vehicle::withoutGlobalScopes()->where('public_id', $identifier)->first()];
        }

        if (Str::isUuid($identifier)) {
            $driver = Driver::withoutGlobalScopes()->where('uuid', $identifier)->first();
            if ($driver) {
                return ['driver', $driver];
            }

            $vehicle = Vehicle::withoutGlobalScopes()->where('uuid', $identifier)->first();
            if ($vehicle) {
                return ['vehicle', $vehicle];
            }
        }

        return [null, null];
    }

    protected function resolveGeofence(string $identifier): array
    {
        if (Str::startsWith($identifier, 'zone_')) {
            return ['zone', Zone::withoutGlobalScopes()->where('public_id', $identifier)->first()];
        }

        if (Str::startsWith($identifier, 'sa_')) {
            return ['service_area', ServiceArea::withoutGlobalScopes()->where('public_id', $identifier)->first()];
        }

        if (Str::isUuid($identifier)) {
            $zone = Zone::withoutGlobalScopes()->where('uuid', $identifier)->first();
            if ($zone) {
                return ['zone', $zone];
            }

            $serviceArea = ServiceArea::withoutGlobalScopes()->where('uuid', $identifier)->first();
            if ($serviceArea) {
                return ['service_area', $serviceArea];
            }
        }

        return [null, null];
    }

    protected function stateTable(string $subjectType): string
    {
        return $subjectType === 'vehicle' ? 'vehicle_geofence_states' : 'driver_geofence_states';
    }

    protected function subjectColumn(string $subjectType): string
    {
        return $subjectType === 'vehicle' ? 'vehicle_uuid' : 'driver_uuid';
    }

    protected function resetState(string $subjectType, Driver|Vehicle $subject, Zone|ServiceArea $geofence): void
    {
        DB::table($this->stateTable($subjectType))
            ->where($this->subjectColumn($subjectType), $subject->uuid)
            ->where('geofence_uuid', $geofence->uuid)
            ->delete();
    }

    protected function markInside(string $subjectType, Driver|Vehicle $subject, Zone|ServiceArea $geofence, Carbon $enteredAt): void
    {
        DB::table($this->stateTable($subjectType))->upsert(
            [
                $this->subjectColumn($subjectType) => $subject->uuid,
                'geofence_uuid'                    => $geofence->uuid,
                'geofence_type'                    => $geofence instanceof ServiceArea ? 'service_area' : 'zone',
                'is_inside'                        => true,
                'entered_at'                       => $enteredAt,
                'exited_at'                        => null,
                'dwell_job_id'                     => null,
                'created_at'                       => now(),
                'updated_at'                       => now(),
            ],
            [$this->subjectColumn($subjectType), 'geofence_uuid'],
            ['is_inside', 'entered_at', 'exited_at', 'dwell_job_id', 'updated_at']
        );
    }

    protected function ensureEnteredAt(string $subjectType, Driver|Vehicle $subject, Zone|ServiceArea $geofence, Carbon $enteredAt): void
    {
        $state = DB::table($this->stateTable($subjectType))
            ->where($this->subjectColumn($subjectType), $subject->uuid)
            ->where('geofence_uuid', $geofence->uuid)
            ->first();

        if (!$state || !$state->entered_at) {
            $this->markInside($subjectType, $subject, $geofence, $enteredAt);
        }
    }

    protected function markOutside(string $subjectType, Driver|Vehicle $subject, Zone|ServiceArea $geofence): void
    {
        DB::table($this->stateTable($subjectType))
            ->where($this->subjectColumn($subjectType), $subject->uuid)
            ->where('geofence_uuid', $geofence->uuid)
            ->update([
                'is_inside'    => false,
                'exited_at'    => now(),
                'dwell_job_id' => null,
                'updated_at'   => now(),
            ]);
    }

    protected function calculateDwellMinutes(string $subjectType, Driver|Vehicle $subject, Zone|ServiceArea $geofence, int $fallback): int
    {
        $state = DB::table($this->stateTable($subjectType))
            ->where($this->subjectColumn($subjectType), $subject->uuid)
            ->where('geofence_uuid', $geofence->uuid)
            ->first();

        if (!$state || !$state->entered_at) {
            return $fallback;
        }

        return max(1, Carbon::parse($state->entered_at)->diffInMinutes(now()));
    }
}
