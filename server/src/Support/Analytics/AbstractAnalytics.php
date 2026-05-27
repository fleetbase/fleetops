<?php

namespace Fleetbase\FleetOps\Support\Analytics;

use Fleetbase\Models\Company;
use Illuminate\Support\Carbon;

/**
 * Base for analytics-widget data providers. Unlike scalar Metrics, each
 * subclass returns a widget-specific JSON shape (Chart.js datasets, ranked
 * rows, scalar tiles, map points, etc.) so the base just provides company
 * scoping + period parsing.
 */
abstract class AbstractAnalytics
{
    protected Company $company;
    protected ?\DateTimeInterface $start = null;
    protected ?\DateTimeInterface $end   = null;

    abstract public function get(): array;

    public static function forCompany(Company $company): static
    {
        return (new static())->setCompany($company);
    }

    public function between(?\DateTimeInterface $start, ?\DateTimeInterface $end): static
    {
        $this->start = $start;
        $this->end   = $end;

        return $this;
    }

    /**
     * Resolve a [start, end] tuple from a `period` shorthand or explicit dates,
     * falling back to a 30-day window. Shared by every analytics call.
     */
    public static function resolvePeriod(?string $period, ?\DateTimeInterface $start, ?\DateTimeInterface $end, int $defaultDays = 30): array
    {
        if ($period !== null && $period !== '') {
            $days = match ($period) {
                '7d'    => 7,
                '14d'   => 14,
                '30d'   => 30,
                '90d'   => 90,
                '180d'  => 180,
                '365d'  => 365,
                default => null,
            };

            if ($days !== null) {
                return [
                    Carbon::now()->subDays($days)->toDateTime(),
                    Carbon::now()->toDateTime(),
                ];
            }
        }

        return [
            $start ?? Carbon::now()->subDays($defaultDays)->toDateTime(),
            $end ?? Carbon::now()->toDateTime(),
        ];
    }

    protected function setCompany(Company $company): static
    {
        $this->company = $company;

        return $this;
    }

    protected function companyCurrency(): string
    {
        return $this->company->currency ?? 'USD';
    }
}
