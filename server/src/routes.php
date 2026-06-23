<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
Route::prefix(config('fleetops.api.routing.prefix'))->namespace('Fleetbase\FleetOps\Http\Controllers')->group(
    function ($router) {
        /*
        |--------------------------------------------------------------------------
        | Consumable FleetOps API Routes
        |--------------------------------------------------------------------------
        |
        | End-user API routes, these are routes that the SDK and applications will interface with, and require API credentials.
        */
        $router->group(['prefix' => 'v1', 'middleware' => ['fleetbase.api', Fleetbase\FleetOps\Http\Middleware\TransformLocationMiddleware::class], 'namespace' => 'Api\v1'], function ($router) {
            // drivers routes
            $router->group(['prefix' => 'drivers', 'middleware' => []], function () use ($router) {
                $router->post('register-device', 'DriverController@registerDevice');
                $router->post('login-with-sms', 'DriverController@loginWithPhone');
                $router->post('verify-code', 'DriverController@verifyCode');
                $router->post('login', 'DriverController@login');
                $router->post('{id}/simulate', 'DriverController@simulate');
                $router->match(['put', 'patch', 'post'], '{id}/track', 'DriverController@track');
                $router->post('{id}/register-device', 'DriverController@registerDevice');
                $router->post('{id}/switch-organization', 'DriverController@switchOrganization');
                $router->post('{id}/toggle-online', 'DriverController@toggleOnline');
                $router->post('/', 'DriverController@create');
                $router->get('/', 'DriverController@query');
                $router->get('{id}', 'DriverController@find');
                $router->get('{id}/organizations', 'DriverController@listOrganizations');
                $router->get('{id}/current-organization', 'DriverController@currentOrganization');
                $router->put('{id}', 'DriverController@update');
                $router->delete('{id}', 'DriverController@delete');
            });
            // contacts routes
            $router->group(['prefix' => 'contacts'], function () use ($router) {
                $router->post('/', 'ContactController@create');
                $router->get('/', 'ContactController@query');
                $router->get('{id}', 'ContactController@find');
                $router->put('{id}', 'ContactController@update');
                $router->delete('{id}', 'ContactController@delete');
            });
            // order-configs — read-only public projection of the OrderConfig flow.
            $router->group(['prefix' => 'order-configs'], function () use ($router) {
                $router->get('/', 'OrderConfigController@query');
                $router->get('{id}', 'OrderConfigController@find');
            });
            // customers routes — public B2C customer auth + customer-scoped orders.
            //  - Public endpoints: API key only (resolves company from credential)
            //  - Authenticated endpoints: API key + `Customer-Token` header (Sanctum)
            $router->group(['prefix' => 'customers', 'middleware' => []], function () use ($router) {
                // Public auth flows
                $router->post('request-creation-code', 'CustomerController@requestCreationCode');
                $router->post('/', 'CustomerController@create');
                $router->post('login', 'CustomerController@login');
                $router->post('login-with-sms', 'CustomerController@loginWithPhone');
                $router->post('verify-code', 'CustomerController@verifyCode');
                $router->post('forgot-password', 'CustomerController@forgotPassword');
                $router->post('reset-password', 'CustomerController@resetPassword');

                // Authenticated (require Customer-Token)
                $router->group(['middleware' => [Fleetbase\FleetOps\Http\Middleware\AuthenticateCustomerToken::class]], function () use ($router) {
                    $router->get('me', 'CustomerController@me');
                    $router->put('me', 'CustomerController@updateMe');
                    $router->match(['post', 'patch'], 'me', 'CustomerController@updateMe');
                    $router->post('logout', 'CustomerController@logout');
                    $router->post('logout-all', 'CustomerController@logoutAll');
                    $router->post('register-device', 'CustomerController@registerDevice');
                    $router->get('places', 'CustomerController@places');
                    $router->get('orders', 'CustomerController@orders');
                    $router->post('orders', 'CustomerController@createOrder');
                    $router->get('orders/{id}', 'CustomerController@findOrder');
                });
            });
            // vendors routes
            $router->group(['prefix' => 'vendors'], function () use ($router) {
                $router->post('/', 'VendorController@create');
                $router->get('/', 'VendorController@query');
                $router->get('{id}', 'VendorController@find');
                $router->put('{id}', 'VendorController@update');
                $router->delete('{id}', 'VendorController@delete');
            });
            // issue routes
            $router->group(['prefix' => 'issues'], function () use ($router) {
                $router->post('/', 'IssueController@create');
                $router->get('/', 'IssueController@query');
                $router->get('{id}', 'IssueController@find');
                $router->put('{id}', 'IssueController@update');
                $router->delete('{id}', 'IssueController@delete');
            });
            // fuel-reports routes
            $router->group(['prefix' => 'fuel-reports'], function () use ($router) {
                $router->post('/', 'FuelReportController@create');
                $router->get('/', 'FuelReportController@query');
                $router->get('{id}', 'FuelReportController@find');
                $router->put('{id}', 'FuelReportController@update');
                $router->delete('{id}', 'FuelReportController@delete');
            });
            // fuel-transactions routes
            $router->group(['prefix' => 'fuel-transactions'], function () use ($router) {
                $router->post('/', 'FuelTransactionController@create');
                $router->get('/', 'FuelTransactionController@query');
                $router->get('{id}', 'FuelTransactionController@find');
                $router->put('{id}', 'FuelTransactionController@update');
                $router->delete('{id}', 'FuelTransactionController@delete');
                $router->post('{id}/match-vehicle', 'FuelTransactionController@matchVehicle');
                $router->post('{id}/match-order', 'FuelTransactionController@matchOrder');
                $router->post('{id}/reprocess', 'FuelTransactionController@reprocess');
                $router->post('{id}/review', 'FuelTransactionController@review');
            });
            // equipment routes
            $router->group(['prefix' => 'equipment'], function () use ($router) {
                $router->post('/', 'EquipmentController@create');
                $router->get('/', 'EquipmentController@query');
                $router->get('{id}', 'EquipmentController@find');
                $router->put('{id}', 'EquipmentController@update');
                $router->delete('{id}', 'EquipmentController@delete');
            });
            // parts routes
            $router->group(['prefix' => 'parts'], function () use ($router) {
                $router->post('/', 'PartController@create');
                $router->get('/', 'PartController@query');
                $router->get('{id}', 'PartController@find');
                $router->put('{id}', 'PartController@update');
                $router->delete('{id}', 'PartController@delete');
            });
            // work-orders routes
            $router->group(['prefix' => 'work-orders'], function () use ($router) {
                $router->post('/', 'WorkOrderController@create');
                $router->get('/', 'WorkOrderController@query');
                $router->get('{id}', 'WorkOrderController@find');
                $router->put('{id}', 'WorkOrderController@update');
                $router->delete('{id}', 'WorkOrderController@delete');
                $router->post('{id}/send', 'WorkOrderController@send');
            });
            // devices routes
            $router->group(['prefix' => 'devices'], function () use ($router) {
                $router->post('/', 'DeviceController@create');
                $router->get('/', 'DeviceController@query');
                $router->get('{id}', 'DeviceController@find');
                $router->put('{id}', 'DeviceController@update');
                $router->delete('{id}', 'DeviceController@delete');
                $router->post('{id}/attach', 'DeviceController@attach');
                $router->post('{id}/detach', 'DeviceController@detach');
            });
            // sensors routes
            $router->group(['prefix' => 'sensors'], function () use ($router) {
                $router->post('/', 'SensorController@create');
                $router->get('/', 'SensorController@query');
                $router->get('{id}', 'SensorController@find');
                $router->put('{id}', 'SensorController@update');
                $router->delete('{id}', 'SensorController@delete');
            });
            // orders routes
            $router->group(['prefix' => 'orders', 'middleware' => []], function () use ($router) {
                $router->post('/', 'OrderController@create');
                $router->get('/', 'OrderController@query');
                $router->get('{id}', 'OrderController@find');
                $router->get('{id}/distance-and-time', 'OrderController@getDistanceMatrix');
                $router->match(['post', 'patch'], '{id}/schedule', 'OrderController@scheduleOrder');
                $router->match(['post', 'patch'], '{id}/dispatch', 'OrderController@dispatchOrder');
                $router->post('{id}/start', 'OrderController@startOrder');
                $router->delete('{id}/cancel', 'OrderController@cancelOrder');
                $router->match(['post', 'patch'], '{id}/update-activity', 'OrderController@updateActivity');
                $router->post('{id}/complete', 'OrderController@completeOrder');
                $router->get('{id}/next-activity', 'OrderController@getNextActivity');
                $router->get('{id}/tracker', 'OrderController@trackerData');
                $router->get('{id}/eta', 'OrderController@etaData');
                $router->get('{id}/comments', 'OrderController@orderComments');
                $router->match(['post', 'patch'], '{id}/set-destination/{placeId}', 'OrderController@setDestination');
                $router->post('{id}/capture-signature/{subjectId?}', 'OrderController@captureSignature');
                $router->post('{id}/capture-qr/{subjectId?}', 'OrderController@captureQrScan');
                $router->post('{id}/capture-photo/{subjectId?}', 'OrderController@capturePhoto');
                $router->get('{id}/proofs/{subjectId?}', 'OrderController@proofs');
                $router->put('{id}', 'OrderController@update');
                $router->delete('{id}', 'OrderController@delete');
                $router->get('{id}/editable-entity-fields', 'OrderController@getEditableEntityFields');
            });
            // entities routes
            $router->group(['prefix' => 'entities'], function () use ($router) {
                $router->post('/', 'EntityController@create');
                $router->get('/', 'EntityController@query');
                $router->get('{id}', 'EntityController@find');
                $router->put('{id}', 'EntityController@update');
                $router->delete('{id}', 'EntityController@delete');
            });
            // payloads routes
            $router->group(['prefix' => 'payloads'], function () use ($router) {
                $router->post('/', 'PayloadController@create');
                $router->get('/', 'PayloadController@query');
                $router->get('{id}', 'PayloadController@find');
                $router->put('{id}', 'PayloadController@update');
                $router->delete('{id}', 'PayloadController@delete');
            });
            // purchase-rates routes
            $router->group(['prefix' => 'purchase-rates'], function () use ($router) {
                $router->post('/', 'PurchaseRateController@create');
                $router->get('/', 'PurchaseRateController@query');
                $router->get('{id}', 'PurchaseRateController@find');
            });
            // places routes
            $router->group(['prefix' => 'places'], function () use ($router) {
                $router->post('/', 'PlaceController@create');
                $router->get('/', 'PlaceController@query');
                $router->get('search', 'PlaceController@search');
                $router->get('{id}', 'PlaceController@find');
                $router->put('{id}', 'PlaceController@update');
                $router->delete('{id}', 'PlaceController@delete');
            });
            // zones routes
            $router->group(['prefix' => 'zones'], function () use ($router) {
                $router->post('/', 'ZoneController@create');
                $router->get('/', 'ZoneController@query');
                $router->get('{id}', 'ZoneController@find');
                $router->put('{id}', 'ZoneController@update');
                $router->delete('{id}', 'ZoneController@delete');
            });
            // service-areas routes
            $router->group(['prefix' => 'service-areas'], function () use ($router) {
                $router->post('/', 'ServiceAreaController@create');
                $router->get('/', 'ServiceAreaController@query');
                $router->get('{id}', 'ServiceAreaController@find');
                $router->put('{id}', 'ServiceAreaController@update');
                $router->delete('{id}', 'ServiceAreaController@delete');
            });
            // geofences routes
            $router->group(['prefix' => 'geofences'], function () use ($router) {
                $router->get('events', 'GeofenceController@events');
                $router->get('inventory', 'GeofenceController@inventory');
                $router->get('dwell-report', 'GeofenceController@dwellReport');
                $router->get('driver/{driverUuid}/history', 'GeofenceController@driverHistory');
            });
            // service-rates routes
            $router->group(['prefix' => 'service-rates'], function () use ($router) {
                $router->post('/', 'ServiceRateController@create');
                $router->get('/', 'ServiceRateController@query');
                $router->get('{id}', 'ServiceRateController@find');
                $router->put('{id}', 'ServiceRateController@update');
                $router->delete('{id}', 'ServiceRateController@delete');
            });
            // service-quotes routes
            $router->group(['prefix' => 'service-quotes'], function () use ($router) {
                $router->get('/', 'ServiceQuoteController@query');
                $router->get('{id}', 'ServiceQuoteController@find');
            });
            // tracking-numbers routes
            $router->group(['prefix' => 'tracking-numbers'], function () use ($router) {
                $router->post('/', 'TrackingNumberController@create');
                $router->post('from-qr', 'TrackingNumberController@fromQR');
                $router->get('/', 'TrackingNumberController@query');
                $router->get('{id}', 'TrackingNumberController@find');
                $router->delete('{id}', 'TrackingNumberController@delete');
            });
            // tracking-statuses routes
            $router->group(['prefix' => 'tracking-statuses'], function () use ($router) {
                $router->post('/', 'TrackingStatusController@create');
                $router->get('/', 'TrackingStatusController@query');
                $router->get('{id}', 'TrackingStatusController@find');
                $router->put('{id}', 'TrackingStatusController@update');
                $router->delete('{id}', 'TrackingStatusController@delete');
            });
            // vehicle routes
            $router->group(['prefix' => 'vehicles'], function () use ($router) {
                $router->post('/', 'VehicleController@create');
                $router->get('/', 'VehicleController@query');
                $router->get('{id}', 'VehicleController@find');
                $router->put('{id}', 'VehicleController@update');
                $router->delete('{id}', 'VehicleController@delete');
                $router->match(['put', 'patch', 'post'], '{id}/track', 'VehicleController@track');
            });
            // fleets routes
            $router->group(['prefix' => 'fleets'], function () use ($router) {
                $router->post('/', 'FleetController@create');
                $router->get('/', 'FleetController@query');
                $router->get('{id}', 'FleetController@find');
                $router->put('{id}', 'FleetController@update');
                $router->delete('{id}', 'FleetController@delete');
            });
            // labels routes
            $router->group(['prefix' => 'labels'], function () use ($router) {
                $router->get('{id}', 'LabelController@getLabel');
            });

            // orchestrator routes
            $router->group(['prefix' => 'orchestrator'], function () use ($router) {
                $router->post('run', 'OrchestrationController@run');
                $router->post('commit', 'OrchestrationController@commit');
            });

            // navigator routes
            $router->group(['prefix' => 'onboard'], function () use ($router) {
                $router->get('driver-onboard-settings/{companyId}', 'NavigatorController@getDriverOnboardSettings');
            });
        });

        /*
         |--------------------------------------------------------------------------
         | Publicly Consumable FleetOps API Routes
         |--------------------------------------------------------------------------
         |
         | End-user API routes, these are routes that the SDK and applications will interface with, that DO NOT REQUIRE API credentials.
         */
        $router->group(['prefix' => 'v1', 'namespace' => 'Api\v1'], function () use ($router) {
            $router->get('organizations', 'OrganizationController@listOrganizations');
        });

        /*
         |--------------------------------------------------------------------------
         | Webhook Integration Routes
         |--------------------------------------------------------------------------
         |
         | End-user API routes, these are routes used for Webhook integrations.
         */
        $router->group(['prefix' => 'webhooks'], function () use ($router) {
            $router->any('telematics/{providerKey}', 'TelematicWebhookController@handle');
            $router->any('telematics/ingest/{id}', 'TelematicWebhookController@ingest');
        });

        /*
        |--------------------------------------------------------------------------
        | Internal FleetOps API Routes
        |--------------------------------------------------------------------------
        |
        | Primary internal routes for console.
        */
        $router->prefix(config('fleetops.api.routing.internal_prefix', 'int'))->namespace('Internal')->group(
            function ($router) {
                $router->group(
                    ['prefix' => 'v1/fleet-ops', 'namespace' => 'v1'],
                    function ($router) {
                        $router->get('lookup', 'OrderController@lookup');
                    }
                );

                $router->group(
                    ['prefix' => 'v1/fleet-ops/navigator', 'namespace' => 'v1'],
                    function ($router) {
                        $router->get('get-link-app', 'NavigatorController@getLinkAppUrl');
                        $router->get('link-app', 'NavigatorController@linkApp');
                    }
                );

                $router->group(
                    [
                        'prefix'     => 'v1',
                        'namespace'  => 'v1',
                        'middleware' => [
                            'fleetbase.protected',
                            Fleetbase\FleetOps\Http\Middleware\TransformLocationMiddleware::class,
                            Fleetbase\FleetOps\Http\Middleware\SetupDriverSession::class,
                        ],
                    ],
                    function ($router) {
                        $router->get('search', 'SearchController@search');

                        $router->fleetbaseRoutes(
                            'contacts',
                            function ($router, $controller) {
                                $router->match(['get', 'post'], 'export', $controller('export'));
                                $router->post('import', $controller('import'));
                                $router->get('facilitators/{id}', $controller('getAsFacilitator'));
                                $router->get('customers/{id}', $controller('getAsCustomer'));
                                $router->post('{id}/convert-to-vendor', $controller('convertToVendor'));
                            }
                        );
                        $router->fleetbaseRoutes(
                            'drivers',
                            function ($router, $controller) {
                                $router->get('statuses', $controller('statuses'));
                                $router->get('avatars', $controller('avatars'));
                                $router->get('{id}/assigned-orders', $controller('assignedOrders'));
                                $router->post('{id}/assign-order', $controller('assignOrder'));
                                $router->post('{id}/unassign-orders', $controller('unassignOrders'));
                                $router->post('{id}/unassign-order', $controller('unassignOrder'));
                                $router->post('{id}/assign-vehicle', $controller('assignVehicle'));
                                $router->post('{id}/unassign-vehicle', $controller('unassignVehicle'));
                                $router->match(['get', 'post'], 'export', $controller('export'));
                                $router->post('import', $controller('import'));
                                // Driver scheduling endpoints
                                $router->get('{id}/schedule-items', $controller('scheduleItems'));
                                $router->get('{id}/availabilities', $controller('availabilities'));
                                $router->get('{id}/hos-status', $controller('hosStatus'));
                                $router->get('{id}/active-shift', $controller('activeShift'));
                            }
                        );
                        $router->fleetbaseRoutes('entities');
                        $router->fleetbaseRoutes(
                            'fleets',
                            function ($router, $controller) {
                                $router->post('assign-driver', $controller('assignDriver'));
                                $router->post('remove-driver', $controller('removeDriver'));
                                $router->post('assign-vehicle', $controller('assignVehicle'));
                                $router->post('remove-vehicle', $controller('removeVehicle'));
                                $router->match(['get', 'post'], 'export', $controller('export'));
                                $router->post('import', $controller('import'));
                            }
                        );
                        $router->fleetbaseRoutes(
                            'fuel-reports',
                            function ($router, $controller) {
                                $router->match(['get', 'post'], 'export', $controller('export'));
                                $router->post('import', $controller('import'));
                            }
                        );
                        $router->fleetbaseRoutes(
                            'fuel-provider-connections',
                            function ($router, $controller) {
                                $router->get('providers', $controller('providers'));
                                $router->post('providers/{provider}/test-credentials', $controller('testCredentials'));
                                $router->post('{id}/test-connection', $controller('testConnection'));
                                $router->post('{id}/sync', $controller('sync'));
                            }
                        );
                        $router->fleetbaseRoutes('fuel-provider-sync-runs');
                        $router->fleetbaseRoutes(
                            'fuel-provider-transactions',
                            function ($router, $controller) {
                                $router->post('{id}/match-vehicle', $controller('matchVehicle'));
                                $router->post('{id}/match-order', $controller('matchOrder'));
                                $router->post('{id}/reprocess', $controller('reprocess'));
                                $router->post('{id}/review', $controller('review'));
                            }
                        );
                        $router->get('issues/{id}/timeline', 'IssueController@timeline');
                        $router->fleetbaseRoutes(
                            'issues',
                            function ($router, $controller) {
                                $router->match(['get', 'post'], 'export', $controller('export'));
                                $router->post('import', $controller('import'));
                            }
                        );
                        $router->fleetbaseRoutes(
                            'integrated-vendors',
                            function ($router, $controller) {
                                $router->get('supported', $controller('getSupported'));
                            }
                        );
                        $router->fleetbaseRoutes(
                            'orders',
                            function ($router, $controller) {
                                $router->get('default-config', $controller('getDefaultOrderConfig'));
                                $router->get('search', $controller('search'));
                                $router->get('statuses', $controller('statuses'));
                                $router->get('types', $controller('types'));
                                $router->get('label/{id}', $controller('label'));
                                $router->get('next-activity/{id}', $controller('nextActivity'));
                                $router->get('{id}/tracker', 'OrderController@trackerInfo');
                                $router->get('{id}/eta', 'OrderController@waypointEtas');
                                $router->post('{id}/ping-driver', $controller('pingDriver'));
                                $router->post('{id}/capture-photo/{subjectId?}', $controller('capturePhoto'));
                                $router->post('process-imports', $controller('importFromFiles'));
                                $router->patch('route/{id}', $controller('editOrderRoute'));
                                $router->patch('set-destination/{id}/{placeId}', $controller('setDestination'));
                                $router->patch('update-activity/{id}', $controller('updateActivity'));
                                $router->get('{id}/proofs/{subjectId?}', $controller('proofs'));
                                $router->patch('bulk-assign-driver', $controller('bulkAssignDriver'));
                                $router->patch('bulk-cancel', $controller('bulkCancel'));
                                $router->post('bulk-dispatch', $controller('bulkDispatch'));
                                $router->patch('cancel', $controller('cancel'));
                                $router->patch('dispatch', $controller('dispatchOrder'));
                                $router->patch('start', $controller('start'));
                                // Scheduler: set scheduled_at + driver without triggering dispatch
                                $router->patch('schedule', $controller('scheduleOrder'));
                                $router->match(['get', 'post'], 'export', $controller('export'));
                            }
                        );
                        $router->fleetbaseRoutes('order-configs');
                        $router->fleetbaseRoutes('payloads');
                        $router->fleetbaseRoutes(
                            'places',
                            function ($router, $controller) {
                                $router->get('search', $controller('search'))->middleware(['cache.headers:private;max_age=3600']);
                                $router->get('lookup', $controller('geocode'))->middleware(['cache.headers:private;max_age=3600']);
                                $router->get('avatars', $controller('avatars'));
                                $router->match(['get', 'post'], 'export', $controller('export'));
                                $router->post('import', $controller('import'));
                            }
                        );
                        $router->fleetbaseRoutes('proofs');
                        $router->fleetbaseRoutes('purchase-rates');
                        $router->fleetbaseRoutes('routes');
                        $router->fleetbaseRoutes('positions', function ($router, $controller) {
                            $router->post('replay', $controller('replay'));
                            $router->post('metrics', $controller('metrics'));
                        });
                        $router->fleetbaseRoutes(
                            'service-areas',
                            function ($router, $controller) {
                                $router->match(['get', 'post'], 'export', $controller('export'));
                            }
                        );
                        $router->group(
                            ['prefix' => 'geofences'],
                            function () use ($router) {
                                $router->get('events', 'GeofenceController@events');
                                $router->get('inventory', 'GeofenceController@inventory');
                                $router->get('dwell-report', 'GeofenceController@dwellReport');
                                $router->get('driver/{driverUuid}/history', 'GeofenceController@driverHistory');
                            }
                        );
                        $router->fleetbaseRoutes('zones');
                        $router->fleetbaseRoutes(
                            'service-quotes',
                            function ($router, $controller) {
                                $router->post('preliminary', $controller('preliminaryQuery'));
                                $router->post('stripe-checkout-session', $controller('createStripeCheckoutSession'));
                                $router->get('stripe-checkout-session', $controller('getStripeCheckoutSessionStatus'));
                            }
                        );
                        $router->fleetbaseRoutes(
                            'service-rates',
                            function ($router, $controller) {
                                $router->get('for-route', $controller('getServicesForRoute'));
                                $router->match(['get', 'post'], 'export', $controller('export'));
                            }
                        );
                        $router->fleetbaseRoutes('tracking-numbers');
                        $router->fleetbaseRoutes('tracking-statuses');
                        $router->fleetbaseRoutes(
                            'vehicles',
                            function ($router, $controller) {
                                $router->get('statuses', $controller('statuses'));
                                $router->get('avatars', $controller('avatars'));
                                $router->post('{id}/assign-driver', $controller('assignDriver'));
                                $router->post('{id}/unassign-driver', $controller('unassignDriver'));
                                $router->get('{id}/assigned-orders', $controller('assignedOrders'));
                                $router->post('{id}/unassign-orders', $controller('unassignOrders'));
                                $router->post('{id}/attach-device', $controller('attachDevice'));
                                $router->post('{id}/detach-device', $controller('detachDevice'));
                                $router->match(['get', 'post'], 'export', $controller('export'));
                                $router->post('import', $controller('import'));
                            }
                        );
                        $router->fleetbaseRoutes('vehicle-devices');
                        $router->fleetbaseRoutes(
                            'vendors',
                            function ($router, $controller) {
                                $router->get('statuses', $controller('statuses'));
                                $router->match(['get', 'post'], 'export', $controller('export'));
                                $router->get('facilitators/{id}', $controller('getAsFacilitator'));
                                $router->get('customers/{id}', $controller('getAsCustomer'));
                                $router->get('{id}/personnels', $controller('vendorPersonnels'));
                                $router->post('{id}/personnels', $controller('addVendorPersonnel'));
                                $router->delete('{id}/personnels/{contact}', $controller('removeVendorPersonnel'));
                                $router->post('{id}/assign-driver', $controller('assignDriver'));
                                $router->post('{id}/remove-driver', $controller('removeDriver'));
                                $router->post('import', $controller('import'));
                            }
                        );
                        $router->fleetbaseRoutes('devices', function ($router, $controller) {
                            $router->match(['get', 'post'], 'export', $controller('export'));
                        });
                        $router->post('devices/{id}/attach', 'DeviceController@attach');
                        $router->post('devices/{id}/detach', 'DeviceController@detach');
                        $router->fleetbaseRoutes('device-events', function ($router, $controller) {
                            $router->post('{id}/mark-processed', $controller('markProcessed'));
                        });
                        $router->fleetbaseRoutes('sensors', function ($router, $controller) {
                            $router->match(['get', 'post'], 'export', $controller('export'));
                        });
                        $router->fleetbaseRoutes('telematics', function ($router, $controller) {
                            $router->match(['get', 'post'], 'export', $controller('export'));
                            $router->get('providers', $controller('providers'));
                            $router->get('{id}/logs', $controller('logs'));
                            $router->get('{id}/devices', $controller('devices'));
                            $router->post('{id}/link-device', $controller('linkDevice'));
                            $router->post('{id}/discover', $controller('discover'));
                            $router->post('{id}/test-connection', $controller('testConnection'));
                            $router->post('{key}/test-credentials', $controller('testCredentials'));
                        });
                        $router->fleetbaseRoutes('maintenance-schedules', function ($router, $controller) {
                            $router->match(['get', 'post'], 'export', $controller('export'));
                            $router->post('import', $controller('import'));
                            $router->post('{id}/pause', $controller('pause'));
                            $router->post('{id}/resume', $controller('resume'));
                            $router->post('{id}/trigger', $controller('trigger'));
                            $router->get('calendar-feed', $controller('calendarFeed'));
                            $router->get('{id}/ical', $controller('ical'));
                        });
                        $router->fleetbaseRoutes('work-orders', function ($router, $controller) {
                            $router->match(['get', 'post'], 'export', $controller('export'));
                            $router->post('import', $controller('import'));
                            $router->post('{id}/send', $controller('sendEmail'));
                        });
                        $router->fleetbaseRoutes('maintenances', function ($router, $controller) {
                            $router->match(['get', 'post'], 'export', $controller('export'));
                            $router->post('import', $controller('import'));
                            $router->post('{id}/line-items', $controller('addLineItem'));
                            $router->put('{id}/line-items/{index}', $controller('updateLineItem'));
                            $router->delete('{id}/line-items/{index}', $controller('removeLineItem'));
                        });
                        $router->fleetbaseRoutes('equipment', function ($router, $controller) {
                            $router->match(['get', 'post'], 'export', $controller('export'));
                            $router->post('import', $controller('import'));
                        });
                        $router->fleetbaseRoutes('parts', function ($router, $controller) {
                            $router->match(['get', 'post'], 'export', $controller('export'));
                            $router->post('import', $controller('import'));
                        });
                        $router->fleetbaseRoutes('warranties');
                        $router->group(
                            ['prefix' => 'query'],
                            function () use ($router) {
                                $router->get('customers', 'MorphController@queryCustomersOrFacilitators');
                                $router->get('facilitators', 'MorphController@queryCustomersOrFacilitators');
                            }
                        );
                        $router->group(
                            ['prefix' => 'customers'],
                            function () use ($router) {
                                $router->get('/', 'MorphController@queryCustomers');
                                $router->post('create-portal-login', 'CustomerController@createPortalLogin');
                                $router->post('reset-credentials', 'CustomerController@resetCredentials');
                                $router->post('send-credentials', 'CustomerController@sendCredentials');
                                $router->post('deactivate-portal-login', 'CustomerController@deactivatePortalLogin');
                                $router->post('reactivate-portal-login', 'CustomerController@reactivatePortalLogin');
                            }
                        );
                        $router->group(
                            ['prefix' => 'facilitators'],
                            function () use ($router) {
                                $router->get('/', 'MorphController@queryFacilitators');
                            }
                        );
                        $router->group(
                            ['prefix' => 'geocoder', ['middleware' => []]],
                            function ($router) {
                                $router->get('reverse', 'GeocoderController@reverse');
                                $router->get('query', 'GeocoderController@geocode');
                            }
                        );
                        $router->group(
                            ['prefix' => 'fleet-ops'],
                            function ($router) {
                                $router->group(
                                    ['prefix' => 'payments', ['middleware' => []]],
                                    function () use ($router) {
                                        $router->post('stripe-account', 'PaymentController@getStripeAccount');
                                        $router->post('stripe-account-session', 'PaymentController@getStripeAccountSession');
                                        $router->get('has-stripe-connect-account', 'PaymentController@hasStripeConnectAccount');
                                        $router->get('payments-received', 'PaymentController@getCompanyReceivedPayments');
                                    }
                                );

                                $router->group(
                                    ['prefix' => 'lookup'],
                                    function ($router) {
                                        $router->get('customers', 'FleetOpsLookupController@polymorphs');
                                        $router->get('facilitators', 'FleetOpsLookupController@polymorphs');
                                    }
                                );
                                $router->group(
                                    ['prefix' => 'live'],
                                    function ($router) {
                                        $router->get('coordinates', 'LiveController@coordinates');
                                        $router->get('routes', 'LiveController@routes');
                                        $router->get('orders', 'LiveController@orders');
                                        $router->get('operations-monitor', 'LiveController@operationsMonitor');
                                        $router->get('drivers', 'LiveController@drivers');
                                        $router->get('vehicles', 'LiveController@vehicles');
                                        $router->get('places', 'LiveController@places');
                                    }
                                );
                                $router->group(
                                    ['prefix' => 'settings', 'middleware' => []],
                                    function ($router) {
                                        $router->get('customer-payments-config', 'SettingController@getCustomerPortalPaymentConfig');
                                        $router->post('customer-payments-config', 'SettingController@saveCustomerPortalPaymentConfig');
                                        $router->get('customer-enabled-order-configs', 'SettingController@getCustomerEnabledOrderConfigs');
                                        $router->post('customer-enabled-order-configs', 'SettingController@saveCustomerEnabledOrderConfigs');
                                        $router->get('entity-editing-settings', 'SettingController@getEntityEditingSettings');
                                        $router->post('entity-editing-settings', 'SettingController@saveEntityEditingSettings');
                                        $router->post('driver-onboard-settings', 'SettingController@savedDriverOnboardSettings');
                                        $router->get('driver-onboard-settings/{companyId}', 'SettingController@getDriverOnboardSettings');
                                        $router->get('notification-notifiables', 'SettingController@getNotifiables');
                                        $router->get('notification-registry', 'SettingController@getNotificationRegistry');
                                        $router->get('notification-settings', 'SettingController@getNotificationSettings');
                                        $router->post('notification-settings', 'SettingController@saveNotificationSettings');
                                        $router->get('routing-settings', 'SettingController@getRoutingSettings');
                                        $router->post('routing-settings', 'SettingController@saveRoutingSettings');
                                        $router->get('tracking-settings', 'SettingController@getTrackingSettings');
                                        $router->post('tracking-settings', 'SettingController@saveTrackingSettings');
                                        $router->get('admin-tracking-settings', 'SettingController@getAdminTrackingSettings');
                                        $router->post('admin-tracking-settings', 'SettingController@saveAdminTrackingSettings');
                                        $router->get('map', 'SettingController@getMapSettings');
                                        $router->post('map', 'SettingController@saveMapSettings');
                                        $router->get('admin-map', 'SettingController@getAdminMapSettings');
                                        $router->post('admin-map', 'SettingController@saveAdminMapSettings');
                                        $router->get('scheduling-settings', 'SettingController@getSchedulingSettings');
                                        $router->post('scheduling-settings', 'SettingController@saveSchedulingSettings');
                                        $router->get('orchestrator-settings', 'SettingController@getOrchestratorSettings');
                                        $router->post('orchestrator-settings', 'SettingController@saveOrchestratorSettings');
                                        $router->get('orchestrator-card-fields', 'SettingController@getOrchestratorCardFields');
                                        $router->post('orchestrator-card-fields', 'SettingController@saveOrchestratorCardFields');
                                    }
                                );
                                $router->group(
                                    ['prefix' => 'metrics'],
                                    function ($router) {
                                        $router->get('/', 'MetricsController@all');
                                        $router->get('{slug}', 'MetricsController@show');
                                    }
                                );
                                $router->group(
                                    ['prefix' => 'analytics'],
                                    function ($router) {
                                        $router->get('operations-pulse', 'AnalyticsController@operationsPulse');
                                        $router->get('revenue-trend', 'AnalyticsController@revenueTrend');
                                        $router->get('orders-by-status', 'AnalyticsController@ordersByStatus');
                                        $router->get('on-time-delivery', 'AnalyticsController@onTimeDelivery');
                                        $router->get('top-drivers', 'AnalyticsController@topDrivers');
                                        $router->get('fuel-efficiency', 'AnalyticsController@fuelEfficiency');
                                        $router->get('fuel-providers', 'AnalyticsController@fuelProviders');
                                        $router->get('issues-insights', 'AnalyticsController@issuesInsights');
                                        $router->get('maintenance-overview', 'AnalyticsController@maintenanceOverview');
                                        $router->get('geofence-violations', 'AnalyticsController@geofenceViolations');
                                        $router->get('live-fleet', 'AnalyticsController@liveFleet');
                                    }
                                );
                                $router->group(
                                    ['prefix' => 'hubs'],
                                    function ($router) {
                                        $router->get('resources', 'HubController@resources');
                                        $router->get('maintenance', 'HubController@maintenance');
                                    }
                                );
                                $router->group(
                                    ['prefix' => 'getting-started'],
                                    function ($router) {
                                        $router->get('status', 'GettingStartedController@status');
                                    }
                                );
                                $router->group(
                                    ['prefix' => 'orchestrator'],
                                    function ($router) {
                                        $router->get('orders', 'OrchestrationController@orders');
                                        $router->post('run', 'OrchestrationController@run');
                                        $router->post('commit', 'OrchestrationController@commit');
                                        $router->get('preview', 'OrchestrationController@preview');
                                        $router->get('engines', 'OrchestrationController@engines');
                                        $router->post('import-orders', 'OrchestrationController@importOrders');
                                        $router->get('order-config-fields', 'OrchestrationController@orderConfigFields');
                                    }
                                );
                                $router->group(
                                    ['prefix' => 'manifests'],
                                    function ($router) {
                                        $router->get('/', 'ManifestController@index');
                                        $router->get('{id}', 'ManifestController@show');
                                        $router->post('{id}/cancel', 'ManifestController@cancel');
                                        $router->delete('{id}', 'ManifestController@destroy');
                                    }
                                );
                                $router->group(
                                    ['prefix' => 'manifest-stops'],
                                    function ($router) {
                                        $router->get('{id}', 'ManifestController@showStop');
                                        $router->patch('{id}', 'ManifestController@updateStop');
                                    }
                                );
                            }
                        );
                    }
                );
            }
        );
    }
);

if (filled(config('fleetops.api.routing.prefix'))) {
    Route::prefix(config('fleetops.api.routing.internal_prefix', 'int') . '/v1')
        ->namespace('Fleetbase\FleetOps\Http\Controllers\Internal\v1')
        ->middleware([
            'fleetbase.protected',
            Fleetbase\FleetOps\Http\Middleware\TransformLocationMiddleware::class,
            Fleetbase\FleetOps\Http\Middleware\SetupDriverSession::class,
        ])
        ->group(function ($router) {
            $router->get('issues/{id}/timeline', 'IssueController@timeline');
            $router->get('vendors/{id}/personnels', 'VendorController@vendorPersonnels');
            $router->post('vendors/{id}/personnels', 'VendorController@addVendorPersonnel');
            $router->delete('vendors/{id}/personnels/{contact}', 'VendorController@removeVendorPersonnel');
            $router->post('contacts/{id}/convert-to-vendor', 'ContactController@convertToVendor');
        });
}
