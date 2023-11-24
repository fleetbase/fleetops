<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Casts\Point;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\TracksApiCredential;
use Grimzy\LaravelMysqlSpatial\Eloquent\SpatialTrait;
use Illuminate\Support\Str;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Vehicle extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use SpatialTrait;
    use Searchable;
    use HasSlug;
    use LogsActivity;
    use HasMetaAttributes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'vehicles';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'vehicle';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['make', 'model', 'year', 'plate_number', 'internal_id', 'vin', 'public_id'];

    /**
     * Attributes that is filterable on this model.
     *
     * @var array
     */
    protected $filterParams = ['vendor', 'driver', 'driver_uuid', 'vehicle_make', 'vehicle_model'];

    /**
     * Relationships to auto load with driver.
     *
     * @var array
     */
    protected $with = [];

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
    protected static $logName = 'vehicle';

    /**
     * Get the options for generating the slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(['year', 'make', 'model', 'trim', 'plate_number', 'internal_id'])
            ->saveSlugsTo('slug');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'vendor_uuid',
        'photo_uuid',
        'avatar_url',
        'make',
        'location',
        'speed',
        'heading',
        'altitude',
        'model',
        'year',
        'trim',
        'type',
        'plate_number',
        'vin',
        'vin_data',
        'meta',
        'telematics',
        'status',
        'online',
        'slug',
    ];

    /**
     * Set attributes and defaults.
     *
     * @var array
     */
    protected $attributes = [
        'avatar_url' => 'https://flb-assets.s3-ap-southeast-1.amazonaws.com/static/vehicle-icons/mini_bus.svg',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['display_name', 'photo_url', 'driver_name', 'vendor_name'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'driver',
        'vendor',
    ];

    /**
     * The attributes that are spatial columns.
     *
     * @var array
     */
    protected $spatialFields = ['location'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'location'   => Point::class,
        'meta'       => Json::class,
        'telematics' => Json::class,
        'model_data' => Json::class,
        'vin_data'   => Json::class,
        'online'     => 'boolean',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function photo()
    {
        return $this->belongsTo(\Fleetbase\Models\File::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function driver()
    {
        return $this->hasOne(Driver::class)->without(['vehicle']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function fleets()
    {
        return $this->hasManyThrough(Fleet::class, FleetVehicle::class, 'vehicle_uuid', 'uuid', 'uuid', 'fleet_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function devices()
    {
        return $this->hasMany(VehicleDevice::class);
    }

    /**
     * Get avatar URL attribute.
     *
     * @return string
     */
    public function getPhotoUrlAttribute()
    {
        return data_get($this, 'photo.url', 'https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/vehicle-placeholder.png');
    }

    /**
     * The name generated from make model and year.
     *
     * @return string
     */
    public function getDisplayNameAttribute()
    {
        // Initialize an empty array to hold the name segments
        $nameSegments = [];

        // Populate the nameSegments array with the values of the attributes
        $keys = ['year', 'make', 'model', 'trim', 'plate_number', 'internal_id'];
        foreach ($keys as $key) {
            if (!empty($this->{$key})) {
                $nameSegments[] = $this->{$key};
            }
        }

        // Join the name segments into a single string, separated by spaces
        $displayName = implode(' ', $nameSegments);

        // Trim any leading or trailing whitespace
        $displayName = trim($displayName);

        return $displayName;
    }

    /**
     * Get avatar url.
     *
     * @return string|null
     */
    public function getAvatarUrlAttribute($value)
    {
        if (!$value) {
            return static::getAvatar();
        }

        return $value;
    }

    /**
     * Get the driver's name assigned to vehicle.
     *
     * @return string|null
     */
    public function getDriverNameAttribute()
    {
        return data_get($this, 'driver.name');
    }

    /**
     * Get the driver's public id assigned to vehicle.
     *
     * @return string|null
     */
    public function getDriverIdAttribute()
    {
        return data_get($this, 'driver.public_id');
    }

    /**
     * Get the driver's uuid assigned to vehicle.
     *
     * @return string|null
     */
    public function getDriverUuidAttribute()
    {
        return data_get($this, 'driver.uuid');
    }

    /**
     * Get drivers vendor ID.
     *
     * @return string|null
     */
    public function getVendorIdAttribute()
    {
        return data_get($this, 'vendor.public_id');
    }

    /**
     * Get drivers vendor name.
     *
     * @return string|null
     */
    public function getVendorNameAttribute()
    {
        return data_get($this, 'vendor.name');
    }

    /**
     * Get the vehicles model data attributes.
     */
    public function getModelDataAttribute()
    {
        $attributes      = $this->getFillable();
        $modelAttributes = [];
        foreach ($attributes as $attr) {
            if (Str::startsWith($attr, 'model_')) {
                $modelAttributes[str_replace('model_', '', $attr)] = $this->{$attr};
            }
        }

        return $modelAttributes;
    }

    /**
     * Get an avatar url by key.
     *
     * @param string $key
     *
     * @return string
     */
    public static function getAvatar($key = 'mini_bus')
    {
        return static::getAvatarOptions()->get($key);
    }

    /**
     * Get all avatar options for a vehicle.
     *
     * @return \Illuminate\Support\Collection
     */
    public static function getAvatarOptions()
    {
        $options = [
            '2_door_truck.svg',
            '3_door_hatchback.svg',
            '4_door_truck.svg',
            '5_door_hatchback.svg',
            'ambulance.svg',
            'convertible.svg',
            'coupe.svg',
            'electric_car.svg',
            'fastback.svg',
            'full_size_suv.svg',
            'hot_hatch.svg',
            'large_ambulance.svg',
            'light_commercial_truck.svg',
            'light_commercial_van.svg',
            'limousine.svg',
            'mid_size_suv.svg',
            'mini_bus.svg',
            'mini_van.svg',
            'muscle_car.svg',
            'police_1.svg',
            'police_2.svg',
            'roadster.svg',
            'sedan.svg',
            'small_3_door_hatchback.svg',
            'small_5_door_hatchback.svg',
            'sportscar.svg',
            'station_wagon.svg',
            'taxi.svg',
        ];

        return collect($options)->mapWithKeys(
            function ($option) {
                $key = str_replace('.svg', '', $option);

                return [$key => Utils::assetFromS3('static/vehicle-icons/' . $option)];
            }
        );
    }

    /**
     * Assign a driver to this vehicle.
     *
     * @return void
     */
    public function assignDriver(Driver $driver)
    {
        $driver->assignVehicle($this);

        return $this;
    }

    /**
     * Updates the position of the vehicle, creating a new Position record if
     * the driver has moved more than 100 meters or if it's their first recorded position.
     *
     * @param \Fleetbase\FleetOps\Models\Order|null $order The order to consider when updating the position (default: null)
     *
     * @return \Fleetbase\FleetOps\Models\Position|null The created Position object, or null if no new position was created
     */
    public function updatePositionWithOrderContext(Order $order = null): ?Position
    {
        $position     = null;
        $lastPosition = $this->positions()->whereCompanyUuid(session('company'))->latest()->first();

        // get driver if applicable
        $driver = $this->load('driver')->driver;

        // get the vehicle's driver's current order
        $currentOrder = $order;

        if (!$currentOrder && $driver) {
            $currentOrder = $driver->currentOrder()->with(['payload'])->first();
        }

        $destination = $currentOrder ? $currentOrder->payload->getPickupOrCurrentWaypoint() : null;

        $positionData = [
            'company_uuid' => session('company', $this->company_uuid),
            'subject_uuid' => $this->uuid,
            'subject_type' => Utils::getMutationType($this),
            'coordinates'  => $this->location,
        ];

        if ($currentOrder) {
            $positionData['order_uuid'] = $currentOrder->uuid;
        }

        if ($destination) {
            $positionData['destination_uuid'] = $destination->uuid;
        }

        $isFirstPosition = !$lastPosition;
        $isPast50Meters  = $lastPosition && Utils::vincentyGreatCircleDistance($this->location, $lastPosition->coordinates) > 50;
        $position        = null;

        // create the first position
        if ($isFirstPosition || $isPast50Meters) {
            $position = Position::create($positionData);
        }

        return $position;
    }
}
