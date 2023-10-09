<?php

namespace Fleetbase\FleetOps\Models;

use Barryvdh\DomPDF\Facade\Pdf;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\FleetOps\Traits\HasTrackingNumber;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Support\Carbon;

class Waypoint extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasTrackingNumber;

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
    protected $fillable = ['_key', 'company_uuid', 'place_uuid', 'tracking_number_uuid', '_import_id', 'payload_uuid', 'type', 'order'];

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
     * The html for the shipment label.
     */
    public function label()
    {
        $this->load(['trackingNumber', 'company', 'place']);

        return view('labels/default', [
            'order'          => $this,
            'dropoff'        => $this->place,
            'trackingNumber' => $this->trackingNumber,
            'company'        => $this->company,
        ])->render();
    }

    /**
     * Place assosciated to order.
     *
     * @var Model
     */
    public function place()
    {
        return $this->belongsTo(Place::class);
    }

    /**
     * Tracking Number assosciated to waypoint.
     *
     * @var Model
     */
    public function trackingNumber()
    {
        return $this->belongsTo(TrackingNumber::class);
    }

    /**
     * The proofs for this order.
     */
    public function proofs()
    {
        return $this->hasMany(Proof::class, 'subject_uuid');
    }

    /**
     * Payload assosciated to waypoint.
     *
     * @var Model
     */
    public function payload()
    {
        return $this->belongsTo(Payload::class);
    }

    /**
     * The company who manages this waypoint.
     *
     * @var Model
     */
    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class);
    }

    /**
     * Waypoint has completed instance.
     *
     * @var Model
     */
    public function completed()
    {
        return $this->hasOne(WaypointCompleted::class, 'place_uuid', 'place_uuid')->where('payload_uuid', $this->payload_uuid);
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

    public static function insertGetUuid($values = [], Payload $payload = null)
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
}
