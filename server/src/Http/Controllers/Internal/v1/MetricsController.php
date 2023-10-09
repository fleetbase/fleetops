<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Support\Metrics;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MetricsController extends Controller
{
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

    public function dashboard(Request $request)
    {
        $start    = $request->date('start');
        $end      = $request->date('end');
        $discover = $request->array('discover', []);
        $metrics  = [];

        try {
            $metrics = Metrics::forCompany($request->user()->company, $start, $end)->with($discover)->get();
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }

        // metrics format map
        $metricsFormats = [
            'earnings'                => 'money',
            'fuel_costs'              => 'money',
            'total_distance_traveled' => 'meters',
        ];

        // dashboard config
        $dashboardConfig = [
            [
                'size'        => 12,
                'title'       => 'Fleet-Ops Metrics',
                'classList'   => [],
                'component'   => null,
                'queryParams' => [
                    'start' => ['component' => 'date-picker'],
                    'end'   => ['component' => 'date-picker'],
                ],
                'widgets' => collect($metrics)
                    ->map(function ($value, $key) use ($metricsFormats) {
                        return [
                            'component' => 'count',
                            'options'   => [
                                'format' => $metricsFormats[$key] ?? null,
                                'title'  => str_replace('_', ' ', \Illuminate\Support\Str::title($key)),
                                'value'  => $value,
                            ],
                        ];
                    })
                    ->values()
                    ->toArray(),
            ],
        ];

        return response()->json(array_values($dashboardConfig));
    }
}
