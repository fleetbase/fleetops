<?php

namespace Fleetbase\FleetOps\Models;

use Barryvdh\DomPDF\Facade\Pdf;
use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\FleetOps\Traits\HasTrackingNumber;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasInternalId;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\SendsWebhooks;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Milon\Barcode\Facades\DNS2DFacade as DNS2D;
use PhpUnitsOfMeasure\PhysicalQuantity\Length;
use PhpUnitsOfMeasure\PhysicalQuantity\Mass;

class Entity extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasInternalId;
    use TracksApiCredential;
    use SendsWebhooks;
    use HasTrackingNumber;
    use HasApiModelBehavior;
    use HasMetaAttributes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'entities';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'entity';

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
    protected $fillable = [
        '_key',
        'public_id',
        'payload_uuid',
        'company_uuid',
        'driver_assigned_uuid',
        'customer_uuid',
        'customer_type',
        'tracking_number_uuid',
        'destination_uuid',
        'supplier_uuid',
        'photo_uuid',
        '_import_id',
        'internal_id',
        'name',
        'type',
        'description',
        'currency',
        'barcode',
        'qr_code',
        'weight',
        'weight_unit',
        'length',
        'width',
        'height',
        'dimensions_unit',
        'declared_value',
        'sku',
        'price',
        'sale_price',
        'meta',
        'slug',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['customer_is_vendor', 'customer_is_contact', 'tracking', 'status', 'photo_url'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['trackingNumber'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta'          => Json::class,
        'customer_type' => PolymorphicType::class,
    ];

    /**
     * Generates QR Code & Barcode on creation.
     *
     * @return void
     */
    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->qr_code = DNS2D::getBarcodePNG($model->uuid, 'QRCODE');
            $model->barcode = DNS2D::getBarcodePNG($model->uuid, 'PDF417');
        });
    }

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
        $this->load(['trackingNumber', 'company', 'destination']);

        return view('labels/default', [
            'dropoff'        => $this->destination,
            'trackingNumber' => $this->trackingNumber,
            'company'        => $this->company,
        ])->render();
    }

    /**
     * @var \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function photo()
    {
        return $this->belongsTo(\Fleetbase\Models\File::class);
    }

    /**
     * @var \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function files()
    {
        return $this->hasMany(\Fleetbase\Models\File::class);
    }

    /**
     * @var \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function proofs()
    {
        return $this->hasMany(Proof::class, 'subject_uuid');
    }

    /**
     * @var \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function destination()
    {
        return $this->belongsTo(Place::class);
    }

    /**
     * @var \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function payload()
    {
        return $this->belongsTo(Payload::class);
    }

    /**
     * @var \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function supplier()
    {
        return $this->belongsTo(Vendor::class, 'supplier_uuid');
    }

    /**
     * @var \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_assigned_uuid');
    }

    /**
     * @var \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class);
    }

    /**
     * @var \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function trackingNumber()
    {
        return $this->belongsTo(TrackingNumber::class);
    }

    /**
     * @var \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function customer()
    {
        return $this->morphTo(__FUNCTION__, 'customer_type', 'customer_uuid')->withoutGlobalScopes();
    }

    /**
     * Get avatar URL attribute.
     *
     * @return string
     */
    public function getPhotoUrlAttribute()
    {
        return data_get($this, 'photo.s3url', 'https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/parcels/medium.png');
    }

    /**
     * True of the customer is a vendor `customer_is_vendor`.
     *
     * @var bool
     */
    public function getCustomerIsVendorAttribute()
    {
        return Str::contains(strtolower($this->customer_type), 'vendor');
    }

    /**
     * True of the customer is a contact `customer_is_contact`.
     *
     * @var bool
     */
    public function getCustomerIsContactAttribute()
    {
        return Str::contains(strtolower($this->customer_type), 'contact');
    }

    /**
     * The length the entity belongs to.
     *
     * @var PhpUnitsOfMeasure\PhysicalQuantity\Length
     */
    public function getLengthUnitAttribute()
    {
        return new Length($this->length, $this->dimensions_unit);
    }

    /**
     * The width the entity belongs to.
     *
     * @var PhpUnitsOfMeasure\PhysicalQuantity\Length
     */
    public function getWidthUnitAttribute()
    {
        return new Length($this->width, $this->dimensions_unit);
    }

    /**
     * The height the entity belongs to.
     *
     * @var PhpUnitsOfMeasure\PhysicalQuantity\Length
     */
    public function getHeightUnitAttribute()
    {
        return new Length($this->height, $this->dimensions_unit);
    }

    /**
     * The weight the entity belongs to.
     *
     * @var PhpUnitsOfMeasure\PhysicalQuantity\Mass
     */
    public function getMassUnitAttribute()
    {
        return new Mass($this->weight, $this->weight_unit);
    }

    /**
     * Always convert price to integer.
     */
    public function setPriceAttribute($value)
    {
        $this->attributes['price'] = Utils::numbersOnly($value);
    }

    /**
     * Always convert sale price to integer.
     */
    public function setSalePriceAttribute($value)
    {
        $this->attributes['sale_price'] = Utils::numbersOnly($value);
    }

    /**
     * Always convert declared value to integer.
     */
    public function setDeclaredValueAttribute($value)
    {
        $this->attributes['declared_value'] = Utils::numbersOnly($value);
    }

    /**
     * The tracking number for the entity.
     */
    public function getTrackingAttribute()
    {
        return data_get($this, 'trackingNumber.tracking_number');
    }

    /**
     * The latest tracking status for entity.
     */
    public function getStatusAttribute()
    {
        return data_get($this, 'trackingNumber.last_status');
    }

    public static function insertGetUuid($values = [], Payload $payload = null)
    {
        if (is_array($values) && isset($values['uuid'])) {
            Entity::where('uuid', $values['uuid'])->update([
                'payload_uuid' => $payload->uuid,
            ]);

            return $values['uuid'];
        }

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
        $values['public_id']    = static::generatePublicId('entity');
        $values['internal_id']  = static::generateInternalId();
        $values['_key']         = session('api_key', 'console');
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
                'owner_type' => Utils::getModelClassName('entity'),
                'region'     => $payload->getPickupRegion(),
                'location'   => Utils::parsePointToWkt($payload->getPickupLocation()),
            ]);

            // set tracking number
            static::where('uuid', $uuid)->update(['tracking_number_uuid' => $trackingNumberId]);
        }

        return $result ? $uuid : false;
    }

    public function setCustomer($model)
    {
        $this->customer_uuid = $model->uuid;
        $this->customer_type = Utils::getMutationType($model);
    }
}
