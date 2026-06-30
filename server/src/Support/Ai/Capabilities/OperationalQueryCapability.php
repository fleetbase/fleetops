<?php

namespace Fleetbase\FleetOps\Support\Ai\Capabilities;

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Services\AiQueryExecutor;
use Fleetbase\Ai\Support\AiRelativeDateResolver;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Zone;
use Illuminate\Database\Eloquent\Builder;

class OperationalQueryCapability extends AbstractFleetOpsAICapability
{
    public function key(): string
    {
        return 'fleet-ops.operational_query';
    }

    public function label(): string
    {
        return 'Fleet-Ops operational query';
    }

    public function description(): string
    {
        return 'Executes safe allowlisted Fleet-Ops operational queries and aggregate summaries.';
    }

    public function permissions(): array
    {
        return [
            'fleet-ops list driver',
            'fleet-ops list vehicle',
            'fleet-ops list device',
            'fleet-ops list order',
            'fleet-ops list fleet',
            'fleet-ops list service-area',
            'fleet-ops list zone',
        ];
    }

    public function resolve(AiTask $task): array
    {
        $prompt   = $this->prompt($task);
        $executor = app(AiQueryExecutor::class);
        $queries  = [];

        if ($this->mentions($prompt, ['driver', 'drivers'])) {
            $queries['drivers'] = $this->driverQueries($prompt, $executor);
        }

        if ($this->mentions($prompt, ['vehicle', 'vehicles'])) {
            $queries['vehicles'] = $this->onlineResourceQueries('fleet-ops.vehicles', $prompt, $executor);
        }

        if ($this->mentions($prompt, ['device', 'devices'])) {
            $queries['devices'] = $this->onlineResourceQueries('fleet-ops.devices', $prompt, $executor);
        }

        if ($this->mentions($prompt, ['order', 'orders'])) {
            $queries['orders'] = $this->orderQueries($prompt, $executor);
        }

        if ($this->mentions($prompt, ['fleet', 'fleets'])) {
            $queries['fleets'] = [
                'total' => $executor->count('fleet-ops.fleets'),
            ];
        }

        return [
            'authorized'   => true,
            'query_engine' => 'fleetbase_ai_allowlisted_operational_query',
            'instruction'  => 'Answer only from these executed Fleetbase query summaries. If a requested metric is missing or empty, say that Fleetbase did not return enough data for that specific metric.',
            'queries'      => array_filter($queries),
        ];
    }

    protected function matchesPrompt(string $prompt): bool
    {
        return $this->mentions($prompt, ['driver', 'drivers', 'vehicle', 'vehicles', 'device', 'devices', 'order', 'orders', 'fleet', 'fleets'])
            && $this->mentions($prompt, ['how many', 'count', 'where', 'located', 'location', 'majority', 'online', 'offline', 'without', 'unassigned', 'assigned', 'break down', 'status', 'service area', 'zone', 'today', 'yesterday', 'tomorrow', 'this week', 'last week', 'next week', 'this month', 'last month', 'last 30 days']);
    }

    protected function driverQueries(string $prompt, AiQueryExecutor $executor): array
    {
        $dateWindow  = $this->dateWindow($prompt);
        $dateFilters = $dateWindow ? $this->dateFilters($dateWindow, 'updated_at') : [];

        $queries = [
            'total'            => $executor->count('fleet-ops.drivers'),
            'online'           => $executor->count('fleet-ops.drivers', [['field' => 'online', 'operator' => '=', 'value' => true]]),
            'offline'          => $executor->count('fleet-ops.drivers', [['field' => 'online', 'operator' => 'false_or_null']]),
            'counts_by_status' => $executor->countsBy('fleet-ops.drivers', 'status'),
        ];

        if ($dateWindow) {
            $queries['date_window']       = $this->dateWindowPayload($dateWindow, 'updated_at');
            $queries['total_in_window']   = $executor->count('fleet-ops.drivers', $dateFilters);
            $queries['online_in_window']  = $executor->count('fleet-ops.drivers', array_merge($dateFilters, [['field' => 'online', 'operator' => '=', 'value' => true]]));
            $queries['offline_in_window'] = $executor->count('fleet-ops.drivers', array_merge($dateFilters, [['field' => 'online', 'operator' => 'false_or_null']]));
        }

        if ($this->mentions($prompt, ['without vehicle', 'without vehicles', 'unassigned vehicle', 'no vehicle'])) {
            $queries['without_vehicle'] = [
                'count'   => $executor->count('fleet-ops.drivers', [['field' => 'vehicle_uuid', 'operator' => 'null']]),
                'samples' => $executor->samples('fleet-ops.drivers', [['field' => 'vehicle_uuid', 'operator' => 'null']], 10),
            ];
        }

        if ($this->mentions($prompt, ['where', 'located', 'location', 'majority', 'service area', 'zone'])) {
            $filters = array_merge($dateFilters, $this->mentions($prompt, ['online']) ? [['field' => 'online', 'operator' => '=', 'value' => true]] : []);

            $queries['location_summary']          = $executor->locationSummary('fleet-ops.drivers', $filters, 250);
            $queries['service_area_distribution'] = $this->driverGeofenceDistribution($filters);
        }

        return $queries;
    }

