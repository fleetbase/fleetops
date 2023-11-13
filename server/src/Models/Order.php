<?php

namespace Fleetbase\FleetOps\Models;

use Barryvdh\DomPDF\Facade\Pdf;
use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\FleetOps\Events\OrderCanceled;
use Fleetbase\FleetOps\Events\OrderCompleted;
use Fleetbase\FleetOps\Events\OrderDispatched;
use Fleetbase\FleetOps\Events\OrderDriverAssigned;
use Fleetbase\FleetOps\Support\Flow;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\FleetOps\Traits\HasTrackingNumber;
use Fleetbase\Models\Model;
use Fleetbase\Models\Transaction;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasInternalId;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasOptionsAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\SendsWebhooks;
use Fleetbase\Traits\TracksApiCredential;
use Grimzy\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Traits\LogsActivity;

class Order extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasInternalId;
    use SendsWebhooks;
    use HasApiModelBehavior;
    use HasOptionsAttributes;
    use HasMetaAttributes;
    use TracksApiCredential;
    use Searchable;
    use LogsActivity;
    use HasTrackingNumber;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'orders';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'order';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['public_id', 'internal_id', 'trackingNumber.tracking_number', 'meta->storefront', 'meta->storefront_network'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        '_key',
        'public_id',
        'internal_id',
        'route_uuid',
        'customer_uuid',
        'customer_type',
        'facilitator_uuid',
        'facilitator_type',
        'pickup_uuid',
        'dropoff_uuid',
        'return_uuid',
        'company_uuid',
        'session_uuid',
        'payload_uuid',
        'transaction_uuid',
        'purchase_rate_uuid',
        'tracking_number_uuid',
        'driver_assigned_uuid',
        'created_by_uuid',
        'updated_by_uuid',
        'scheduled_at',
        'dispatched_at',
        'dispatched',
        'adhoc',
        'adhoc_distance',
        'started',
        'started_at',
        'pod_method',
        'pod_required',
        'is_route_optimized',
        'distance',
        'time',
        'meta',
        'notes',
        'type',
        'status',
    ];

    /**
     * Attributes that is filterable on this model.
     *
     * @var array
     */
    protected $filterParams = [
        'service_quote_uuid',
        'unassigned',
        'pod_required',
        'started',
        'adhoc',
        'nearby',
        'storefront',
        'unassigned',
        'active',
        'tracking',
        'facilitator',
        'payload',
        'pickup',
        'dropoff',
        'return',
        'customer',
        'driver',
        'entity_status',
        'created_by',
        'updated_by',
        'layout',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [
        'driver_name',
        'tracking',
        'total_entities',
        'transaction_amount',
        'customer_name',
        'customer_phone',
        'facilitator_name',
        'customer_is_vendor',
        'customer_is_contact',
        'facilitator_is_vendor',
        'facilitator_is_contact',
        'has_driver_assigned',
        'pickup_name',
        'dropoff_name',
        'payload_id',
        'purchase_rate_id',
        'is_scheduled',
        'qr_code',
        'created_by_name',
        'updated_by_name',
    ];

    /**
     * Relationships to always append to model.
     *
     * @var array
     */
    protected $with = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta'             => Json::class,
        'options'          => Json::class,
        'customer_type'    => PolymorphicType::class,
        'facilitator_type' => PolymorphicType::class,
        'dispatched'       => 'boolean',
        'adhoc'            => 'boolean',
        'started'          => 'boolean',
        'pod_required'     => 'boolean',
        'scheduled_at'     => 'datetime',
        'dispatched_at'    => 'datetime',
        'started_at'       => 'datetime',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'id',
        '_key',
    ];

    /**
     * Properties which activity needs to be logged.
     *
     * @var array
     */
    protected static $logAttributes = '*';

    /**
     * Do not log empty changed.
     *
     * @var bool
     */
    protected static $submitEmptyLogs = false;

    /**
     * The name of the subject to log.
     *
     * @var string
     */
    protected static $logName = 'order';

    /**
     * @return \Barryvdh\DomPDF\PDF
     */
    public function pdfLabel()
    {
        return Pdf::loadHTML($this->label());
    }

    /**
     * @return \Illuminate\Http\Response
     */
    public function pdfLabelStream()
    {
        return $this->pdfLabel()->stream();
    }

    /**
     * @return \Illuminate\View\View
     */
    public function label()
    {
        $this->load(['trackingNumber', 'company']);

        return view('fleetops::labels/default', [
            'order'          => $this,
            'trackingNumber' => $this->trackingNumber,
            'company'        => $this->company,
        ])->render();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function transaction()
    {
        return $this->belongsTo(\Fleetbase\Models\Transaction::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function route()
    {
        return $this->belongsTo(Route::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function payload()
    {
        return $this->belongsTo(Payload::class)->with(['pickup', 'dropoff', 'return', 'waypoints', 'entities']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function orderConfig()
    {
        return $this->hasOne(\Fleetbase\Models\Extension::class, 'key', 'type')->where('type', 'order_config');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy()
    {
        return $this->belongsTo(\Fleetbase\Models\User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updatedBy()
    {
        return $this->belongsTo(\Fleetbase\Models\User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function driverAssigned()
    {
        return $this->belongsTo(Driver::class)->without(['devices', 'vendor']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function driver()
    {
        return $this->belongsTo(Driver::class)->without(['devices', 'vendor']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function drivers()
    {
        return $this->hasManyThrough(Driver::class, Entity::class, 'tracking_number_uuid', 'tracking_number_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function trackingNumber()
    {
        return $this->belongsTo(TrackingNumber::class)->without(['owner']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function trackingStatuses()
    {
        return $this->hasMany(TrackingStatus::class, 'tracking_number_uuid', 'tracking_number_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function proofs()
    {
        return $this->hasMany(Proof::class, 'subject_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function purchaseRate()
    {
        return $this->belongsTo(PurchaseRate::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function facilitator()
    {
        return $this->morphTo(__FUNCTION__, 'facilitator_type', 'facilitator_uuid')->withTrashed();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function customer()
    {
        return $this->morphTo(__FUNCTION__, 'customer_type', 'customer_uuid');
    }

    /**
     * Get the adhoc distance for this order, or fallback to settings or default value which is 6km.
     *
     * @return int
     */
    public function getAdhocDistance()
    {
        return $this->adhoc_distance ?? data_get($this, 'company.options.fleetops.adhoc_distance', 6000);
    }

    /**
     * The assigned drivers full name.
     *
     * @return string
     */
    public function getDriverNameAttribute()
    {
        return data_get($this, 'driverAssigned.name');
    }

    /**
     * The tracking number for the order.
     *
     * @return string
     */
    public function getTrackingAttribute()
    {
        return data_get($this, 'trackingNumber.tracking_number');
    }

    /**
     * The number of items for this order.
     *
     * @return string
     */
    public function getTotalEntitiesAttribute()
    {
        return (int) $this->fromCache('payload.total_entities');
    }

    /**
     * The transaction amount for the order.
     *
     * @return string
     */
    public function getTransactionAmountAttribute()
    {
        return data_get($this, 'transaction.amount');
    }

    /**
     * The customer name for the order.
     *
     * @return string
     */
    public function getCustomerNameAttribute()
    {
        return data_get($this, 'customer.name');
    }

    /**
     * The customer phone for the order.
     *
     * @return string
     */
    public function getCustomerPhoneAttribute()
    {
        return data_get($this, 'customer.phone');
    }

    /**
     * The facilitator name for the order.
     *
     * @return string
     */
    public function getFacilitatorNameAttribute()
    {
        return data_get($this, 'facilitator.name');
    }

    /**
     * True of the facilitator is a vendor `facilitator_is_vendor`.
     *
     * @return bool
     */
    public function getFacilitatorIsVendorAttribute()
    {
        return $this->facilitator_type === 'Fleetbase\\FleetOps\\Models\\Vendor';
    }

    /**
     * True of the facilitator is a integrated vendor `facilitator_is_integrated_vendor`.
     *
     * @return bool
     */
    public function getFacilitatorIsIntegratedVendorAttribute()
    {
        return $this->facilitator_type === 'Fleetbase\\FleetOps\\Models\\IntegratedVendor';
    }

    /**
     * True of the facilitator is a contact `facilitator_is_contact`.
     *
     * @return bool
     */
    public function getFacilitatorIsContactAttribute()
    {
        return $this->facilitator_type === 'Fleetbase\\FleetOps\\Models\\Contact';
    }

    /**
     * True of the customer is a vendor `customer_is_vendor`.
     *
     * @return bool
     */
    public function getCustomerIsVendorAttribute()
    {
        return $this->customer_type === 'Fleetbase\\FleetOps\\Models\\Vendor';
    }

    /**
     * True of the customer is a contact `customer_is_contact`.
     *
     * @return bool
     */
    public function getCustomerIsContactAttribute()
    {
        return $this->customer_type === 'Fleetbase\\FleetOps\\Models\\Contact';
    }

    /**
     * The pickup location name.
     */
    public function getPickupNameAttribute()
    {
        return $this->payload ? $this->payload->pickup_name : null;
    }

    /**
     * The dropoff location name.
     */
    public function getDropoffNameAttribute()
    {
        return $this->payload ? $this->payload->dropoff_name : null;
    }

    /**
     * The purchase rate public id.
     */
    public function getPurchaseRateIdAttribute()
    {
        return data_get($this, 'purchaseRate.public_id');
    }

    /**
     * The payload public id.
     */
    public function getPayloadIdAttribute()
    {
        return data_get($this, 'payload.public_id');
    }

    /**
     * The payload public id.
     *
     * @return string
     */
    public function getQrCodeAttribute()
    {
        return data_get($this, 'trackingNumber.qr_code');
    }

    /**
     * The name of the user who created the order.
     *
     * @return string
     */
    public function getCreatedByNameAttribute()
    {
        return data_get($this, 'createdBy.name');
    }

    /**
     * The name of the user who last updated.
     *
     * @return string
     */
    public function getUpdatedByNameAttribute()
    {
        return data_get($this, 'updatedBy.name');
    }

    /**
     * Set the order type attribute, which defaults to `default`.
     */
    public function setTypeAttribute(string $type = null): void
    {
        $this->attributes['type'] = is_string($type) ? Str::slug($type) : 'default';
    }

    /**
     * Set the order status attribute, which defaults to `created`.
     */
    public function setStatusAttribute(string $status = null): void
    {
        $this->attributes['status'] = is_string($status) ? Str::snake($status) : 'created';
    }

    public function getHasDriverAssignedAttribute()
    {
        return (bool) $this->driver_assigned_uuid;
    }

    public function getIsReadyForDispatchAttribute()
    {
        return $this->hasDrvierAssigned || $this->adhoc;
    }

    public function getIsScheduledAttribute(): bool
    {
        return !empty($this->scheduled_at) && Carbon::parse($this->scheduled_at)->isValid();
    }

    public function getIsAssignedNotDispatchedAttribute(): bool
    {
        return !empty($this->driver_assigned_uuid) && !$this->dispatched_at;
    }

    public function getIsNotDispatchedAttribute(): bool
    {
        return !$this->dispatched_at;
    }

    public function getIsIntegratedVendorOrderAttribute()
    {
        return $this->isIntegratedVendorOrder();
    }

    public function setPayload(?Payload $payload): Order
    {
        $this->payload_uuid = $payload->uuid;
        $this->setRelation('payload', $payload);
        $this->save();

        return $this;
    }

    public function createPayload(?array $attributes = [], bool $setPayload = true): Payload
    {
        // set payload type if not set
        if (!isset($attributes['type'])) {
            $attributes['type'] = $this->type;
        }

        if (isset($attributes['pickup']) && is_array($attributes['pickup'])) {
            $pickup = Place::createFromMixed($attributes['pickup']);

            if ($pickup instanceof Place) {
                $attributes['pickup_uuid'] = $pickup->uuid;
            }
        }

        if (isset($attributes['dropoff']) && is_array($attributes['dropoff'])) {
            $dropoff = Place::createFromMixed($attributes['dropoff']);

            if ($dropoff instanceof Place) {
                $attributes['dropoff_uuid'] = $dropoff->uuid;
            }
        }

        if (isset($attributes['return']) && is_array($attributes['return'])) {
            $return = Place::createFromMixed($attributes['return']);

            if ($return instanceof Place) {
                $attributes['return_uuid'] = $return->uuid;
            }
        }

        $payload = Payload::create($attributes);

        if ($setPayload) {
            $this->setPayload($payload);
        }

        return $payload;
    }

    public function insertPayload(?array $attributes = [], bool $setPayload = true): Payload
    {
        // set payload type if not set
        if (!isset($attributes['type'])) {
            $attributes['type'] = $this->type;
        }

        if (isset($attributes['pickup']) && is_array($attributes['pickup'])) {
            $pickupId = Place::insertFromMixed($attributes['pickup']);

            $attributes['pickup_uuid'] = $pickupId;
        }

        if (isset($attributes['dropoff']) && is_array($attributes['dropoff'])) {
            $dropoffId = Place::insertFromMixed($attributes['dropoff']);

            $attributes['dropoff_uuid'] = $dropoffId;
        }

        if (isset($attributes['return']) && is_array($attributes['return'])) {
            $returnId = Place::insertFromMixed($attributes['return']);

            $attributes['return_uuid'] = $returnId;
        }

        $fillable   = $this->getFillable();
        $insertKeys = array_keys($attributes);
        // clean insert data
        foreach ($insertKeys as $key) {
            if (!in_array($key, $fillable)) {
                unset($attributes[$key]);
            }
        }

        $attributes['uuid']         = $uuid = (string) Str::uuid();
        $attributes['public_id']    = static::generatePublicId('payload');
        $attributes['_key']         = session('api_key', 'console');
        $attributes['created_at']   = Carbon::now()->toDateTimeString();
        $attributes['company_uuid'] = session('company');

        $result = Payload::insert($attributes);

        if (!$result) {
            return $this->createPayload($attributes);
        }

        // get newly inserted payload
        $payload = Payload::find($uuid);

        // manyally trigger payload created event
        $payload->fireModelEvent('created', false);

        if ($setPayload) {
            $this->setPayload($payload);
        }

        return $payload;
    }

    public function getPayload()
    {
        if ($this->payload) {
            return $this->payload;
        }

        $this->load('payload');

        return $this->payload;
    }

    public function setRoute(?array $attributes = [])
    {
        if (!$attributes) {
            return $this;
        }

        if ($attributes instanceof Route) {
            $attributes->set('order_uuid', $this->order_uuid);
            $attributes->save();

            return $this;
        }

        if (isset($attributes['payload'])) {
            $attributes['details'] = $attributes['payload'];
            unset($attributes['payload']);
        }

        $attributes['order_uuid']   = $this->uuid;
        $attributes['company_uuid'] = $this->company_uuid ?? session('company');

        $route = new Route($attributes);
        $route->save();

        $this->update(['route_uuid' => $route->uuid]);

        return $this;
    }

    public function getCurrentDestinationLocation()
    {
        if ($this->payload && $this->payload->dropoff) {
            return $this->payload->dropoff->location;
        }

        if ($this->payload && $this->payload->waypoints->count() && $this->payload->current_waypoint_uuid) {
            return $this->payload->waypoints->firstWhere('uuid', $this->payload->current_waypoint_uuid)->location;
        }

        if ($this->payload && $this->payload->waypoints->count()) {
            return $this->payload->waypoints->first()->location;
        }

        return new Point(0, 0);
    }

    public function getLastLocation()
    {
        if ($this->driverAssigned && $this->driverAssigned->location) {
            return $this->driverAssigned->location;
        }

        if ($this->payload && $this->payload->pickup && $this->payload->pickup->location) {
            return $this->payload->pickup->location;
        }

        if ($this->payload && $this->payload->waypoints->count() && $this->payload->current_waypoint_uuid) {
            return $this->payload->waypoints->firstWhere('uuid', $this->payload->current_waypoint_uuid)->location;
        }

        if ($this->payload && $this->payload->waypoints->count()) {
            return $this->payload->waypoints->first()->location;
        }

        return new Point(0, 0);
    }

    public function purchaseQuote(string $serviceQuoteId, $meta = [])
    {
        // $serviceQuote = ServiceQuote::where('uuid', $serviceQuoteId)->first();
        // create purchase rate for order
        $purchasedRate = PurchaseRate::create([
            'customer_uuid'      => $this->customer_uuid,
            'customer_type'      => $this->customer_type,
            'company_uuid'       => session('company'),
            'service_quote_uuid' => $serviceQuoteId,
            'payload_uuid'       => $this->payload_uuid,
            'status'             => 'created',
            'meta'               => $meta,
        ]);

        $this->purchase_rate_uuid = $purchasedRate->uuid;

        return $this->save();
    }

    public function purchaseServiceQuote($serviceQuote, $meta = [])
    {
        if (!$serviceQuote) {
            // create transaction for order
            $this->createOrderTransactionWithoutServiceQuote();

            return $this;
        }

        if (Str::isUuid($serviceQuote)) {
            $serviceQuote = ServiceQuote::where('uuid', $serviceQuote)->first();
        }

        if (Utils::isPublicId($serviceQuote)) {
            $serviceQuote = ServiceQuote::where('public_id', $serviceQuote)->first();
        }

        if ($serviceQuote instanceof ServiceQuote) {
            $purchasedRate = PurchaseRate::create([
                'customer_uuid'      => $this->customer_uuid,
                'customer_type'      => $this->customer_type,
                'company_uuid'       => $this->company_uuid ?? session('company'),
                'service_quote_uuid' => $serviceQuote->uuid,
                'payload_uuid'       => $this->payload_uuid,
                'status'             => 'created',
                'meta'               => $meta,
            ]);

            return $this->update([
                'purchase_rate_uuid' => $purchasedRate->uuid,
            ]);
        }

        return false;
    }

    public function createOrderTransactionWithoutServiceQuote(): ?Transaction
    {
        $transaction = null;

        try {
            // create transaction and transaction items
            $transaction = Transaction::create([
                'company_uuid'           => session('company', $this->company_uuid),
                'customer_uuid'          => $this->customer_uuid,
                'customer_type'          => $this->customer_type,
                'gateway_transaction_id' => Transaction::generateNumber(),
                'gateway'                => 'internal',
                'amount'                 => 0,
                'currency'               => data_get($this->company, 'country') ? Utils::getCurrenyFromCountryCode(data_get($this->company, 'country')) : 'SGD',
                'description'            => 'Dispatch order',
                'type'                   => 'dispatch',
                'status'                 => 'success',
            ]);

            // set transaction to order
            $this->update(['transaction_uuid' => $transaction->uuid]);
        } catch (\Throwable $e) {
            // log error unable to create order transaction
        }

        return $transaction;
    }

    public function shouldDispatch($precision = 1)
    {
        $min = Carbon::now()->subMinutes($precision);
        $max = Carbon::now()->addMinutes($precision);

        return !$this->dispatched && Carbon::fromString($this->scheduled_at)->between($min, $max);
    }

    public function dispatch($save = false)
    {
        $this->dispatched    = true;
        $this->dispatched_at = now();

        if ($save === true) {
            $this->save();
            $this->flushAttributesCache();
        }

        return event(new OrderDispatched($this));
    }

    public function firstDispatch()
    {
        if ($this->dispatched) {
            $this->dispatch();
        }
    }

    public function cancel()
    {
        $this->status = 'canceled';

        if ($this->isIntegratedVendorOrder()) {
            $api = $this->facilitator->api();

            if (method_exists($api, 'cancelFromFleetbaseOrder')) {
                $api->cancelFromFleetbaseOrder($this);
            }
        }

        return event(new OrderCanceled($this));
    }

    public function notifyDriverAssigned()
    {
        if ($this->driver_assigned_uuid) {
            return event(new OrderDriverAssigned($this));
        }
    }

    public function notifyCompleted()
    {
        return event(new OrderCompleted($this));
    }

    public function setCustomer($model)
    {
        $this->customer_uuid = $model->uuid;
        $this->customer_type = Utils::getModelClassName($model);
    }

    public function setCustomerTypeAttribute($type)
    {
        if (is_string($type)) {
            if ($type === 'customer' || $type === 'vendor' || !Str::startsWith($type, 'fleet-ops')) {
                $type = 'fleet-ops:' . $type;
            }

            $this->attributes['customer_type'] = Utils::getMutationType($type);
        }
    }

    public function setFacilitatorTypeAttribute($type)
    {
        if (is_string($type)) {
            if ($type === 'customer' || $type === 'vendor' || !Str::startsWith($type, 'fleet-ops')) {
                $type = 'fleet-ops:' . $type;
            }

            $this->attributes['facilitator_type'] = Utils::getMutationType($type);
        }
    }

    public function setDriverLocationAsPickup($force = false)
    {
        if ($force === true) {
            $this->load('driverAssigned');

            if ($this->driverAssigned instanceof Driver) {
                $this->payload->setPickup($this->driverAssigned->location, true);
            }
        }

        // if payload is using a special key `pickup_is_driver_location`
        // and driver is assigned set the pickup point as the drivers current location
        if ($this->isDirty('driver_assigned_uuid') && !empty($this->driver_assigned_uuid) && $this->payload && $this->payload->hasMeta('pickup_is_driver_location')) {
            $this->load('driverAssigned');

            if ($this->driverAssigned instanceof Driver) {
                $this->payload->setPickup($this->driverAssigned->location, true);
            }
        }
    }

    public function isPickupIsFromDriverLocation()
    {
        return $this->payload instanceof Payload && $this->payload->hasMeta('pickup_is_driver_location');
    }

    public function updateStatus($code = null)
    {
        // update multiple status codes
        if (is_array($code)) {
            return collect($code)->every(function ($activityCode) {
                return $this->updateStatus($activityCode);
            });
        }

        $flow     = Flow::getOrderFlow($this);
        $activity = null;

        if (count($flow) === 1 && $code === null) {
            $activity = $flow[0];
        }

        if ($code) {
            $activity = collect($flow)->firstWhere('code', $code);
        }

        if (!$activity) {
            return false;
        }

        $isDispatchActivity = is_array($activity) && $activity['code'] === 'dispatched';
        $isReadyForDispatch = $this->isReadyForDispatch;

        if ($isDispatchActivity && $isReadyForDispatch) {
            $this->dispatch(true);
        }

        // edge case if not dispatched but code is dispatched/dispatch
        if (!$this->dispatched && Str::startsWith($code, 'dispatch')) {
            $this->dispatch(true);
        }

        $location = $this->getLastLocation();

        $this->setStatus($activity['code']);
        $this->insertActivity($activity['status'], $activity['details'], $location, $activity['code']);

        return true;
    }

    public function isDriver($driver)
    {
        if ($driver instanceof Driver) {
            return $driver->uuid === $this->driver_assigned_uuid;
        }

        if (is_string($driver)) {
            return $driver === $this->driver_assigned_uuid || ($this->driverAssigned && $driver === $this->driverAssigned->public_id);
        }

        return $driver === $this->driverAssigned;
    }

    public function assignDriver($driver, $silent = false)
    {
        if ($driver instanceof Driver) {
            $this->driver_assigned_uuid = $driver->uuid;
        }

        if (is_string($driver)) {
            if (Str::startsWith($driver, 'driver_')) {
                $driver = Driver::select(['uuid', 'public_id'])->where('public_id', $driver)->whereNull('deleted_at')->withoutGlobalScopes()->first();
                if ($driver) {
                    return $this->assignDriver($driver);
                }

                throw new \Exception('Invalid driver provided for assignment!');
            }

            $this->driver_assigned_uuid = $driver;
        }

        if ($driver instanceof Driver) {
            $this->setRelation('driverAssigned', $driver);
        }

        if (!$silent) {
            $this->notifyDriverAssigned();
        }

        $this->save();

        return $this;
    }

    public function getCurrentOriginPosition()
    {
        if ($this->hasDriverAssigned) {
            return $this->driverAssigned->location;
        }

        $origin = null;

        if ($this->payload) {
            $origin = $this->payload->getPickupOrCurrentWaypoint();
        }

        return $origin ? $origin->location : null;
    }

    public function getDestinationPosition()
    {
        $destination = null;

        if ($this->payload) {
            $destination = $this->payload->getDropoffOrLastWaypoint();
        }

        return $destination ? $destination->location : null;
    }

    public function setPreliminaryDistanceAndTime()
    {
        $origin      = $this->getCurrentOriginPosition();
        $destination = $this->getDestinationPosition();

        if ($origin === null || $destination === null) {
            return $this;
        }

        $matrix = Utils::getPreliminaryDistanceMatrix($origin, $destination);

        $this->update(['distance' => $matrix->distance, 'time' => $matrix->time]);

        return $this;
    }

    public function setDistanceAndTime(): Order
    {
        $origin      = $this->getCurrentOriginPosition();
        $destination = $this->getDestinationPosition();

        $matrix = Utils::getDrivingDistanceAndTime($origin, $destination);

        if ($origin === null || $destination === null) {
            return $this;
        }

        $this->update(['distance' => $matrix->distance, 'time' => $matrix->time]);

        return $this;
    }

    public function isIntegratedVendorOrder()
    {
        return $this->facilitator_is_integrated_vendor === true;
    }

    public function getAdhocPingDistance(): int
    {
        return (int) Utils::get($this, 'adhoc_distance', Utils::get($this, 'company.options.fleetops.adhoc_distance', 6000));
    }
}
