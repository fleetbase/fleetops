<?php

namespace Fleetbase\FleetOps\Support\Metrics;

use Fleetbase\Models\Company;
use Illuminate\Support\Carbon;

/**
 * Base class for FleetOps scalar metrics.
 *
 * Subclasses implement query() + aggregate() and declare slug() + format(). The base
 * handles period selection, optional period-over-period delta, and optional sparkline.
 */
abstract class AbstractMetric
{
    protected Company $company;
    protected ?\DateTimeInterface $start = null;
    protected ?\DateTimeInterface $end   = null;
    protected ?\DateTimeInterface $compareStart = null;
    protected ?\DateTimeInterface $compareEnd   = null;
    protected int $sparklineBuckets = 0;
    protected string $sparklineUnit = 'day';

    /**
     * Period boundaries for the currently-executing aggregate call.
     * Set by value()/delta()/sparkline() before invoking aggregate(); subclasses
     * that need to issue a second parallel query (e.g. AvgOrderValueMetric) read
     * these instead of re-introspecting the query's where clauses.
     */
    protected ?\DateTimeInterface $currentStart = null;
    protected ?\DateTimeInterface $currentEnd   = null;

    abstract public static function slug(): string;

    abstract public function format(): string;

    /**
     * Build the query for the given period. The base class invokes this with the
     * current period, the comparison period (for delta), and one sub-range per
     * sparkline bucket — so it must be cheap and idempotent.
     */
    abstract protected function query(?\DateTimeInterface $start, ?\DateTimeInterface $end);

    /**
     * Reduce a query to a single numeric value. Override for sums (`->sum('amount')`),
     * counts (`->count()`), or computed expressions.
     */
    abstract protected function aggregate($query): float|int;

    public function currency(): ?string
    {
        return null;
    }

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

    public function compareTo(\DateTimeInterface $start, \DateTimeInterface $end): static
    {
        $this->compareStart = $start;
        $this->compareEnd   = $end;

        return $this;
    }

    public function withSparkline(int $buckets = 14, string $unit = 'day'): static
    {
        $this->sparklineBuckets = max(0, $buckets);
        $this->sparklineUnit    = $unit;

        return $this;
    }

    public function value(): float|int
    {
        return $this->runAggregate($this->start, $this->end);
    }

    public function delta(): ?float
    {
        if (!$this->compareStart || !$this->compareEnd) {
            return null;
        }

        $current  = $this->value();
        $previous = $this->runAggregate($this->compareStart, $this->compareEnd);

        if ((float) $previous === 0.0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    public function sparkline(): ?array
    {
        if ($this->sparklineBuckets === 0 || $this->end === null) {
            return null;
        }

        $end    = Carbon::instance($this->end);
        $cursor = $end->copy()->sub($this->sparklineUnit, $this->sparklineBuckets);
        $labels = [];
        $data   = [];

        for ($i = 0; $i < $this->sparklineBuckets; $i++) {
            $next     = $cursor->copy()->add($this->sparklineUnit, 1);
            $labels[] = $cursor->format('Y-m-d');
            $data[]   = $this->runAggregate($cursor->toDateTime(), $next->toDateTime());
            $cursor   = $next;
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * Run a single aggregation for a sub-period. Sets $currentStart/$currentEnd
     * so subclasses that need to issue a parallel query can read the bucket range.
     */
    protected function runAggregate(?\DateTimeInterface $start, ?\DateTimeInterface $end): float|int
    {
        $this->currentStart = $start;
        $this->currentEnd   = $end;

        return $this->aggregate($this->query($start, $end));
    }

    public function get(): array
    {
        $payload = [
            'slug'   => static::slug(),
            'value'  => $this->value(),
            'format' => $this->format(),
        ];

        $currency = $this->currency();
        if ($currency !== null) {
            $payload['currency'] = $currency;
        }

        $delta = $this->delta();
        if ($delta !== null) {
            $payload['delta_pct'] = $delta;
        }

        $sparkline = $this->sparkline();
        if ($sparkline !== null) {
            $payload['sparkline'] = $sparkline;
        }

        return $payload;
    }

    protected function setCompany(Company $company): static
    {
        $this->company = $company;

        return $this;
    }
}
