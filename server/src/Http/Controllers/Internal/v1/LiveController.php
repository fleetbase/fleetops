<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Filter\PlaceFilter;
use Fleetbase\FleetOps\Http\Resources\v1\Driver as DriverResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Order as OrderIndexResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Place as PlaceIndexResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Vehicle as VehicleIndexResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\FleetDriver;
use Fleetbase\FleetOps\Models\FleetVehicle;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Route;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\LiveCacheService;
use Fleetbase\FleetOps\Support\LiveOrderQuery;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Class LiveController.
 */
class LiveController extends Controller
{
    protected const DEFAULT_VIEWPORT_LIMIT    = 500;
    protected const MAX_VIEWPORT_LIMIT        = 1000;
    protected const VIEWPORT_BOUNDS_PRECISION = 4;

    /**
     * Get coordinates for active orders.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function coordinates()
    {
        return LiveCacheService::remember('coordinates', [], function () {
            $coordinates = [];

            // Fetch active orders for the current company
            $orders = Order::where('company_uuid', session('company'))
                ->whereNotIn('status', ['canceled', 'completed'])
                ->with(['payload.dropoff', 'payload.pickup'])
                ->applyDirectivesForPermissions('fleet-ops list order')
                ->get();

            // Loop through each order to get its current destination location.
            // Filter out null and 0,0 fallback coordinates — these are returned
            // by getCurrentDestinationLocation() when an order has no dropoff or
            // waypoint set, and would cause the location service to resolve to
            // the ocean (0,0 = Gulf of Guinea).
            foreach ($orders as $order) {
                $location = $order->getCurrentDestinationLocation();
                if ($location && !($location->getLat() == 0 && $location->getLng() == 0)) {
                    $coordinates[] = $location;
                }
            }

            return response()->json($coordinates);
        });
    }

    /**
     * Get active routes for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function routes()
    {
        return LiveCacheService::remember('routes', [], function () {
            // Fetch routes that are not canceled or completed and have an assigned driver
            $routes = Route::where('company_uuid', session('company'))
                ->whereHas(
                    'order',
                    function ($q) {
                        $q->whereNotIn('status', ['canceled', 'completed', 'expired']);
                        $q->whereNotNull('driver_assigned_uuid');
                        $q->whereNull('deleted_at');
                        $q->whereHas('trackingNumber');
                        $q->whereHas('trackingStatuses');
                        $q->whereHas('payload', function ($query) {
                            $query->where(
                                function ($q) {
                                    $q->whereHas('waypoints');
                                    $q->orWhereHas('pickup');
                                    $q->orWhereHas('dropoff');
                                }
                            );
                        });
                    }
                )
                ->with(['order.payload', 'order.trackingNumber', 'order.trackingStatuses', 'order.driverAssigned'])
                ->applyDirectivesForPermissions('fleet-ops list route')
                ->get();

            return response()->json($routes);
        });
    }

    /**
     * Get active orders with payload for the current company.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function orders(Request $request)
    {
        $exclude     = $request->array('exclude');
        $active      = $request->boolean('active');
        $unassigned  = $request->boolean('unassigned');

        // Cache key includes all parameters that affect the query
        $cacheParams = [
            'exclude'      => $exclude,
            'active'       => $active,
            'unassigned'   => $unassigned,
        ];

        return LiveCacheService::remember('orders', $cacheParams, function () use ($exclude, $active, $unassigned) {
            $query = LiveOrderQuery::make(session('company'), [
                'exclude'        => $exclude,
                'active'         => $active,
                'unassigned'     => $unassigned,
                'with_relations' => true,
            ]);

            $query->limit(60); // max 60 latest
            $query->latest();

            $orders = $query->get();

            return OrderIndexResource::collection($orders);
        });
    }

    /**
     * Get drivers for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function drivers(Request $request)
    {
        $bounds      = $this->normalizeLiveBounds($request);
        $limit       = $this->normalizeLiveLimit($request);
        $cacheParams = ['bounds' => $bounds, 'limit' => $limit];

        return LiveCacheService::remember('drivers', $cacheParams, function () use ($bounds, $limit) {
            $query = Driver::where(['company_uuid' => session('company')])
                ->with(['user', 'vehicle'])
                ->applyDirectivesForPermissions('fleet-ops list driver');

            $this->applyLiveLocationGuards($query);
            $this->applyLiveViewportBounds($query, $bounds);

            $query->orderByDesc('updated_at')->orderByDesc('id')->limit($limit);

            $drivers = $query->get();

            return DriverResource::collection($drivers);
        });
    }

    /**
     * Get vehicles for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function vehicles(Request $request)
    {
        $bounds      = $this->normalizeLiveBounds($request);
        $limit       = $this->normalizeLiveLimit($request);
        $cacheParams = ['bounds' => $bounds, 'limit' => $limit];

        return LiveCacheService::remember('vehicles', $cacheParams, function () use ($bounds, $limit) {
            // Fetch vehicles that are online
            $query = Vehicle::where(['company_uuid' => session('company')])
                ->with(['devices', 'driver'])
                ->applyDirectivesForPermissions('fleet-ops list vehicle');

            $this->applyLiveLocationGuards($query);
            $this->applyLiveViewportBounds($query, $bounds);

            $query->orderByDesc('updated_at')->orderByDesc('id')->limit($limit);

            $vehicles = $query->get();

            return VehicleIndexResource::collection($vehicles);
        });
    }

    /**
     * Get the complete resource snapshot used by the operations sidebar monitor.
     *
     * @return array
     */
    public function operationsMonitor()
    {
        return LiveCacheService::remember('operations-monitor', [], function () {
            $drivers = Driver::where(['company_uuid' => session('company')])
                ->with(['user', 'vehicle'])
                ->applyDirectivesForPermissions('fleet-ops list driver')
                ->orderBy('id')
                ->get();

            $vehicles = Vehicle::where(['company_uuid' => session('company')])
                ->with(['driver'])
                ->applyDirectivesForPermissions('fleet-ops list vehicle')
                ->orderBy('id')
                ->get();

            $fleets = Fleet::where(['company_uuid' => session('company')])
                ->applyDirectivesForPermissions('fleet-ops list fleet')
                ->orderBy('name')
                ->orderBy('id')
                ->get();

            $driverIdsByUuid    = $drivers->pluck('uuid', 'uuid');
            $vehicleIdsByUuid   = $vehicles->pluck('uuid', 'uuid');
            $onlineDriverUuids  = $drivers->where('online', true)->pluck('uuid')->flip();
            $onlineVehicleUuids = $vehicles->where('online', true)->pluck('uuid')->flip();
            $fleetUuids         = $fleets->pluck('uuid');

            $driverMemberships = FleetDriver::whereIn('fleet_uuid', $fleetUuids)
                ->whereIn('driver_uuid', $driverIdsByUuid->keys())
                ->get()
                ->groupBy('fleet_uuid');

            $vehicleMemberships = FleetVehicle::whereIn('fleet_uuid', $fleetUuids)
                ->whereIn('vehicle_uuid', $vehicleIdsByUuid->keys())
                ->get()
                ->groupBy('fleet_uuid');

            $fleetNodes = $fleets->mapWithKeys(function (Fleet $fleet) use ($driverMemberships, $vehicleMemberships, $driverIdsByUuid, $vehicleIdsByUuid, $onlineDriverUuids, $onlineVehicleUuids) {
                $fleetDriverUuids  = $driverMemberships->get($fleet->uuid, collect())->pluck('driver_uuid')->unique()->values();
                $fleetVehicleUuids = $vehicleMemberships->get($fleet->uuid, collect())->pluck('vehicle_uuid')->unique()->values();
                $driverIds         = $fleetDriverUuids->map(fn ($uuid) => $driverIdsByUuid->get($uuid))->filter()->values();
                $vehicleIds        = $fleetVehicleUuids->map(fn ($uuid) => $vehicleIdsByUuid->get($uuid))->filter()->values();

                return [
                    $fleet->uuid => [
                        'id'                    => $fleet->uuid,
                        'uuid'                  => $fleet->uuid,
                        'public_id'             => $fleet->public_id,
                        'name'                  => $fleet->name,
                        'task'                  => $fleet->task,
                        'status'                => $fleet->status,
                        'slug'                  => $fleet->slug,
                        'parent_fleet_uuid'     => $fleet->parent_fleet_uuid,
                        'drivers_count'         => $driverIds->count(),
                        'drivers_online_count'  => $fleetDriverUuids->filter(fn ($uuid) => $onlineDriverUuids->has($uuid))->count(),
                        'vehicles_count'        => $vehicleIds->count(),
                        'vehicles_online_count' => $fleetVehicleUuids->filter(fn ($uuid) => $onlineVehicleUuids->has($uuid))->count(),
                        'driver_ids'            => $driverIds->all(),
                        'vehicle_ids'           => $vehicleIds->all(),
                        'subfleets'             => [],
                        'updated_at'            => $fleet->updated_at,
                        'created_at'            => $fleet->created_at,
                    ],
                ];
            });

            return [
                'drivers'  => $drivers->map(fn (Driver $driver) => $this->serializeMonitorDriver($driver))->values()->all(),
                'vehicles' => $vehicles->map(fn (Vehicle $vehicle) => $this->serializeMonitorVehicle($vehicle))->values()->all(),
                'fleets'   => $this->buildOperationsMonitorFleetTree($fleetNodes),
                'meta'     => [
                    'generated_at'   => now()->toISOString(),
                    'ttl'            => LiveCacheService::DEFAULT_TTL,
                    'drivers_count'  => $drivers->count(),
                    'vehicles_count' => $vehicles->count(),
                    'fleets_count'   => $fleets->count(),
                ],
            ];
        });
    }

