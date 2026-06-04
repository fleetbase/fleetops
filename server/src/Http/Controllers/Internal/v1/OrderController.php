<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\FleetOps\Events\EntityActivityChanged;
use Fleetbase\FleetOps\Events\EntityCompleted;
use Fleetbase\FleetOps\Events\OrderDispatchFailed;
use Fleetbase\FleetOps\Events\OrderReady;
use Fleetbase\FleetOps\Events\OrderStarted;
use Fleetbase\FleetOps\Events\WaypointActivityChanged;
use Fleetbase\FleetOps\Events\WaypointCompleted;
use Fleetbase\FleetOps\Exports\OrderExport;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Http\Requests\BulkDispatchRequest;
use Fleetbase\FleetOps\Http\Requests\CancelOrderRequest;
use Fleetbase\FleetOps\Http\Requests\Internal\CreateOrderRequest;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Order as OrderIndexResource;
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
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Support\ResolvesOrderServiceStops;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\Internal\BulkActionRequest;
use Fleetbase\Models\File;
use Fleetbase\Models\Type;
use Fleetbase\Support\Auth;
use Fleetbase\Support\TemplateString;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class OrderController extends FleetOpsController
{
    use ResolvesOrderServiceStops;

    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'order';

    /**
     * The lightweight resource for index/list views.
     *
     * @var string
     */
    public $indexResource = OrderIndexResource::class;

    /**
     * Handle order waypoint changes if any.
     */
    public function onAfterUpdate($request, $order)
    {
        $uploads = $request->array('order.files');
        if ($uploads) {
            $order->attachFiles($uploads);
        }

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

                    $this->normalizeCustomerType($input);

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

                    // Normalize order config + type. Invalid explicit configs must
                    // not silently fall back to the default transport config.
                    $hasExplicitOrderConfig = !empty($input['order_config_uuid']);
                    $hasExplicitOrderType   = !empty($input['type']);
                    $resolvedOrderConfig    = OrderConfig::resolveFromIdentifier([$input['order_config_uuid'] ?? null, $input['type'] ?? null]);

                    if (!$resolvedOrderConfig && $hasExplicitOrderConfig) {
                        throw new FleetbaseRequestValidationException(['order_config_uuid' => 'The selected order config is invalid.']);
                    }

                    if (!$resolvedOrderConfig && !$hasExplicitOrderType) {
                        $resolvedOrderConfig = OrderConfig::defaultOrCreate();
                    }

                    if ($resolvedOrderConfig) {
                        $input['order_config_uuid'] = $resolvedOrderConfig->uuid;
                        $input['type']              = $resolvedOrderConfig->key;
                    } elseif (!isset($input['type'])) {
                        $input['type'] = 'transport';
                    }

                    // if no status is set its default to `created`
                    if (!isset($input['status'])) {
                        $input['status'] = 'created';
                    }

                    // Ensure orchestrator_priority is never null — the column is NOT NULL
                    // and the DB default is bypassed when Eloquent receives an explicit null.
                    if (!isset($input['orchestrator_priority']) || !is_numeric($input['orchestrator_priority'])) {
                        $input['orchestrator_priority'] = 50;
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
                    $order->attachFiles($uploads);

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
     * Resolve the concrete polymorphic customer type from the submitted UUID.
     */
    protected function normalizeCustomerType(array &$input): void
    {
        $customerUuid = data_get($input, 'customer_uuid') ?? data_get($input, 'customer.uuid');
        if (!$customerUuid) {
            return;
        }

        $customer = Utils::getUuid(
            ['contacts', 'vendors'],
            [
                'uuid'         => $customerUuid,
                'company_uuid' => session('company'),
            ],
            [
                'with_table' => true,
            ]
        );

        if (is_array($customer)) {
            $input['customer_uuid'] = Utils::get($customer, 'uuid');
            $input['customer_type'] = Utils::getModelClassName(Utils::get($customer, 'table'));
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
        $pickup            = $request->input('pickup');
        $dropoff           = $request->input('dropoff');
        $return            = $request->input('return');
        $waypoints         = $request->array('waypoints', []);
        $hasPickupInput    = $request->exists('pickup');
        $hasDropoffInput   = $request->exists('dropoff');
        $hasReturnInput    = $request->exists('return');
        $hasWaypointsInput = $request->exists('waypoints');

        // Get the order
        $order = Order::where('uuid', $id)->with(['payload'])->first();
        if (!$order) {
            return response()->error('Unable to find order to update route for.');
        }

        if ($hasPickupInput) {
            if ($pickup) {
                $order->payload->setPickup($pickup, ['save' => true]);
            } else {
                $order->payload->removePlace('pickup', ['save' => true]);
            }
        }

        if ($hasDropoffInput) {
            if ($dropoff) {
                $order->payload->setDropoff($dropoff, ['save' => true]);
            } else {
                $order->payload->removePlace('dropoff', ['save' => true]);
            }
        }

        if ($hasReturnInput) {
            if ($return) {
                $order->payload->setReturn($return, ['save' => true]);
            } else {
                $order->payload->removePlace('return', ['save' => true]);
            }
        }

        if ($hasWaypointsInput) {
            if (!empty($waypoints)) {
                $order->payload->updateWaypoints($waypoints);
            } else {
                $order->payload->removeWaypoints();
            }
        } elseif ($hasPickupInput || $hasDropoffInput || $hasReturnInput) {
            $order->payload->removeWaypoints();
        }

        $startingDestination = $order->payload->getPickupOrFirstWaypoint();
        if (!$startingDestination) {
            $startingDestination = $order->payload->getDropoffOrLastWaypoint();
        }

        if ($startingDestination instanceof Place || $startingDestination instanceof Waypoint) {
            $order->payload->setCurrentWaypoint($startingDestination);
        }

        $order->load(['payload.pickup', 'payload.dropoff', 'payload.return', 'payload.waypoints']);

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

        // Ensure the order is normalized onto a real order config before
        // dispatching activities.
        if (!$order->ensureOrderConfig()) {
            return response()->error('No order config found for dispatch.');
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

        // if multi-stop route set first service stop destination
        if ($this->payloadUsesServiceStopActivity($payload)) {
            $this->ensurePayloadCurrentServiceStop($payload);
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
        $order = Order::findById($id, [
            'driverAssigned',
            'payload.entities',
            'payload.pickup',
            'payload.dropoff',
            'payload.return',
            'payload.currentWaypoint',
            'payload.waypoints',
            'payload.waypointMarkers.place',
            'payload.waypointMarkers.trackingNumber.status',
        ]);
        if (!$order) {
            return response()->error('No order found.');
        }

        $proof       = $request->input('proof');
        $bypassProof = $request->boolean('bypass_proof');
        $activity    = $request->array('activity');
        $activity    = new Activity($activity, $order->getConfigFlow());

        $requiresProof = Utils::isActivity($activity)
            && ($activity->get('require_pod') || ($activity->completesOrder() && $order->pod_required));
        if ($requiresProof && !$proof && !$bypassProof) {
            return response()->error('Proof of delivery is required for this activity or must be explicitly bypassed.', 422);
        }

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
        $location                = $order->getLastLocation();
        $usesServiceStopActivity = $this->payloadUsesServiceStopActivity($order->payload);
        $isLifecycleActivity     = Utils::isActivity($activity) && in_array($activity->code, ['created', 'dispatched', 'started'], true);

        if (!$usesServiceStopActivity || $isLifecycleActivity) {
            $order->updateActivity($activity, $proof);

            if (!$usesServiceStopActivity) {
                foreach ($order->payload->entities as $entity) {
                    $entity->insertActivity($activity, $location, $proof);
                }
            }

            if ($usesServiceStopActivity && $activity->is('started')) {
                $this->ensurePayloadCurrentServiceStop($order->payload);
            }

            // Handle order completed
            if (Utils::isActivity($activity) && $activity->completesOrder() && $order->driverAssigned) {
                // unset from driver current job
                $order->driverAssigned->unassignCurrentOrder();
                $order->complete($this->resolveProof($proof));
            }

            return new OrderResource($order->refresh());
        }

        if (!$order->started && in_array($order->status, ['created', 'dispatched'])) {
            return response()->error('Order must be started before waypoint activity can be updated.', 422);
        }

        $currentStop               = $this->ensurePayloadCurrentServiceStop($order->payload);
        $isCompletingCurrentStop   = Utils::isActivity($activity) && $activity->complete();

        $this->updateCurrentServiceStopActivity($order, $activity, $location, $proof);

        if (Utils::isActivity($activity) && $activity->is('canceled')) {
            if ($order->driverAssigned) {
                $order->driverAssigned->unassignCurrentOrder();
            }

            $order->cancel();

            return new OrderResource($order->refresh());
        }

        if (Utils::isActivity($activity) && $activity->completesOrder()) {
            $nextStop = $isCompletingCurrentStop ? $this->advanceCurrentServiceStopDestination($order, $order->payload) : null;

            if (!$nextStop || !$currentStop) {
                if ($order->driverAssigned) {
                    $order->driverAssigned->unassignCurrentOrder();
                }

                $order->complete($this->resolveProof($proof));
            }
        }

        return new OrderResource($order->refresh());
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

        $order->loadMissing([
            'payload.pickup',
            'payload.dropoff',
            'payload.waypoints',
            'payload.waypointMarkers.place',
            'payload.waypointMarkers.trackingNumber.status',
        ]);

        $canUpdateWaypointActivity = $this->payloadUsesServiceStopActivity($order->payload)
            && ($order->started || $order->started_at || !in_array($order->status, ['created', 'dispatched']));
        $stop                      = null;
        if ($canUpdateWaypointActivity) {
            $stop = $request->filled('waypoint')
                ? $this->resolveServiceStopFromKey($order->payload, $request->input('waypoint'))
                : $this->payloadCurrentServiceStop($order->payload);
        }
        $orderConfig = $order->ensureOrderConfig();
        if (!$orderConfig) {
            return response()->error('No order config found for order.');
        }
        $activities = $stop ? $this->nextActivitiesForServiceStop($order, $order->payload, $stop) : $orderConfig->nextActivity();

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
     * Mark a waypoint as the current destination for a multi-waypoint order.
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function setDestination(string $id, string $placeId)
    {
        $order = Order::findById($id, [
            'payload.pickup',
            'payload.dropoff',
            'payload.return',
            'payload.waypoints',
            'payload.waypointMarkers.place',
            'payload.waypointMarkers.trackingNumber.status',
            'driverAssigned',
            'vehicleAssigned',
            'customer',
            'facilitator',
        ]);
        if (!$order) {
            return response()->error('No order found.');
        }

        if (!$this->payloadUsesServiceStopActivity($order->payload)) {
            return response()->error('Destination can only be changed for multi-waypoint orders.', 422);
        }

        $stop = $this->resolveServiceStopFromKey($order->payload, $placeId);
        if (!$stop) {
            return response()->error('Place resource is not a valid route destination.', 422);
        }

        $this->setPayloadCurrentServiceStop($order->payload, $stop);

        return new OrderResource($order->refresh());
    }

    /**
     * Capture one or more photos for order/waypoint proof of delivery.
     *
     * @return ProofResource|\Illuminate\Http\Response
     */
    public function capturePhoto(Request $request, string $id, ?string $subjectId = null)
    {
        $incoming  = $this->collectProofPhotoInputs($request);
        $metadata  = $request->input('data', []);
        $metadata  = is_array($metadata) ? $metadata : [];
        $validator = Validator::make([
            'photos'  => $incoming,
            'remarks' => $request->input('remarks'),
            'data'    => $metadata,
        ], [
            'photos'   => 'required|array|min:1',
            'photos.*' => [
                function ($attribute, $value, $fail) {
                    if ($value instanceof UploadedFile) {
                        $extension = strtolower($value->getClientOriginalExtension() ?: $value->extension());
                        if (
                            !$value->isValid()
                            || !in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])
                            || $value->getSize() > 10 * 1024 * 1024
                        ) {
                            $fail("{$attribute} must be a valid image file <= 10 MB.");
                        }

                        return;
                    }

                    if ($this->isValidBase64ProofPhoto($value)) {
                        return;
                    }

                    $fail("{$attribute} must be an image file or a valid Base64 string.");
                },
            ],
            'remarks'  => 'sometimes|string|max:255',
            'data'     => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            $errorMessage = collect($validator->errors()->all())->first();

            return response()->error($errorMessage, 422);
        }

        $order = Order::findById($id, ['payload.pickup', 'payload.dropoff', 'payload.return', 'payload.waypoints', 'payload.waypointMarkers.place']);
        if (!$order) {
            return response()->error('No order found.');
        }

        $subject = $this->resolveProofSubject($order, $subjectId);
        if (!$subject) {
            return response()->error('Unable to capture photo as proof.', 422);
        }

        $disk    = $request->input('disk', config('filesystems.default'));
        $bucket  = $request->input("filesystems.disks.{$disk}.bucket", config('filesystems.disks.s3.bucket'));
        $remarks = $request->input('remarks', 'Verified by Photo');
        $data    = $metadata;

        if (empty($incoming)) {
            return response()->error('No photo data to capture.', 422);
        }

        foreach ($incoming as $item) {
            $proof = Proof::create([
                'company_uuid' => session('company'),
                'order_uuid'   => $order->uuid,
                'subject_uuid' => $subject->uuid,
                'subject_type' => Utils::getModelClassName($subject),
                'remarks'      => $remarks,
                'raw_data'     => $item instanceof UploadedFile ? null : $item,
                'data'         => $data,
            ]);

            $file = $this->storeProofPhoto($proof, $item, $disk, $bucket);
            $proof->update(['file_uuid' => $file->uuid]);
        }

        return new ProofResource($proof);
    }

    /**
     * Normalize console proof upload payloads into one photo list before validation.
     */
    protected function collectProofPhotoInputs(Request $request): array
    {
        $incoming = [];
        foreach (['photos', 'photo', 'files', 'file'] as $key) {
            $this->appendProofPhotoInputs($incoming, $request->file($key));
            $this->appendProofPhotoInputs($incoming, $request->input($key));
        }

        $incoming = array_values(array_filter($incoming, function ($value) {
            return $value instanceof UploadedFile || is_string($value);
        }));

        return $this->dedupeProofPhotoInputs($incoming);
    }

    /**
     * Append a nested file/input value to the proof input list.
     */
    protected function appendProofPhotoInputs(array &$incoming, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $nested) {
                $this->appendProofPhotoInputs($incoming, $nested);
            }

            return;
        }

        $incoming[] = $value;
    }

    /**
     * Remove duplicate photo inputs exposed under multiple multipart aliases.
     */
    protected function dedupeProofPhotoInputs(array $incoming): array
    {
        $seen = [];

        return array_values(array_filter($incoming, function ($value) use (&$seen) {
            $fingerprint = $this->proofPhotoInputFingerprint($value);
            if ($fingerprint === null || isset($seen[$fingerprint])) {
                return false;
            }

            $seen[$fingerprint] = true;

            return true;
        }));
    }

    /**
     * Build a stable fingerprint for an uploaded file or Base64 proof payload.
     */
    protected function proofPhotoInputFingerprint(mixed $value): ?string
    {
        if ($value instanceof UploadedFile) {
            return hash('sha256', implode('|', [
                $value->getRealPath(),
                $value->getSize(),
                $value->getClientOriginalName(),
                $value->getClientMimeType(),
            ]));
        }

        if (is_string($value)) {
            $payload = Str::contains($value, 'base64,') ? Str::after($value, 'base64,') : $value;

            return hash('sha256', $payload);
        }

        return null;
    }

    /**
     * Determine whether a proof input is a usable Base64 image payload.
     */
    protected function isValidBase64ProofPhoto(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $payload = Str::contains($value, 'base64,') ? Str::after($value, 'base64,') : $value;

        return base64_decode($payload, true) !== false;
    }

    /**
     * Store a proof photo and create its file record.
     */
    protected function storeProofPhoto(Proof $proof, UploadedFile|string $photo, string $disk, string $bucket): File
    {
        $isFile      = $photo instanceof UploadedFile;
        $contents    = $isFile ? file_get_contents($photo->getRealPath()) : base64_decode(Str::contains($photo, 'base64,') ? Str::after($photo, 'base64,') : $photo);
        $extension   = $isFile ? $photo->getClientOriginalExtension() : 'png';
        $contentType = $isFile ? $photo->getClientMimeType() : 'image/png';
        $company     = session('company');
        $path        = "uploads/{$company}/photos/{$proof->public_id}.{$extension}";

        Storage::disk($disk)->put($path, $contents);

        return File::create([
            'company_uuid'      => $company,
            'uploader_uuid'     => session('user'),
            'name'              => basename($path),
            'original_filename' => basename($path),
            'extension'         => $extension,
            'content_type'      => $contentType,
            'path'              => $path,
            'bucket'            => $bucket,
            'type'              => 'photo',
            'size'              => strlen($contents),
        ])->setKey($proof);
    }

    /**
     * Resolve the subject that proof belongs to.
     */
    protected function resolveProofSubject(Order $order, ?string $subjectId = null): Order|Waypoint|Place|null
    {
        if (!$subjectId) {
            return $order;
        }

        $stop = $this->resolveServiceStopFromKey($order->payload, $subjectId);
        if ($stop) {
            return ($stop['waypoint'] ?? null) instanceof Waypoint ? $stop['waypoint'] : ($stop['place'] ?? $order);
        }

        return Waypoint::withoutGlobalScopes()
            ->where('payload_uuid', $order->payload_uuid)
            ->where(function ($query) use ($subjectId) {
                $query
                    ->where('public_id', $subjectId)
                    ->orWhere('uuid', $subjectId)
                    ->orWhereHas('place', function ($query) use ($subjectId) {
                        $query->where('public_id', $subjectId)->orWhere('uuid', $subjectId);
                    });
            })
            ->first();
    }

    /**
     * Resolve proof to the model expected by order completion.
     */
    protected function resolveProof($proof): ?Proof
    {
        if ($proof instanceof Proof) {
            return $proof;
        }

        if (is_string($proof)) {
            return Proof::where('public_id', $proof)->orWhere('uuid', $proof)->first();
        }

        return null;
    }

    /**
     * Determine whether the payload should use waypoint-scoped activity.
     */
    protected function payloadHasWaypoints(?Payload $payload): bool
    {
        if (!$payload) {
            return false;
        }

        $payload->loadMissing('waypoints');

        return $payload->waypoints->isNotEmpty();
    }

    /**
     * Insert an activity on the payload's current waypoint and its destination entities.
     */
    protected function updateCurrentWaypointActivity(?Payload $payload, Activity $activity, $location = null, $proof = null): ?Waypoint
    {
        if (!$payload || !Utils::isActivity($activity) || !$location) {
            return null;
        }

        $payload->loadMissing(['order', 'waypointMarkers', 'entities']);
        $currentWaypoint = $payload->waypointMarkers->firstWhere('place_uuid', $payload->current_waypoint_uuid);
        if (!$currentWaypoint) {
            return null;
        }

        $currentWaypoint->insertActivity($activity, $location, $proof);
        $activity->fireEvents($payload->order, $currentWaypoint);

        $entities = $payload->entities->where('destination_uuid', $payload->current_waypoint_uuid);
        foreach ($entities as $entity) {
            $entity->insertActivity($activity, $location, $proof);
            if ($activity->complete()) {
                event(new EntityCompleted($entity, $activity));
            } else {
                event(new EntityActivityChanged($entity, $activity));
            }
        }

        if ($activity->complete()) {
            event(new WaypointCompleted($currentWaypoint, $activity));
        } else {
            event(new WaypointActivityChanged($currentWaypoint, $activity));
        }

        return $currentWaypoint;
    }

    /**
     * Reload waypoint marker status and decide whether every stop is complete.
     */
    protected function allWaypointMarkersComplete(?Payload $payload): bool
    {
        if (!$payload) {
            return false;
        }

        $waypointMarkers = $this->freshWaypointMarkers($payload);

        return $waypointMarkers->isNotEmpty() && $waypointMarkers->every(fn (Waypoint $waypoint) => $this->waypointMarkerIsComplete($waypoint));
    }

    /**
     * Move the current destination to the next incomplete waypoint.
     */
    protected function advanceCurrentWaypointDestination(?Payload $payload): ?Waypoint
    {
        if (!$payload) {
            return null;
        }

        $nextWaypoint = $this->freshWaypointMarkers($payload)
            ->sortBy('order')
            ->first(function (Waypoint $waypoint) use ($payload) {
                return !$this->waypointMarkerIsComplete($waypoint) && $waypoint->place_uuid !== $payload->current_waypoint_uuid;
            });

        if (!$nextWaypoint) {
            return null;
        }

        $payload->setCurrentWaypoint($nextWaypoint);
        $payload->setRelation('currentWaypoint', $nextWaypoint->getPlace());
        $payload->setRelation('currentWaypointMarker', $nextWaypoint);

        return $nextWaypoint;
    }

    /**
     * Reload waypoint markers with current tracking status.
     */
    protected function freshWaypointMarkers(Payload $payload)
    {
        $payload->unsetRelation('waypointMarkers');
        $payload->load(['waypointMarkers.trackingNumber.status']);

        return $payload->waypointMarkers;
    }

    /**
     * Determine whether a waypoint marker's latest status is complete.
     */
    protected function waypointMarkerIsComplete(Waypoint $waypoint): bool
    {
        if (!$waypoint->tracking_number_uuid) {
            return false;
        }

        return (bool) data_get($this->trackingNumberStatus($waypoint->tracking_number_uuid), 'complete', false);
    }

    /**
     * Determine whether the payload's current waypoint already has an activity row.
     */
    protected function payloadHasCurrentWaypointActivity(?Payload $payload, Activity $activity): bool
    {
        if (!$payload || !$payload->current_waypoint_uuid) {
            return false;
        }

        $payload->loadMissing('waypointMarkers');
        $currentWaypoint = $payload->waypointMarkers->firstWhere('place_uuid', $payload->current_waypoint_uuid);
        if (!$currentWaypoint || !$currentWaypoint->tracking_number_uuid) {
            return false;
        }

        return TrackingStatus::where('tracking_number_uuid', $currentWaypoint->tracking_number_uuid)
            ->where('code', TrackingStatus::prepareCode($activity->code))
            ->exists();
    }

    /**
     * Finds and responds with the orders next activity update based on the orders configuration.
     *
     * @return \Illuminate\Http\Response
     */
    public function trackerInfo(Request $request, string $id)
    {
        $order = Order::findById($id);
        if (!$order) {
            return response()->error('No order found.');
        }

        return response()->json($order->tracker()->toArray($request->only(['provider', 'fallbacks', 'traffic_enabled'])));
    }

    public function waypointEtas(Request $request, string $id)
    {
        $order = Order::findById($id);
        if (!$order) {
            return response()->error('No order found.');
        }

        return response()->json($order->tracker()->eta($request->only(['provider', 'fallbacks', 'traffic_enabled'])));
    }

    /**
     * Ping the assigned driver to refresh/order attention in the driver app.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function pingDriver(string $id)
    {
        if (!Auth::can('fleet-ops update order')) {
            return response()->error('Unauthorized.', 403);
        }

        try {
            $order = Order::findByIdOrFail($id, ['driverAssigned']);
        } catch (ModelNotFoundException $e) {
            return response()->error('Order resource not found.', 404);
        }

        if (!$order->driverAssigned) {
            return response()->error('Order does not have an assigned driver.', 422);
        }

        try {
            $order->driverAssigned->notify(new OrderPing($order));

            return response()->json([
                'status'  => 'ok',
                'message' => 'Driver app ping sent.',
            ]);
        } catch (\Throwable $e) {
            return response()->error('Unable to ping driver app.', 500);
        }
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

    /**
     * Schedule an order: set scheduled_at and optionally assign a driver.
     * This endpoint intentionally does NOT trigger dispatch or change the
     * order status, so it is safe to call from the scheduler UI.
     *
     * @return \Illuminate\Http\Response
     */
    public function scheduleOrder(Request $request)
    {
        $orderId     = $request->input('order');
        $scheduledAt = $request->input('scheduled_at');
        $driverId    = $request->input('driver_id');

        $order = Order::findById($orderId);
        if (!$order) {
            return response()->error('No order found to schedule.');
        }

        if ($scheduledAt) {
            $order->scheduled_at = \Carbon\Carbon::parse($scheduledAt);
        }

        if ($driverId) {
            // Resolve by uuid or public_id
            $driver = Driver::where('uuid', $driverId)
                ->orWhere('public_id', $driverId)
                ->first();
            if ($driver) {
                $order->driver_assigned_uuid = $driver->uuid;
            }
        }

        $order->saveQuietly();

        return response()->json([
            'status'       => 'OK',
            'message'      => 'Order scheduled',
            'order'        => $order->uuid,
            'scheduled_at' => $order->scheduled_at,
        ]);
    }
}
