<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Support\Metrics\AbstractMetric;
use Fleetbase\FleetOps\Support\Metrics\Registry;
use Fleetbase\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Thin facade over Support/Metrics/* — preserves the legacy chainable API
 * (Metrics::new($co)->withEarnings()->withFuelCosts()->get()) for one release
 * while the new per-slug pattern is preferred:
 *
 *     Metrics::forCompany($co)->between($s, $e)->resolve('earnings')->get()
 *
 * The 7 documented bugs in the previous monolithic implementation are fixed
 * by the per-metric classes:
 *  - totalTimeTraveled now sums `time`, not `distance`
 *  - openIssues/resolvedIssues use $this->company->uuid (not session)
 *  - earnings/fuelCosts return float, currency-filtered
 *  - ordersInProgress uses explicit allowlist
 *  - metric discovery uses explicit Registry (not array_slice + reflection)
 */
class Metrics
{
    protected \DateTime $start;
    protected \DateTime $end;
    protected Company $company;
    protected array $metrics = [];

    public static function new(Company $company, ?\DateTime $start = null, ?\DateTime $end = null): Metrics
    {
        $start = $start === null ? Carbon::create(1900)->toDateTime() : $start;
        $end   = $end === null ? Carbon::tomorrow()->toDateTime() : $end;

        return (new static())->setCompany($company)->between($start, $end);
    }

    public static function forCompany(Company $company, ?\DateTime $start = null, ?\DateTime $end = null): Metrics
    {
        return static::new($company, $start, $end);
    }

    public function start(\DateTime $start): Metrics
    {
        $this->start = $start;

        return $this;
    }

    public function end(\DateTime $end): Metrics
    {
        $this->end = $end;

        return $this;
    }

    public function between(\DateTime $start, \DateTime $end): Metrics
    {
        return $this->start($start)->end($end);
    }

    /**
     * Resolve a single metric by slug. New preferred entry point.
     */
    public function resolve(string $slug): ?AbstractMetric
    {
        $class = Registry::resolve($slug);

        if ($class === null) {
            return null;
        }

        return $class::forCompany($this->company)->between($this->start, $this->end);
    }

    /**
     * Legacy bulk API: invoke a set of metrics and collect their scalar values
     * in the same shape the old endpoint returned. Empty $metrics means "all".
     *
     * @param array<string> $metrics slugs or camelCase method names accepted for back-compat
     */
    public function with(?array $metrics = []): Metrics
    {
        if (empty($metrics)) {
            $slugs = Registry::slugs();
        } else {
            $slugs = array_map(
                fn ($m) => Str::snake($m),
                $metrics
            );
        }

        foreach ($slugs as $slug) {
            $metric = $this->resolve($slug);
            if ($metric !== null) {
                $this->metrics[$slug] = $metric->value();
            }
        }

        return $this;
    }

    public function get(): array
    {
        return $this->metrics;
    }

    private function setCompany(Company $company): Metrics
    {
        $this->company = $company;

        return $this;
    }
}