    protected function serializeMonitorDriver(Driver $driver): array
    {
        return [
            'id'                    => $driver->uuid,
            'uuid'                  => $driver->uuid,
            'public_id'             => $driver->public_id,
            'company_uuid'          => $driver->company_uuid,
            'user_uuid'             => $driver->user_uuid,
            'vehicle_uuid'          => $driver->vehicle_uuid,
            'vendor_uuid'           => $driver->vendor_uuid,
            'current_job_uuid'      => $driver->current_job_uuid,
            'name'                  => $driver->name,
            'email'                 => $driver->email,
            'phone'                 => $driver->phone,
            'photo_url'             => $driver->photo_url,
            'avatar_url'            => $driver->photo_url,
            'vehicle_name'          => $driver->vehicle_name,
            'status'                => $driver->status,
            'location'              => Utils::castPoint($driver->location),
            'heading'               => (int) data_get($driver, 'heading', 0),
            'altitude'              => (int) data_get($driver, 'altitude', 0),
            'speed'                 => (int) data_get($driver, 'speed', 0),
            'online'                => (bool) data_get($driver, 'online', false),
            'assigned_orders_count' => null,
            'meta'                  => [
                '_index_resource' => true,
            ],
            'updated_at'            => $driver->updated_at,
            'created_at'            => $driver->created_at,
        ];
    }