    protected function onlineResourceQueries(string $resource, string $prompt, AiQueryExecutor $executor): array
    {
        $dateWindow  = $this->dateWindow($prompt);
        $dateField   = $resource === 'fleet-ops.devices' ? 'last_online_at' : 'updated_at';
        $dateFilters = $dateWindow ? $this->dateFilters($dateWindow, $dateField) : [];

        $queries = [
            'total'            => $executor->count($resource),
            'online'           => $executor->count($resource, [['field' => 'online', 'operator' => '=', 'value' => true]]),
            'offline'          => $executor->count($resource, [['field' => 'online', 'operator' => 'false_or_null']]),
            'counts_by_status' => $executor->countsBy($resource, 'status'),
        ];

        if ($dateWindow) {
            $queries['date_window']       = $this->dateWindowPayload($dateWindow, $dateField);
            $queries['total_in_window']   = $executor->count($resource, $dateFilters);
            $queries['online_in_window']  = $executor->count($resource, array_merge($dateFilters, [['field' => 'online', 'operator' => '=', 'value' => true]]));
            $queries['offline_in_window'] = $executor->count($resource, array_merge($dateFilters, [['field' => 'online', 'operator' => 'false_or_null']]));
        }

        if ($this->mentions($prompt, ['where', 'located', 'location', 'majority']) && $resource === 'fleet-ops.vehicles') {
            $queries['location_summary'] = $executor->locationSummary($resource, array_merge($dateFilters, $this->mentions($prompt, ['online']) ? [['field' => 'online', 'operator' => '=', 'value' => true]] : []), 250);
        }

        return $queries;
    }

    protected function orderQueries(string $prompt, AiQueryExecutor $executor): array
    {
        $filters = $this->orderDateFilters($prompt);

        if ($this->mentions($prompt, ['active'])) {
            $filters[] = ['field' => 'status', 'operator' => 'not_in', 'value' => ['canceled', 'completed', 'expired']];
        }

        $queries = [
            'total'            => $executor->count('fleet-ops.orders', $filters),
            'counts_by_status' => $executor->countsBy('fleet-ops.orders', 'status', $filters),
        ];

        if ($dateWindow = $this->dateWindow($prompt)) {
            $queries['date_window'] = $this->dateWindowPayload($dateWindow, 'created_at');
        }

        if ($this->mentions($prompt, ['without driver', 'unassigned driver', 'no driver'])) {
            $queries['without_driver'] = $executor->count('fleet-ops.orders', array_merge($filters, [['field' => 'driver_assigned_uuid', 'operator' => 'null']]));
        }

        if ($this->mentions($prompt, ['assigned driver', 'with driver'])) {
            $queries['with_driver'] = $executor->count('fleet-ops.orders', array_merge($filters, [['field' => 'driver_assigned_uuid', 'operator' => 'not_null']]));
        }

        if ($this->mentions($prompt, ['without vehicle', 'unassigned vehicle', 'no vehicle'])) {
            $queries['without_vehicle'] = $executor->count('fleet-ops.orders', array_merge($filters, [['field' => 'vehicle_assigned_uuid', 'operator' => 'null']]));
        }

        return $queries;
    }

