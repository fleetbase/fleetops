<?php

namespace Fleetbase\FleetOps\Traits;

use Fleetbase\FleetOps\Models\Maintenance;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Trait Maintainable.
 *
 * Provides maintenance-related functionality for models that can be maintained.
 * This trait can be used by Asset, Equipment, and other maintainable entities.
 */
trait Maintainable
{
    /**
     * Get all maintenances for this maintainable entity.
     */
    public function maintenances(): MorphMany
    {
        return $this->morphMany(Maintenance::class, 'maintainable');
    }

    /**
     * Get scheduled maintenances.
     */
    public function scheduledMaintenances(): MorphMany
    {
        return $this->maintenances()->where('status', 'scheduled');
    }

    /**
     * Get completed maintenances.
     */
    public function completedMaintenances(): MorphMany
    {
        return $this->maintenances()->where('status', 'completed');
    }

    /**
     * Get overdue maintenances.
     */
    public function overdueMaintenances(): MorphMany
    {
        return $this->maintenances()
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<', now());
    }

    /**
     * Get the last completed maintenance.
     */
    public function getLastMaintenanceAttribute(): ?Maintenance
    {
        return $this->completedMaintenances()
            ->orderBy('completed_at', 'desc')
            ->first();
    }

    /**
     * Get the next scheduled maintenance.
     */
    public function getNextMaintenanceAttribute(): ?Maintenance
    {
        return $this->scheduledMaintenances()
            ->orderBy('scheduled_at', 'asc')
            ->first();
    }

    /**
     * Check if the entity needs maintenance.
     */
    public function needsMaintenance(): bool
    {
        // Check if there's overdue maintenance
        if ($this->overdueMaintenances()->exists()) {
            return true;
        }

        // Check maintenance intervals based on usage metrics
        return $this->checkMaintenanceIntervals();
    }

    /**
     * Check maintenance intervals based on usage metrics.
     */
    protected function checkMaintenanceIntervals(): bool
    {
        $lastMaintenance = $this->last_maintenance;

        if (!$lastMaintenance) {
            // If no maintenance history, check against purchase/creation date
            $baseDate = $this->purchased_at ?? $this->created_at;

            return $this->checkIntervalsSinceDate($baseDate);
        }

        // Check intervals since last maintenance
        return $this->checkIntervalsSinceDate($lastMaintenance->completed_at);
    }

