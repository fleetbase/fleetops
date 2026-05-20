<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Support\Metrics;
use Fleetbase\FleetOps\Support\Metrics\Registry;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MetricsController extends Controller
{
    /**
     * Legacy bulk endpoint. Returns a flat map of slug → scalar value for the
     * requested period. Preserved for backward compat; the dashboard widgets
     * now prefer per-slug GET /metrics/{slug}.
     */
    public function all(Request $request)
    {
        $start    = $request->date('start');
        $end      = $request->date('end');
        $discover = $request->array('discover', []);

        try {
            $data = Metrics::forCompany($request->user()->company, $start, $end)->with($discover)->get();
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }

        return response()->json($data);
    }

    /**
     * Per-metric endpoint backing <Widget::KpiTile>.
     *
     * Accepts:
     *   ?period=7d|30d|90d  OR  ?start=ISO&end=ISO
     *   ?sparkline=true     to include a per-day mini-chart series
     *   ?compare=true       to include period-over-period delta (default: true)
     *
     * Returns: { slug, value, format, currency?, delta_pct?, sparkline? }
     */
    public function show(Request $request, string $slug)
    {
        $class = Registry::resolve($slug);
        if ($class === null) {
            return response()->json(['error' => "Unknown metric: {$slug}"], 404);
        }

        [$start, $end] = $this->resolvePeriod($request);

        $metric = $class::forCompany($request->user()->company)->between($start, $end);

        if ($request->boolean('compare', true)) {
            $duration     = $end->getTimestamp() - $start->getTimestamp();
            $compareEnd   = (clone $start);
            $compareStart = (clone $start)->modify("-{$duration} seconds");
            $metric->compareTo($compareStart, $compareEnd);
        }

        if ($request->boolean('sparkline')) {
            $buckets = (int) $request->input('sparkline_buckets', 14);
            $metric->withSparkline($buckets, 'day');
        }

        return response()->json($metric->get());
    }

    /**
     * Parse ?period=7d/30d/90d into a [start, end] pair, falling back to
     * explicit ?start=&end= ISO dates, then to a 30-day default.
     */
    private function resolvePeriod(Request $request): array
    {
        $period = $request->string('period')->toString();

        if ($period !== '') {
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
                $end   = Carbon::now()->toDateTime();
                $start = Carbon::now()->subDays($days)->toDateTime();

                return [$start, $end];
            }
        }

        $start = $request->date('start') ?? Carbon::now()->subDays(30)->toDateTime();
        $end   = $request->date('end') ?? Carbon::now()->toDateTime();

        if (!$start instanceof \DateTime) {
            $start = Carbon::parse($start)->toDateTime();
        }

        if (!$end instanceof \DateTime) {
            $end = Carbon::parse($end)->toDateTime();
        }

        return [$start, $end];
    }
}