    protected function driverGeofenceDistribution(array $filters = []): array
    {
        if (!$this->can('fleet-ops list driver')) {
            return ['authorized' => false, 'resource' => 'fleet-ops.drivers'];
        }

        $query = Driver::where('company_uuid', session('company'))
            ->applyDirectivesForPermissions('fleet-ops list driver');

        foreach ($filters as $filter) {
            if (($filter['field'] ?? null) === 'online') {
                $query->where('online', (bool) ($filter['value'] ?? false));
            }

            if (($filter['field'] ?? null) === 'updated_at' && in_array(($filter['operator'] ?? null), ['>=', '<='], true)) {
                $query->where('updated_at', $filter['operator'], $filter['value']);
            }
        }

        $drivers = $this->whereValidDriverLocation($query)
            ->latest()
            ->limit(250)
            ->get(['uuid', 'public_id', 'company_uuid', 'location', 'city', 'country', 'online']);

        if ($drivers->isEmpty()) {
            return [
                'authorized'           => true,
                'valid_location_count' => 0,
                'service_areas'        => [],
                'zones'                => [],
            ];
        }

        $serviceAreaCounts = [];
        $zoneCounts        = [];

        foreach ($drivers as $driver) {
            $point = $driver->location;
            if (!is_object($point) || !method_exists($point, 'getLat') || !method_exists($point, 'getLng')) {
                continue;
            }

            $wkt = sprintf('POINT(%s %s)', $point->getLng(), $point->getLat());

            if ($this->can('fleet-ops list service-area')) {
                ServiceArea::where('company_uuid', session('company'))
                    ->whereNotNull('border')
                    ->whereRaw('MBRContains(`border`, ST_GeomFromText(?))', [$wkt])
                    ->whereRaw('ST_Contains(`border`, ST_GeomFromText(?))', [$wkt])
                    ->get(['uuid', 'name', 'public_id'])
                    ->each(function (ServiceArea $serviceArea) use (&$serviceAreaCounts) {
                        $key = $serviceArea->uuid;
                        $serviceAreaCounts[$key] ??= [
                            'uuid'      => $serviceArea->uuid,
                            'public_id' => $serviceArea->public_id,
                            'name'      => $serviceArea->name,
                            'count'     => 0,
                        ];
                        $serviceAreaCounts[$key]['count']++;
                    });
            }

            if ($this->can('fleet-ops list zone')) {
                Zone::where('company_uuid', session('company'))
                    ->whereNotNull('border')
                    ->whereRaw('MBRContains(`border`, ST_GeomFromText(?))', [$wkt])
                    ->whereRaw('ST_Contains(`border`, ST_GeomFromText(?))', [$wkt])
                    ->get(['uuid', 'name', 'public_id', 'service_area_uuid'])
                    ->each(function (Zone $zone) use (&$zoneCounts) {
                        $key = $zone->uuid;
                        $zoneCounts[$key] ??= [
                            'uuid'              => $zone->uuid,
                            'public_id'         => $zone->public_id,
                            'name'              => $zone->name,
                            'service_area_uuid' => $zone->service_area_uuid,
                            'count'             => 0,
                        ];
                        $zoneCounts[$key]['count']++;
                    });
            }
        }

        return [
            'authorized'           => true,
            'valid_location_count' => $drivers->count(),
            'service_areas'        => collect($serviceAreaCounts)->sortByDesc('count')->take(10)->values()->all(),
            'zones'                => collect($zoneCounts)->sortByDesc('count')->take(10)->values()->all(),
        ];
    }

    protected function whereValidDriverLocation(Builder $query): Builder
    {
        return $query->whereNotNull('location')->whereRaw('
            ST_Y(location) BETWEEN -90 AND 90
            AND ST_X(location) BETWEEN -180 AND 180
            AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)
        ');
    }

    protected function orderDateFilters(string $prompt): array
    {
        $window = $this->dateWindow($prompt);

        return $window ? $this->dateFilters($window, 'created_at') : [];
    }

    protected function dateWindow(string $prompt): ?array
    {
        return $this->relativeDateResolver()->resolveWindow($prompt);
    }

    protected function dateFilters(array $window, string $field): array
    {
        return [
            ['field' => $field, 'operator' => '>=', 'value' => $window['start']],
            ['field' => $field, 'operator' => '<=', 'value' => $window['end']],
        ];
    }

    protected function dateWindowPayload(array $window, string $field): array
    {
        return [
            'label'    => $window['label'],
            'timezone' => $window['timezone'],
            'field'    => $field,
            'start'    => $window['start']->toIso8601String(),
            'end'      => $window['end']->toIso8601String(),
        ];
    }

    protected function relativeDateResolver(): AiRelativeDateResolver
    {
        return function_exists('app') ? app(AiRelativeDateResolver::class) : new AiRelativeDateResolver(null);
    }

    protected function mentions(string $prompt, array $terms): bool
    {
        return $this->containsAny($prompt, $terms);
    }
}
