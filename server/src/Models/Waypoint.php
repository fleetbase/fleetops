<?php

namespace Fleetbase\FleetOps\Models;

use Barryvdh\DomPDF\Facade\Pdf;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\FleetOps\Traits\HasTrackingNumber;
use Fleetbase\FleetOps\Traits\PayloadAccessors;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Waypoint extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasTrackingNumber;
    use PayloadAccessors;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'waypoints';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'waypoint';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['_key', '_import_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'customer_uuid', 'customer_type', 'type', 'order'];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['status', 'status_code', 'tracking'];

    /**
     * Relationships to always append to model.
     *
     * @var array
     */
    protected $with = ['place'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'customer_type'    => PolymorphicType::class,
    ];

    /**
     * The pdf source stream for label.
     */
    public function pdfLabel()
    {
        return Pdf::loadHTML($this->label());
    }

    /**
     * The pdf source stream for label.
     */
    public function pdfLabelStream()
    {
        return $this->pdfLabel()->stream();
    }

    /**
     * @return HasMany
     */
    public function entities()
    {
        return $this->hasMany(Entity::class, 'destination_uuid', 'place_uuid')->where('payload_uuid', $this->payload_uuid);
    }

    /**
     * The html for the shipment label.
     */
    public function label()
    {
        $this->load(['trackingNumber', 'company', 'place', 'entities']);

        return view('fleetops::labels/waypoint-label', [
            'waypoint'          => $this,
            'dropoff'           => $this->place,
            'entities'          => $this->entities()->get(),
            'trackingNumber'    => $this->trackingNumber,
            'company'           => $this->company,
        ])->render();
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function trackingNumber(): BelongsTo
    {
        return $this->belongsTo(TrackingNumber::class);
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(Proof::class, 'subject_uuid');
    }

    public function payload(): BelongsTo
    {
        return $this->belongsTo(Payload::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class);
    }

    public function customer(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'customer_type', 'customer_uuid');
    }

    /**
     * The tracking number for waypoint.
     */
    public function getTrackingAttribute()
    {
        return data_get($this, 'trackingNumber.tracking_number');
    }

    /**
     * The latest tracking status for waypoint.
     */
    public function getStatusAttribute()
    {
        return data_get($this, 'trackingNumber.last_status');
    }

    /**
     * The latest tracking status code for waypoint.
     */
    public function getStatusCodeAttribute()
    {
        return data_get($this, 'trackingNumber.last_status_code');
    }

    /**
     * The latest tracking status code for waypoint.
     */
    public function getCompleteAttribute()
    {
        return data_get($this, 'trackingNumber.last_status_complete');
    }

    public static function insertGetUuid($values = [], ?Payload $payload = null)
    {
        $instance   = new static();
        $fillable   = $instance->getFillable();
        $insertKeys = array_keys($values);
        // clean insert data
        foreach ($insertKeys as $key) {
            if (!in_array($key, $fillable)) {
                unset($values[$key]);
            }
        }

        $values['uuid']         = $uuid = static::generateUuid();
        $values['public_id']    = static::generatePublicId('waypoint');
        $values['_key']         = session('api_key') ?? 'console';
        $values['created_at']   = Carbon::now()->toDateTimeString();
        $values['company_uuid'] = session('company');

        if ($payload) {
            $values['payload_uuid'] = $payload->uuid;
        }

        if (isset($values['meta']) && (is_object($values['meta']) || is_array($values['meta']))) {
            $values['meta'] = json_encode($values['meta']);
        }

        $result = static::insert($values);
        if ($result && $payload) {
            // create tracking number for entity
            $trackingNumberId = TrackingNumber::insertGetUuid([
                'owner_uuid' => $uuid,
                'owner_type' => Utils::getModelClassName('waypoint'),
                'region'     => $payload->getPickupRegion(),
                'location'   => Utils::parsePointToWkt($payload->getPickupLocation()),
            ]);

            // set tracking number
            static::where('uuid', $uuid)->update(['tracking_number_uuid' => $trackingNumberId]);
        }

        return $result ? $uuid : false;
    }

    /**
     * Get this waypoint place record.
     */
    public function getPlace(): ?Place
    {
        $this->loadMissing('place');

        return $this->place ?? Place::where('uuid', $this->place_uuid)->first();
    }

    /**
     * Find the first waypoint for a given place and order/payload.
     *
     * This method attempts to locate a waypoint belonging to the provided order's payload
     * that is associated with the given place. It supports lookup by:
     * - `Place` model instance (direct UUID match),
     * - UUID string (direct lookup on `place_uuid`),
     * - or public identifier string (fallback through `whereHas('place')`).
     *
     * @param \Fleetbase\Models\Place|string                    $place   the place instance or identifier (UUID or public_id)
     * @param \Fleetbase\Models\Order|\Fleetbase\Models\Payload $order   the order or payload model to scope the search
     * @param array                                             $with    optional relationships to eager load
     * @param array                                             $columns columns to select from the waypoints table
     *
     * @return static|null the matching waypoint, or null if none is found
     *
     * @throws \InvalidArgumentException if the provided order or payload is missing a payload UUID
     */
    public static function findByPlace(
        Place|string $place,
        Order|Payload $order,
        array $with = [],
        array $columns = ['*'],
    ): ?self {
        $payloadId = match (true) {
            $order instanceof Order   => $order->payload_uuid,
            $order instanceof Payload => $order->uuid,
            default                   => null,
        };

        if (!$payloadId) {
            throw new \InvalidArgumentException('Missing payload UUID for lookup.');
        }

        if ($place instanceof Place) {
            return static::with($with)
                ->select($columns)
                ->where('payload_uuid', $payloadId)
                ->where('place_uuid', $place->uuid)
                ->first();
        }

        if (Str::isUuid($place)) {
            return static::with($with)
                ->select($columns)
                ->where('payload_uuid', $payloadId)
                ->where('place_uuid', $place)
                ->first();
        }

        return static::with($with)
            ->select($columns)
            ->where('payload_uuid', $payloadId)
            ->whereHas('place', fn ($q) => $q->where('public_id', $place))
            ->first();
    }
}