    protected function serializeMonitorVehicle(Vehicle $vehicle): array
    {
        return [
            'id'                    => $vehicle->uuid,
            'uuid'                  => $vehicle->uuid,
            'public_id'             => $vehicle->public_id,
            'company_uuid'          => $vehicle->company_uuid,
            'vendor_uuid'           => $vehicle->vendor_uuid,
            'photo_uuid'            => $vehicle->photo_uuid,
            'internal_id'           => $vehicle->internal_id,
            'name'                  => $vehicle->name,
            'display_name'          => $vehicle->display_name,
            'driver_name'           => $vehicle->driver_name,
            'plate_number'          => $vehicle->plate_number,
            'serial_number'         => $vehicle->serial_number,
            'fuel_card_number'      => $vehicle->fuel_card_number,
            'vin'                   => $vehicle->vin,
            'make'                  => $vehicle->make,
            'model'                 => $vehicle->model,
            'year'                  => $vehicle->year,
            'photo_url'             => $vehicle->photo_url,
            'avatar_url'            => $vehicle->avatar_url,
            'status'                => $vehicle->status,
            'location'              => Utils::castPoint($vehicle->location),
            'heading'               => (int) data_get($vehicle, 'heading', 0),
            'altitude'              => (int) data_get($vehicle, 'altitude', 0),
            'speed'                 => (int) data_get($vehicle, 'speed', 0),
            'online'                => (bool) data_get($vehicle, 'online', false),
            'assigned_orders_count' => null,
            'meta'                  => [
                '_index_resource' => true,
            ],
            'updated_at'            => $vehicle->updated_at,
            'created_at'            => $vehicle->created_at,
        ];
    }

