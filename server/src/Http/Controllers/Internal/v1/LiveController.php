<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Filter\PlaceFilter;
use Fleetbase\FleetOps\Http\Resources\v1\Driver as DriverResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Order as OrderIndexResource;
use Fleetbase\FleetOps\Http\Resources\v1\Place as PlaceResource;
use Fleetbase\FleetOps\Http\Resources\v1\Vehicle as VehicleResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Route;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\LiveCacheService;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Class LiveController.
 */
class LiveController extends Controller
{
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

            // Loop through each order to get its current destination location
            foreach ($orders as $order) {
                $coordinates[] = $order->getCurrentDestinationLocation();
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
        $withTracker = $request->has('with_tracker_data');

        // Cache key includes all parameters that affect the query
        $cacheParams = [
            'exclude'      => $exclude,
            'active'       => $active,
            'unassigned'   => $unassigned,
            'with_tracker' => $withTracker,
        ];

        return LiveCacheService::remember('orders', $cacheParams, function () use ($exclude, $active, $unassigned) {
            $query = Order::where('company_uuid', session('company'))
            ->whereHas('payload', function ($query) {
                $query->where(
                    function ($q) {
                        $q->whereHas('waypoints');
                        $q->orWhereHas('pickup');
                        $q->orWhereHas('dropoff');
                    }
                );
            })
            ->whereNotIn('status', ['canceled', 'completed', 'expired'])
            ->whereHas('trackingNumber')
            ->whereHas('trackingStatuses')
            ->whereNotIn('public_id', $exclude)
            ->whereNull('deleted_at')
            ->applyDirectivesForPermissions('fleet-ops list order')
            ->with([
                'payload.entities',
                'payload.waypoints',
                'payload.dropoff',
                'payload.pickup',
                'payload.return',
                'trackingNumber',
                'trackingStatuses',
                'driverAssigned' => function ($query) {
                    $query->without(['jobs', 'currentJob']);
                },
                'vehicleAssigned' => function ($query) {
                    $query->without(['fleets', 'vendor']);
                },
                'customer',
                'facilitator',
            ]);

            if ($active) {
                $query->whereHas('driverAssigned');
            }

            if ($unassigned) {
                $query->whereNull('driver_assigned_uuid');
            }

            $query->limit(60); // max 60 latest
            $query->latest();

            $orders = $query->get();

            // // Load tracker data if requested (limit to first 20 orders for performance)
            // if ($withTracker) {
            //     $orders->take(20)->each(function ($order) {
            //         $order->tracker_data = $order->tracker()->toArray();
            //         $order->eta          = $order->tracker()->eta();
            //     });
            // }

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
        $bounds = $request->input('bounds'); // Map viewport bounds: [south, west, north, east]
        $cacheParams = ['bounds' => $bounds];
        
        return LiveCacheService::remember('drivers', $cacheParams, function () use ($bounds) {
            $query = Driver::where(['company_uuid' => session('company')])
                ->with(['user', 'vehicle', 'currentJob'])
                ->applyDirectivesForPermissions('fleet-ops list driver');
            
            // Apply spatial filtering if bounds are provided
            if ($bounds && is_array($bounds) && count($bounds) === 4) {
                [$south, $west, $north, $east] = $bounds;
                
                $query->whereBetween('latitude', [$south, $north])
                      ->whereBetween('longitude', [$west, $east]);
            }
            
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
        $bounds = $request->input('bounds'); // Map viewport bounds: [south, west, north, east]
        $cacheParams = ['bounds' => $bounds];
        
        return LiveCacheService::remember('vehicles', $cacheParams, function () use ($bounds) {
            // Fetch vehicles that are online
            $query = Vehicle::where(['company_uuid' => session('company')])
                ->with(['devices', 'driver'])
                ->applyDirectivesForPermissions('fleet-ops list vehicle');
            
            // Apply spatial filtering if bounds are provided
            if ($bounds && is_array($bounds) && count($bounds) === 4) {
                [$south, $west, $north, $east] = $bounds;
                
                $query->whereBetween('latitude', [$south, $north])
                      ->whereBetween('longitude', [$west, $east]);
            }
            
            $vehicles = $query->get();

            return VehicleResource::collection($vehicles);
        });
    }

    /**
     * Get places based on filters for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function places(Request $request)
    {
        // Cache key includes filter parameters
        $cacheParams = $request->only(['query', 'type', 'country', 'limit', 'bounds']);

        return LiveCacheService::remember('places', $cacheParams, function () use ($request) {
            // Query places based on filters
            $query = Place::where(['company_uuid' => session('company')])
                ->filter(new PlaceFilter($request))
                ->applyDirectivesForPermissions('fleet-ops list place');
            
            // Apply spatial filtering if bounds are provided
            $bounds = $request->input('bounds');
            if ($bounds && is_array($bounds) && count($bounds) === 4) {
                [$south, $west, $north, $east] = $bounds;
                
                $query->whereBetween('latitude', [$south, $north])
                      ->whereBetween('longitude', [$west, $east]);
            }
            
            $places = $query->get();

            return PlaceResource::collection($places);
        });
    }
}
