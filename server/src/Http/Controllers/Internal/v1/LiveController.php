<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Filter\PlaceFilter;
use Fleetbase\FleetOps\Http\Resources\v1\Driver as DriverResource;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Http\Resources\v1\Place as PlaceResource;
use Fleetbase\FleetOps\Http\Resources\v1\Vehicle as VehicleResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Route;
use Fleetbase\FleetOps\Models\Vehicle;
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
        $coordinates = [];

        // Fetch active orders for the current company
        $orders = Order::where('company_uuid', session('company'))
            ->whereNotIn('status', ['canceled', 'completed'])
            ->applyDirectivesForPermissions('fleet-ops list order')
            ->get();

        // Loop through each order to get its current destination location
        foreach ($orders as $order) {
            $coordinates[] = $order->getCurrentDestinationLocation();
        }

        return response()->json($coordinates);
    }

    /**
     * Get active routes for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function routes()
    {
        // Fetch routes that are not canceled or completed and have an assigned driver
        $routes = Route::where('company_uuid', session('company'))
            ->whereHas(
                'order',
                function ($q) {
                    $q->whereNotIn('status', ['canceled', 'completed']);
                    $q->whereNotNull('driver_assigned_uuid');
                    $q->whereNull('deleted_at');
                }
            )
            ->applyDirectivesForPermissions('fleet-ops list route')
            ->get();

        return response()->json($routes);
    }

    /**
     * Get active orders with payload for the current company.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function orders(Request $request)
    {
        $exclude = $request->array('exclude');

        $orders = Order::where('company_uuid', session('company'))
            ->whereHas('payload')
            ->whereNotIn('status', ['canceled', 'completed'])
            ->whereNotIn('public_id', $exclude)
            ->whereNotNull('driver_assigned_uuid')
            ->whereNull('deleted_at')
            ->applyDirectivesForPermissions('fleet-ops list order')
            ->get();

        return OrderResource::collection($orders);
    }

    /**
     * Get drivers for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function drivers()
    {
        $drivers = Driver::where(['company_uuid' => session('company')])
            ->applyDirectivesForPermissions('fleet-ops list driver')
            ->get();

        return DriverResource::collection($drivers);
    }

    /**
     * Get vehicles for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function vehicles()
    {
        // Fetch vehicles that are online
        $vehicles = Vehicle::where(['company_uuid' => session('company')])
            ->with(['devices'])
            ->applyDirectivesForPermissions('fleet-ops list vehicle')
            ->get();

        return VehicleResource::collection($vehicles);
    }

    /**
     * Get places based on filters for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function places(Request $request)
    {
        // Query places based on filters
        $places = Place::where(['company_uuid' => session('company')])
            ->filter(new PlaceFilter($request))
            ->applyDirectivesForPermissions('fleet-ops list place')
            ->get();

        return PlaceResource::collection($places);
    }
}