    protected function buildOperationsMonitorFleetTree($fleetNodes): array
    {
        $nodes = $fleetNodes->map(fn ($node) => $node)->all();

        foreach ($nodes as $uuid => $node) {
            $parentUuid = data_get($node, 'parent_fleet_uuid');

            if ($parentUuid && isset($nodes[$parentUuid])) {
                $nodes[$parentUuid]['subfleets'][] = &$nodes[$uuid];
            }
        }

        return collect($nodes)
            ->filter(fn ($node) => empty(data_get($node, 'parent_fleet_uuid')) || !isset($nodes[data_get($node, 'parent_fleet_uuid')]))
            ->values()
            ->all();
    }

    /**
     * Get places based on filters for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function places(Request $request)
    {
        $bounds = $this->normalizeLiveBounds($request);
        $limit  = $this->normalizeLiveLimit($request);

        // Cache key includes filter parameters
        $cacheParams = array_merge($request->only(['query', 'type', 'country']), [
            'bounds' => $bounds,
            'limit'  => $limit,
        ]);

        return LiveCacheService::remember('places', $cacheParams, function () use ($request, $bounds, $limit) {
            // Query places based on filters
            $query = Place::where(['company_uuid' => session('company')])
                ->filter(new PlaceFilter($request))
                ->applyDirectivesForPermissions('fleet-ops list place');

            $this->applyLiveLocationGuards($query);
            $this->applyLiveViewportBounds($query, $bounds);

            $query->orderByDesc('updated_at')->orderByDesc('id')->limit($limit);

            $places = $query->get();

            return PlaceIndexResource::collection($places);
        });
    }

    protected function normalizeLiveBounds(Request $request): ?array
    {
        $bounds = $request->input('bounds');

        if (!is_array($bounds) || count($bounds) !== 4) {
            return null;
        }

        $bounds = array_values($bounds);

        if (array_filter($bounds, fn ($value) => !is_numeric($value))) {
            return null;
        }

        [$south, $west, $north, $east] = array_map('floatval', $bounds);

        if ($south < -90 || $south > 90 || $north < -90 || $north > 90) {
            return null;
        }

        if ($west < -180 || $west > 180 || $east < -180 || $east > 180) {
            return null;
        }

        if ($south > $north || $west > $east) {
            return null;
        }

        return array_map(
            fn ($value) => round($value, static::VIEWPORT_BOUNDS_PRECISION),
            [$south, $west, $north, $east]
        );
    }

    protected function normalizeLiveLimit(Request $request): int
    {
        $limit = (int) $request->input('limit', static::DEFAULT_VIEWPORT_LIMIT);

        if ($limit < 1) {
            return static::DEFAULT_VIEWPORT_LIMIT;
        }

        return min($limit, static::MAX_VIEWPORT_LIMIT);
    }

    protected function applyLiveLocationGuards($query): void
    {
        $query->whereNotNull('location')
            ->whereRaw('
                ST_Y(location) BETWEEN -90 AND 90
                AND ST_X(location) BETWEEN -180 AND 180
                AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)
            ');
    }

    protected function applyLiveViewportBounds($query, ?array $bounds): void
    {
        if (!$bounds) {
            return;
        }

        [$south, $west, $north, $east] = $bounds;

        $query->whereRaw(
            'ST_Y(location) BETWEEN ? AND ? AND ST_X(location) BETWEEN ? AND ?',
            [$south, $north, $west, $east]
        );
    }
}
