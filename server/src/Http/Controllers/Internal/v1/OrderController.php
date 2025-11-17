<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\FleetOps\Events\OrderDispatchFailed;
use Fleetbase\FleetOps\Events\OrderReady;
use Fleetbase\FleetOps\Events\OrderStarted;
use Fleetbase\FleetOps\Exports\OrderExport;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Http\Requests\BulkDispatchRequest;
use Fleetbase\FleetOps\Http\Requests\CancelOrderRequest;
use Fleetbase\FleetOps\Http\Requests\Internal\CreateOrderRequest;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Http\Resources\v1\Proof as ProofResource;
use Fleetbase\FleetOps\Imports\OrdersImport;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\Internal\BulkActionRequest;
use Fleetbase\Http\Requests\Internal\BulkDeleteRequest;
use Fleetbase\Models\File;
use Fleetbase\Models\Type;
use Fleetbase\Support\TemplateString;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class OrderController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'order';

    /**
     * Handle order waypoint changes if any.
     */
    public function onAfterUpdate($request, $order)
    {
        $waypoints = $request->array('order.payload.waypoints');
        if ($waypoints) {
            $order->loadMissing('payload');
            $order->payload->updateWaypoints($waypoints);
        }

        $customFieldValues = $request->array('order.custom_field_values');
        if ($customFieldValues) {
            $order->syncCustomFieldValues($customFieldValues);
        }
    }

    /**
     * Creates a record with request payload.
     *
     * @return \Illuminate\Http\Response
     */
    public function createRecord(Request $request)
    {
        // Create validation request
        $createOrderRequest  = CreateOrderRequest::createFrom($request);
        $rules               = $createOrderRequest->rules();

        // Manually validate request
        $validator = Validator::make($request->input('order'), $rules);
        if ($validator->fails()) {
            return $createOrderRequest->responseWithErrors($validator);
        }

        try {
            $record = $this->model->createRecordFromRequest(
                $request,
                function ($request, &$input) {
                    $serviceQuote = ServiceQuote::resolveFromRequest($request);

                    // if service quote is applied, resolve it
                    if ($serviceQuote instanceof ServiceQuote && $serviceQuote->fromIntegratedVendor()) {
                        // create order with integrated vendor, then resume fleetbase order creation
                        try {
                            $integratedVendorOrder = $serviceQuote->integratedVendor->api()->createOrderFromServiceQuote($serviceQuote, $request);
                        } catch (\Exception $e) {
                            return response()->error($e->getMessage());
                        }

                        $input['integrated_vendor_order'] = $integratedVendorOrder;
                    }

                    // if no type is set its default to default
                    if (!isset($input['type'])) {
                        $input['type'] = 'default';
                    }

                    // if no status is set its default to `created`
                    if (!isset($input['status'])) {
                        $input['status'] = 'created';
                    }

                    // Set order config
                    if (!isset($input['order_config_uuid'])) {
                        $defaultOrderConfig = OrderConfig::default();
                        if ($defaultOrderConfig) {
                            $input['order_config_uuid'] = $defaultOrderConfig->uuid;
                        }
                    }
                },
                function (&$request, Order &$order, &$requestInput) {
                    $input                   = $request->input('order');
                    $isIntegratedVendorOrder = isset($requestInput['integrated_vendor_order']);
                    $serviceQuote            = ServiceQuote::resolveFromRequest($request);

                    $route               = Utils::get($input, 'route');
                    $payload             = Utils::get($input, 'payload');
                    $waypoints           = Utils::get($input, 'payload.waypoints');
                    $entities            = Utils::get($input, 'payload.entities');
                    $uploads             = Utils::get($input, 'files', []);
                    $customFieldValues   = Utils::get($input, 'custom_field_values', []);

                    // save order route & payload with request input
                    $order
                        ->setRoute($route)
                        ->setStatus('created', false)
                        ->insertPayload($payload)
                        ->insertWaypoints($waypoints)
                        ->insertEntities($entities);

                    // If order creation includes files assosciate each to this order
                    if ($uploads) {
                        $ids   = collect($uploads)->pluck('uuid');
                        $files = File::whereIn('uuid', $ids)->get();

                        foreach ($files as $file) {
                            $file->setKey($order);
                        }
                    }

                    // save custom field values
                    if (is_array($customFieldValues)) {
                        $order->syncCustomFieldValues($customFieldValues);
                    }

                    // if it's integrated vendor order apply to meta
                    if ($isIntegratedVendorOrder) {
                        $order->updateMeta(
                            [
                                'integrated_vendor'       => Utils::get($requestInput['integrated_vendor_order'], 'metadata.integrated_vendor'),
                                'integrated_vendor_order' => $requestInput['integrated_vendor_order'],
                            ]
                        );
                    }

                    // Check dispatch flag with backward compatibility (default true)
                    $shouldDispatch = isset($input['dispatched']) ? (bool) $input['dispatched'] : true;

                    // dispatch if flagged true, otherwise ensure order stays in created state
                    if ($shouldDispatch) {
                        $order->firstDispatchWithActivity();
                    }

                    // set driving distance and time
                    $order->setPreliminaryDistanceAndTime();

                    // if service quote attached purchase
                    $order->purchaseServiceQuote($serviceQuote);

                    // Run background processes on queue
                    dispatch(function () use ($order): void {
                        // notify driver if assigned
                        $order->notifyDriverAssigned();

                        // Trigger order created event
                        event(new OrderReady($order));
                    })->afterCommit();
                }
            );

            // Reload payload and tracking number
            $record->load(['payload', 'trackingNumber']);

            return ['order' => new $this->resource($record)];
        } catch (QueryException $e) {
            return response()->error(app()->hasDebugModeEnabled() ? $e->getMessage() : 'Error occurred while trying to create a ' . $this->resourceSingularlName);
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }
    }

    /**
     *  Route which enables editing of an order route.
     *
     * @param string $id - The order ID
     *
     * @return Response
     */
    public function editOrderRoute(string $id, Request $request)
    {
        $pickup    = $request->input('pickup');
        $dropoff   = $request->input('dropoff');
        $return    = $request->input('return');
        $waypoints = $request->array('waypoints', []);

        // Get the order
        $order = Order::where('uuid', $id)->with(['payload'])->first();
        if (!$order) {
            return response()->error('Unable to find order to update route for.');
        }

        // Handle update of multiple waypoints
        if ($waypoints) {
            $order->payload->updateWaypoints($waypoints);
            $order->payload->removePlace(['pickup', 'dropoff', 'return'], ['save' => true]);
        } else {
            // Update pickup
            if ($pickup) {
                $order->payload->setPickup($pickup, ['save' => true]);
            }

            // Update dropoff
            if ($dropoff) {
                $order->payload->setDropoff($dropoff, ['save' => true]);
            }

            // Update return
            if ($return) {
                $order->payload->setDropoff($return, ['save' => true]);
            }

            // Remove waypoints if any
            $order->payload->removeWaypoints();
        }

        return ['order' => new $this->resource($order)];
    }

    /**
     * Process import files (excel,csv) into Fleetbase order data.
     *
     * @return \Illuminate\Http\Response
     */
    public function importFromFiles(Request $request)
    {
        $info    = Utils::lookupIp();
        $disk    = $request->input('disk', config('filesystems.default'));
        $files   = $request->input('files');
        $files   = File::whereIn('uuid', $files)->get();
        $country = $request->input('country', Utils::or($info, ['country_name', 'region'], 'Singapore'));

        $validFileTypes = ['csv', 'tsv', 'xls', 'xlsx'];
        $imports        = collect();

        foreach ($files as $file) {
            // validate file type
            if (!Str::endsWith($file->path, $validFileTypes)) {
                return response()->error('Invalid file uploaded, must be one of the following: ' . implode(', ', $validFileTypes));
            }

            try {
                $data = Excel::toArray(new OrdersImport(), $file->path, $disk);
            } catch (\Exception $e) {
                return response()->error('Invalid file, unable to proccess.');
            }

            $imports = $imports->concat($data);
        }

        $places   = collect();
        $entities = collect();

        foreach ($imports as $rows) {
            foreach ($rows as $row) {
                if (empty($row) || empty(array_values($row))) {
                    continue;
                }

                $importId = (string) Str::uuid();
                $place    = Place::createFromImportRow($row, $importId, $country);

                if (!$place) {
                    continue;
                }

                $places[] = $place;

                $items          = Utils::or($row, ['items', 'entities', 'packages', 'passengers', 'products', 'services']);
                $itemsDelimiter = Utils::findDelimiterFromString($items, '|');
                $items          = is_string($items) ? explode($itemsDelimiter, $items) : [];

                foreach ($items as $itemName) {
                    $entity = new Entity(['name' => $itemName]);
                    $entity->setAttribute('destination_uuid', $place->uuid);
                    $entity->setAttribute('_import_id', $importId);
                    $entity->setMeta($place->getMeta());
                    $entities[] = $entity;
                }
            }
        }

        return response()->json(
            [
                'entities' => $entities,
                'places'   => $places,
            ]
        );
    }

    /**
     * Updates a order to canceled and updates order activity.
     *
     * @return \Illuminate\Http\Response
     */
    public function bulkDelete(BulkDeleteRequest $request)
    {
        $ids = $request->input('ids', []);

        if (!$ids) {
            return response()->error('Nothing to delete.');
        }

        /** @var Order */
        $count   = Order::whereIn('uuid', $ids)->count();
        $deleted = Order::whereIn('uuid', $ids)->delete();

        if (!$deleted) {
            return response()->error('Failed to bulk delete orders.');
        }

        return response()->json(
            [
                'status'  => 'OK',
                'message' => 'Deleted ' . $count . ' orders',
                'count'   => $count,
            ]
        );
    }

    /**
     * Updates a order to canceled and updates order activity.
     *
     * @return \Illuminate\Http\Response
     */
    public function bulkCancel(BulkActionRequest $request)
    {
        /** @var \Illuminate\Database\Eloquent\Collection $orders */
        $orders = Order::whereIn('uuid', $request->input('ids'))->get();

        $count      = $orders->count();
        $failed     = [];
        $successful = [];

        foreach ($orders as $order) {
            if ($order->status === 'canceled') {
                $failed[] = $order->uuid;
                continue;
            }

            $trackingStatusExists = TrackingStatus::where(['tracking_number_uuid' => $order->tracking_number_uuid, 'code' => 'CANCELED'])->exists();
            if ($trackingStatusExists) {
                $failed[] = $order->uuid;
                continue;
            }

            $order->cancel();
            $successful[] = $order->uuid;
        }

        return response()->json(
            [
                'status'     => 'OK',
                'message'    => 'Canceled ' . $count . ' orders',
                'count'      => $count,
                'failed'     => $failed,
                'successful' => $successful,
            ]
        );
    }

    /**
     * Dispatches orders in bulk.
     *
     * @return \Illuminate\Http\Response
     */
    public function bulkDispatch(BulkDispatchRequest $request)
    {
        /** @var Order */
        $orders = Order::whereIn('uuid', $request->input('ids'))->get();

        $count      = $orders->count();
        $failed     = [];
        $successful = [];

        foreach ($orders as $order) {
            if ($order->status !== 'created') {
                $failed[] = $order->uuid;
                continue;
            }

            $trackingStatusExists = TrackingStatus::where(['tracking_number_uuid' => $order->tracking_number_uuid, 'code' => 'CANCELED'])->exists();
            if ($trackingStatusExists) {
                $failed[] = $order->uuid;
                continue;
            }

            $order->dispatch();
            $successful[] = $order->uuid;
        }

        return response()->json(
            [
                'status'     => 'OK',
                'message'    => 'Dispatched ' . $count . ' orders',
                'count'      => $count,
                'failed'     => $failed,
                'successful' => $successful,
            ]
        );
    }

    /**
     * Assigns a driver to many orders and queues individual driver‑notification tasks.
     *
     * The queued closure keeps payloads lean (just two UUID strings) and
     * prevents the HTTP request from blocking on network‑bound notification work.
     *
     * @param BulkActionRequest $request Validated request with:
     *                                   - ids    : string[] list of order UUIDs
     *                                   - driver : string   driver UUID
     *
     * @return \Illuminate\Http\Response
     */
    public function bulkAssignDriver(BulkActionRequest $request)
    {
        // Validate Inputs
        $data = $request->validate([
            'ids'    => 'required|array',
            'ids.*'  => 'uuid',
            'driver' => 'required|uuid',
        ]);

        // Resolve Driver
        /** @var Driver|null $driver */
        $driver = Driver::whereUuid($data['driver'])->first();
        if (!$driver) {
            return response()->error('Invalid driver selected to assign orders to.');
        }

        // Prepare Order UUID Collection
        $orderUuids = collect($data['ids'])->unique()->values();

        // Bulk Update Inside A Transaction
        DB::transaction(function () use ($orderUuids, $driver): void {
            Order::whereIn('uuid', $orderUuids)->update([
                'driver_assigned_uuid' => $driver->uuid,
                'updated_at'           => now(),
            ]);
        });

        // Queue Per‑Order Notifications
        if (!$request->boolean('silent')) {
            dispatch(function () use ($orderUuids, $driver): void {
                // Re‑hydrate Driver To Avoid Serializing The Full Model
                $driver = Driver::whereUuid($driver->uuid)->first();

                // Stream Orders To Keep Memory Footprint Low
                Order::whereIn('uuid', $orderUuids)
                    ->cursor()
                    ->each(function (Order $order) use ($driver): void {
                        // Synchronize In‑Memory Model
                        $order->setRelation('driverAssigned', $driver);
                        $order->driver_assigned_uuid = $driver->uuid;

                        try {
                            $order->notifyDriverAssigned();
                        } catch (\Throwable $e) {
                            logger()->warning(
                                'Failed notifying driver on order ' . $order->uuid,
                                ['error' => $e->getMessage()]
                            );
                        }
                    });
            })
            ->afterCommit();
        }

        return response()->json([
            'status'  => 'OK',
            'message' => sprintf(
                'Queued assignment of driver (%s) to %d orders',
                $driver->name,
                $orderUuids->count()
            ),
            'count'   => $orderUuids->count(),
        ]);
    }

    /**
     * Updates a order to canceled and updates order activity.
     *
     * @param \Fleetbase\Http\Requests\CancelOrderRequest $request
     *
     * @return \Illuminate\Http\Response
     */
    public function cancel(CancelOrderRequest $request)
    {
        /** @var Order */
        $order = Order::where('uuid', $request->input('order'))->first();

        $order->cancel();

        return response()->json(
            [
                'status'  => 'OK',
                'message' => 'Order was canceled',
                'order'   => $order->uuid,
            ]
        );
    }

    /**
     * Dispatches an order.
     *
     * @return \Illuminate\Http\Response
     */
    public function dispatchOrder(Request $request)
    {
        /**
         * @var Order
         */
        $order = Order::findById($request->input('order'), ['orderConfig', 'driverAssigned']);
        if (!$order) {
            return response()->error('No order found to dispatch.');
        }

        // if order has no config set, set default config
        $order->loadMissing('orderConfig');
        if (!$order->orderConfig) {
            $defaultOrderConfig = OrderConfig::default();
            if ($defaultOrderConfig) {
                $order->update(['order_config_uuid' => $defaultOrderConfig->uuid]);
                $order->loadMissing('orderConfig');
            }
        }

        if (!$order->hasDriverAssigned && !$order->adhoc) {
            return response()->error('No driver assigned to dispatch!');
        }

        if ($order->dispatched) {
            return response()->error('Order has already been dispatched!');
        }

        $order->dispatchWithActivity();

        return response()->json(
            [
                'status'  => 'OK',
                'message' => 'Order was dispatched',
                'order'   => $order->uuid,
            ]
        );
    }

    /**
     * Internal request for driver to start order.
     *
     * @return \Illuminate\Http\Response
     */
    public function start(Request $request)
    {
        /**
         * @var Order
         */
        $order = Order::where('uuid', $request->input('order'))->withoutGlobalScopes()->first();

        if (!$order) {
            return response()->error('Unable to find order to start.');
        }

        if ($order->started) {
            return response()->error('Order has already been started.');
        }

        /**
         * @var Driver
         */
        $driver = Driver::where('uuid', $order->driver_assigned_uuid)->withoutGlobalScopes()->first();

        /**
         * @var Payload
         */
        $payload = Payload::where('uuid', $order->payload_uuid)->withoutGlobalScopes()->with(['waypoints', 'waypointMarkers', 'entities'])->first();

        if (!$driver) {
            return response()->error('No driver assigned to order.');
        }

        // set order to started
        $order->started    = true;
        $order->started_at = now();
        $order->save();

        // trigger start event
        event(new OrderStarted($order));

        // set order as drivers current order
        $driver->current_job_uuid = $order->uuid;
        $driver->save();

        // get the next order activity
        $flow = $activity = $order->config()->nextFirstActivity();

        /**
         * @var \Fleetbase\LaravelMysqlSpatial\Types\Point
         */
        $location = $order->getLastLocation();

        // if multi drop order set first destination
        if ($payload->isMultipleDropOrder) {
            $firstDestination = $payload->waypoints->first();

            if ($firstDestination) {
                $payload->current_waypoint_uuid = $firstDestination->uuid;
                $payload->save();
            }

            // update activity for each waypoint and entity
            foreach ($payload->waypointMarkers as $waypointMarker) {
                $waypointMarker->insertActivity($activity, $location);
            }

            foreach ($payload->entities as $entity) {
                $entity->insertActivity($activity, $location);
            }
        }

        // update order activity
        $updateActivityRequest = new Request(['activity' => $flow]);

        // update activity
        return $this->updateActivity($order->uuid, $updateActivityRequest);
    }

    /**
     * Update an order activity.
     *
     * @return \Illuminate\Http\Response
     */
    public function updateActivity(string $id, Request $request)
    {
        $order = Order::findById($id, ['driverAssigned', 'payload.entities']);
        if (!$order) {
            return response()->error('No order found.');
        }

        $activity = $request->array('activity');
        $activity = new Activity($activity, $order->getConfigFlow());

        // Handle pickup/dropoff order activity update as normal
        if (Utils::isActivity($activity) && $activity->is('dispatched')) {
            // make sure driver is assigned if not trigger failed dispatch
            if (!$order->hasDriverAssigned && !$order->adhoc) {
                event(new OrderDispatchFailed($order, 'No driver assigned for order to dispatch to.'));

                return response()->error('No driver assigned for order to dispatch to.');
            }

            $order->dispatchWithActivity();

            return response()->json(['status' => 'dispatched']);
        }

        /**
         * @var \Fleetbase\LaravelMysqlSpatial\Types\Point
         */
        $location = $order->getLastLocation();
        $order->setStatus($activity->code);
        $order->insertActivity($activity, $location);

        // also update for each order entities if not multiple drop order
        // all entities will share the same activity status as is one drop order
        if (!$order->payload->isMultipleDropOrder) {
            foreach ($order->payload->entities as $entity) {
                $entity->insertActivity($activity, $location);
            }
        }

        // Handle order completed
        if (Utils::isActivity($activity) && $activity->completesOrder() && $order->driverAssigned) {
            // unset from driver current job
            $order->driverAssigned->unassignCurrentOrder();
            $order->complete();
        }

        // Fire activity events
        $activity->fireEvents($order);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Finds and responds with the orders next activity update based on the orders configuration.
     *
     * @return \Illuminate\Http\Response
     */
    public function nextActivity(string $id, Request $request)
    {
        try {
            $order = Order::findByIdOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->error('No order found.');
        }

        $waypoint   = $request->filled('waypoint') ? Waypoint::findByPlace($request->input('waypoint'), $order) : null;
        $activities = $order->config()->nextActivity($waypoint);

        // If activity is to complete order add proof of delivery properties if required
        // This is a temporary fix until activity is updated to handle POD on it's own
        $activities = $activities->map(function ($activity) use ($order) {
            if ($activity->completesOrder() && $order->pod_required) {
                $activity->set('require_pod', true);
                $activity->set('pod_method', $order->pod_method);
            }

            // resolved status and details
            $activity->set('_resolved_status', TemplateString::resolve($activity->get('status', ''), $order));
            $activity->set('_resolved_details', TemplateString::resolve($activity->get('details', ''), $order));

            return $activity;
        });

        return response()->json($activities);
    }

    /**
     * Finds and responds with the orders next activity update based on the orders configuration.
     *
     * @return \Illuminate\Http\Response
     */
    public function trackerInfo(string $id)
    {
        $order = Order::findById($id);
        if (!$order) {
            return response()->error('No order found.');
        }

        $trackerInfo = $order->tracker()->toArray();

        return response()->json($trackerInfo);
    }

    public function waypointEtas(string $id)
    {
        $order = Order::findById($id);
        if (!$order) {
            return response()->error('No order found.');
        }

        // Get order tracker
        $eta = $order->tracker()->eta();

        return response()->json($eta);
    }

    /**
     * Return distinct order statuses (and optionally activity codes) for a company,
     * filtered by order_config_uuid or order_config_key if provided.
     */
    public function statuses(Request $request)
    {
        $companyUuid       = $request->user()->company_uuid ?? session('company');
        $includeActivities = $request->boolean('include_order_config_activities', true);

        // Use input() + trim to get plain strings (Request::string() returns Stringable in newer Laravel)
        $orderConfigKey = trim((string) $request->input('order_config_key', ''));
        $orderConfigId  = trim((string) $request->input('order_config_uuid', ''));

        // ---------------------------
        // Build base orders query
        // ---------------------------
        $ordersQuery = DB::table('orders')
            ->where('company_uuid', $companyUuid)
            ->whereNotNull('status')
            ->whereNull('deleted_at');

        // Prefer filtering by UUID (most precise), else by key
        if ($orderConfigId !== '') {
            $ordersQuery->where('order_config_uuid', $orderConfigId);
        } elseif ($orderConfigKey !== '') {
            $ordersQuery->whereExists(function ($q) use ($companyUuid, $orderConfigKey) {
                $q->select(DB::raw(1))
                  ->from('order_configs as oc')
                  ->whereColumn('oc.uuid', 'orders.order_config_uuid')
                  ->where('oc.company_uuid', $companyUuid)
                  ->where('oc.key', $orderConfigKey);
            });
        }

        // Distinct order statuses
        $orderStatuses = $ordersQuery->distinct()->pluck('status')->filter();

        // ---------------------------------------
        // Optionally include activity codes
        // (must use the SAME target config set)
        // ---------------------------------------
        $activityCodes = collect();

        if ($includeActivities) {
            // Determine target config UUIDs once, honoring UUID > key > all-on-company
            if ($orderConfigId !== '') {
                $targetConfigUuids = collect([$orderConfigId]);
            } elseif ($orderConfigKey !== '') {
                $targetConfigUuids = DB::table('order_configs')
                    ->where('company_uuid', $companyUuid)
                    ->where('key', $orderConfigKey)
                    ->pluck('uuid');
            } else {
                // No filter given; derive from orders in this company
                $targetConfigUuids = DB::table('orders')
                    ->where('company_uuid', $companyUuid)
                    ->whereNotNull('order_config_uuid')
                    ->distinct()
                    ->pluck('order_config_uuid');
            }

            if ($targetConfigUuids->isNotEmpty()) {
                $orderConfigs = OrderConfig::where('company_uuid', $companyUuid)
                    ->whereIn('uuid', $targetConfigUuids)
                    ->get();

                foreach ($orderConfigs as $config) {
                    if (!method_exists($config, 'activities')) {
                        continue;
                    }

                    $activities = $config->activities();

                    // Handle Collection/array gracefully
                    $codes = collect($activities)
                        ->map(function ($activity) {
                            return data_get($activity, 'code');
                        })
                        ->filter()
                        ->values();

                    $activityCodes = $activityCodes->merge($codes);
                }
            }
        }

        // ---------------------------------------
        // Merge & return
        // ---------------------------------------
        $result = $orderStatuses
            ->merge($activityCodes)
            ->unique()
            ->values();

        return response()->json($result);
    }

    /**
     * Get all order type options.
     *
     * @return \Illuminate\Http\Response
     */
    public function types()
    {
        $defaultTypes = collect(config('api.types.order', []))->map(
            function ($attributes) {
                return new Type($attributes);
            }
        );
        $customTypes = Type::where('for', 'order')->get();

        $results = collect([...$customTypes, ...$defaultTypes])
            ->unique('key')
            ->values();

        return response()->json($results);
    }

    /**
     * Sends back the PDF stream for an order label file.
     *
     * @return void
     */
    public function label(string $publicId, Request $request)
    {
        $format  = $request->input('format', 'stream');
        $type    = $request->input('type', strtok($publicId, '_'));
        $subject = null;

        switch ($type) {
            case 'order':
                $subject = Order::where('public_id', $publicId)->orWhere('uuid', $publicId)->withoutGlobalScopes()->first();
                break;

            case 'waypoint':
                $subject = Waypoint::where('public_id', $publicId)->orWhere('uuid', $publicId)->withoutGlobalScopes()->first();
                break;

            case 'entity':
                $subject = Entity::where('public_id', $publicId)->orWhere('uuid', $publicId)->withoutGlobalScopes()->first();
                break;
        }

        if (!$subject) {
            return response()->error('Unable to render label.');
        }

        switch ($format) {
            case 'pdf':
            case 'stream':
            default:
                $stream = $subject->pdfLabelStream();

                return $stream;

            case 'text':
                $text = $subject->pdfLabel()->output();

                return response()->make($text);

            case 'base64':
                $base64 = base64_encode($subject->pdfLabel()->output());

                return response()->json(['data' => mb_convert_encoding($base64, 'UTF-8', 'UTF-8')]);
        }

        return response()->error('Unable to render label.');
    }

    /**
     * Retrieve proof of delivery resources associated with a given order and optional subject.
     *
     * This method supports retrieving proofs related to the order itself or a subject within the order,
     * such as a waypoint, place, or entity. If a subject ID is provided, it will determine the subject type
     * based on its prefix and resolve the appropriate model. If no subject ID is provided, the order itself is used
     * as the subject.
     *
     * @param Request     $request   the incoming HTTP request instance
     * @param string      $id        the public ID of the order
     * @param string|null $subjectId Optional subject ID (e.g., waypoint, place, or entity).
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function proofs(Request $request, string $id, ?string $subjectId = null)
    {
        try {
            $order = Order::where('uuid', $id)->first();
        } catch (ModelNotFoundException $e) {
            return response()->error('Order resource not found.', 404);
        }

        $subject = $order;

        if ($subjectId) {
            $type = strtok($subjectId, '_');

            $subject = match ($type) {
                'place', 'waypoint' => Waypoint::where('payload_uuid', $order->payload_uuid)
                    ->where(function ($query) use ($subjectId) {
                        $query->whereHas('place', fn ($q) => $q->where('uuid', $subjectId))
                              ->orWhere('uuid', $subjectId);
                    })
                    ->withoutGlobalScopes()
                    ->first(),

                'entity' => Entity::where('uuid', $subjectId)->withoutGlobalScopes()->first(),

                default => $order,
            };
        }

        if (!$subject) {
            return response()->error('Unable to retrieve proof of delivery for subject.');
        }

        $proofsQuery = Proof::where([
            'company_uuid' => session('company'),
            'order_uuid'   => $order->uuid,
        ]);

        // if subject is not the order then filter by subject
        if ($order->uuid !== $subject->uuid) {
            $proofsQuery->where('subject_uuid', $subject->uuid);
        }

        // get proofs
        $proofs = $proofsQuery->get();

        return ProofResource::collection($proofs);
    }

    /**
     * Export the issue to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public function export(ExportRequest $request)
    {
        $format       = $request->input('format', 'xlsx');
        $selections   = $request->array('selections');
        $fileName     = trim(Str::slug('order-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new OrderExport($selections), $fileName);
    }

    public function getDefaultOrderConfig()
    {
        return response()->json(OrderConfig::default());
    }

    public function lookup(Request $request)
    {
        $trackingNumber = $request->input('tracking');
        if (!$trackingNumber) {
            return response()->error('No tracking number provided for lookup.');
        }

        $order = Order::whereHas(
            'trackingNumber',
            function ($query) use ($trackingNumber) {
                $query->where('tracking_number', $trackingNumber);
            }
        )->first();

        if (!$order) {
            return response()->error('No order found using tracking number provided.');
        }

        // load required relations
        $order->loadMissing(['trackingNumber', 'payload', 'trackingStatuses']);

        // load tracker data
        $order->tracker_data = $order->tracker()->toArray();
        $order->eta          = $order->tracker()->eta();

        return new OrderResource($order);
    }
}