    /**
     * Check maintenance intervals since a specific date.
     *
     * @param \Carbon\Carbon $date
     */
    protected function checkIntervalsSinceDate($date): bool
    {
        $specs = $this->specs ?? [];
        $meta  = $this->meta ?? [];

        // Check time-based intervals
        $maintenanceIntervalDays = $specs['maintenance_interval_days'] ?? $meta['maintenance_interval_days'] ?? null;
        if ($maintenanceIntervalDays && $date->diffInDays(now()) >= $maintenanceIntervalDays) {
            return true;
        }

        // Check odometer-based intervals (for assets with odometer)
        if (isset($this->odometer) && isset($specs['maintenance_interval_miles'])) {
            $lastOdometer              = $this->last_maintenance?->odometer ?? 0;
            $milesSinceLastMaintenance = $this->odometer - $lastOdometer;
            if ($milesSinceLastMaintenance >= $specs['maintenance_interval_miles']) {
                return true;
            }
        }

        // Check engine hours-based intervals (for assets with engine hours)
        if (isset($this->engine_hours) && isset($specs['maintenance_interval_hours'])) {
            $lastEngineHours           = $this->last_maintenance?->engine_hours ?? 0;
            $hoursSinceLastMaintenance = $this->engine_hours - $lastEngineHours;
            if ($hoursSinceLastMaintenance >= $specs['maintenance_interval_hours']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Schedule maintenance for the entity.
     */
    public function scheduleMaintenance(string $type, \DateTime $scheduledAt, array $details = []): Maintenance
    {
        return Maintenance::create([
            'company_uuid'      => $this->company_uuid,
            'maintainable_type' => static::class,
            'maintainable_uuid' => $this->uuid,
            'type'              => $type,
            'status'            => 'scheduled',
            'scheduled_at'      => $scheduledAt,
            'odometer'          => $this->odometer ?? null,
            'engine_hours'      => $this->engine_hours ?? null,
            'summary'           => $details['summary'] ?? null,
            'notes'             => $details['notes'] ?? null,
            'priority'          => $details['priority'] ?? 'medium',
            'created_by_uuid'   => auth()->id(),
        ]);
    }

    /**
     * Get maintenance cost for a specific period.
     */
    public function getMaintenanceCost(int $days = 365): float
    {
        $startDate = now()->subDays($days);

        return $this->completedMaintenances()
            ->where('completed_at', '>=', $startDate)
            ->sum('total_cost') ?? 0;
    }

    /**
     * Get maintenance frequency (maintenances per year).
     */
    public function getMaintenanceFrequency(int $days = 365): float
    {
        $startDate        = now()->subDays($days);
        $maintenanceCount = $this->completedMaintenances()
            ->where('completed_at', '>=', $startDate)
            ->count();

        return ($maintenanceCount / $days) * 365;
    }

    /**
     * Get average maintenance duration in hours.
     */
    public function getAverageMaintenanceDuration(int $days = 365): ?float
    {
        $startDate    = now()->subDays($days);
        $maintenances = $this->completedMaintenances()
            ->where('completed_at', '>=', $startDate)
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->get();

        if ($maintenances->isEmpty()) {
            return null;
        }

        $totalHours = $maintenances->sum(function ($maintenance) {
            return $maintenance->started_at->diffInHours($maintenance->completed_at);
        });

        return $totalHours / $maintenances->count();
    }

    /**
     * Get maintenance efficiency rating.
     */
    public function getMaintenanceEfficiency(int $days = 365): ?float
    {
        $startDate    = now()->subDays($days);
        $maintenances = $this->completedMaintenances()
            ->where('completed_at', '>=', $startDate)
            ->get();

        if ($maintenances->isEmpty()) {
            return null;
        }

        $onTimeCount = $maintenances->filter(function ($maintenance) {
            return $maintenance->wasCompletedOnTime();
        })->count();

        return ($onTimeCount / $maintenances->count()) * 100;
    }

    /**
     * Get upcoming maintenance due dates.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUpcomingMaintenance(int $days = 30)
    {
        $endDate = now()->addDays($days);

        return $this->scheduledMaintenances()
            ->where('scheduled_at', '<=', $endDate)
            ->orderBy('scheduled_at', 'asc')
            ->get();
    }

    /**
     * Create preventive maintenance schedule.
     */
    public function createPreventiveMaintenanceSchedule(array $intervals = []): array
    {
        $schedule         = [];
        $specs            = $this->specs ?? [];
        $defaultIntervals = $specs['maintenance_intervals'] ?? [];

        $intervals = array_merge($defaultIntervals, $intervals);

        foreach ($intervals as $type => $interval) {
            $lastMaintenance = $this->completedMaintenances()
                ->where('type', $type)
                ->orderBy('completed_at', 'desc')
                ->first();

            $baseDate = $lastMaintenance?->completed_at ?? $this->created_at;
            $nextDue  = $baseDate->copy()->addDays($interval['days'] ?? 365);

            if ($nextDue->isFuture()) {
                $schedule[] = [
                    'type'          => $type,
                    'due_date'      => $nextDue,
                    'interval_days' => $interval['days'] ?? 365,
                    'priority'      => $interval['priority'] ?? 'medium',
                    'description'   => $interval['description'] ?? "Scheduled {$type} maintenance",
                ];
            }
        }

        return $schedule;
    }

    /**
     * Get maintenance history summary.
     */
    public function getMaintenanceHistorySummary(int $days = 365): array
    {
        $startDate    = now()->subDays($days);
        $maintenances = $this->maintenances()
            ->where('created_at', '>=', $startDate)
            ->get();

        $completed = $maintenances->where('status', 'completed');
        $scheduled = $maintenances->where('status', 'scheduled');
        $overdue   = $scheduled->filter(function ($maintenance) {
            return $maintenance->scheduled_at->isPast();
        });

        return [
            'total_maintenances'     => $maintenances->count(),
            'completed_count'        => $completed->count(),
            'scheduled_count'        => $scheduled->count(),
            'overdue_count'          => $overdue->count(),
            'total_cost'             => $completed->sum('total_cost'),
            'average_cost'           => $completed->avg('total_cost'),
            'total_downtime_hours'   => $completed->sum('duration_hours'),
            'average_duration_hours' => $completed->avg('duration_hours'),
            'on_time_percentage'     => $this->getMaintenanceEfficiency($days),
            'most_common_type'       => $completed->groupBy('type')->sortByDesc->count()->keys()->first(),
        ];
    }
}
