<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\Money;
use Fleetbase\FleetOps\Casts\Point;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\FleetOps\Support\VehicleData;
use Fleetbase\LaravelMysqlSpatial\Eloquent\SpatialTrait;
use Fleetbase\Models\Category;
use Fleetbase\Models\File;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasCustomFields;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
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
    use HasCustomFields;

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
    protected $searchableColumns = ['make', 'model', 'year', 'plate_number', 'vin', 'public_id'];

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
     * Get the activity log options for the model.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['*'])->logOnlyDirty();
    }

    /**
     * Get the options for generating the slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(['year', 'make', 'model', 'trim', 'plate_number'])
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
        'category_uuid',
        'warranty_uuid',
        'photo_uuid',
        'avatar_url',
        'name',
        'description',
        'make',
        'model',
        'model_type',
        'year',
        'color',
        'location',
        'speed',
        'heading',
        'altitude',
        'odometer',
        'odometer_unit',
        'fuel_type',
        'fuel_volume_unit',
        'trim',
        'transmission',
        'measurement_system',
        'body_type',
        'body_sub_type',
        'usage_type',
        'ownership_type',
        'type',
        'class',
        'plate_number',
        'call_sign',
        'serial_number',
        'financing_status',
        'currency',
        'insurance_value',
        'depreciation_rate',
        'current_value',
        'acquisition_cost',
        'vin',
        'vin_data',
        'specs',
        'notes',
        'meta',
        'telematics',
        'status',
        'online',
        'slug',
        'purchased_at',
        'lease_expires_at',
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
        'current_value'        => Money::class,
        'insurance_value'      => Money::class,
        'acquisition_cost'     => Money::class,
        'location'             => Point::class,
        'meta'                 => Json::class,
        'telematics'           => Json::class,
        'specs'                => Json::class,
        'vin_data'             => Json::class,
        'online'               => 'boolean',
        'purchased_at'         => 'datetime',
        'lease_expires_at'     => 'datetime',
    ];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class, 'vehicle_uuid', 'uuid')->without(['vehicle']);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_uuid', 'uuid');
    }

    public function telematic(): BelongsTo
    {
        return $this->belongsTo(Telematic::class, 'telematic_uuid', 'uuid');
    }

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class, 'warranty_uuid', 'uuid');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function fleets(): HasManyThrough
    {
        return $this->hasManyThrough(Fleet::class, FleetVehicle::class, 'vehicle_uuid', 'uuid', 'uuid', 'fleet_uuid');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'attachable_uuid');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class, 'subject_uuid');
    }

    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class, 'equipable_uuid', 'uuid')->where('equipable_type', static::class);
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(Maintenance::class, 'maintainable_uuid', 'uuid')->where('maintainable_type', static::class);
    }

    public function sensors(): HasMany
    {
        return $this->hasMany(Sensor::class, 'sensorable_uuid', 'uuid')->where('sensorable_type', static::class);
    }

    public function parts(): MorphMany
    {
        return $this->morphMany(Part::class, 'asset');
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
        $keys = ['year', 'make', 'model', 'trim'];
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
     * Get avatar url.
     *
     * @return string|null
     */
    public function getAvatarUrlAttribute($value)
    {
        if (!$value) {
            return static::getAvatar();
        }

        if (Str::isUuid($value)) {
            return static::getAvatar($value);
        }

        return $value;
    }

    /**
     * Get an avatar url by key.
     *
     * @param string $key
     */
    public static function getAvatar($key = 'mini_bus'): ?string
    {
        if (Str::isUuid($key)) {
            $file = File::where('uuid', $key)->first();
            if ($file) {
                return $file->url;
            }

            return null;
        }

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

        // Get custom avatars
        $customAvatars = collect(File::where('type', 'vehicle-avatar')->get()->mapWithKeys(
            function ($file) {
                $key = str_replace(['.svg', '.png'], '', 'Custom: ' . $file->original_filename);

                return [$key => $file->uuid];
            }
        )->toArray());

        // Create default avatars included from fleetbase
        $avatars = collect($options)->mapWithKeys(
            function ($option) {
                $key = str_replace(['.svg', '.png'], '', $option);

                return [$key => Utils::assetFromS3('static/vehicle-icons/' . $option)];
            }
        );

        return $customAvatars->merge($avatars);
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
     * Retrieves the last known position of the driver within the current company context.
     *
     * @return ?Position the last recorded position or null if none exists
     */
    public function getLastKnownPosition(): ?Position
    {
        return $this->positions()
            ->where('company_uuid', session('company', $this->company_uuid))
            ->latest()
            ->first();
    }

    /**
     * Updates the position of the vehicle, creating a new Position record if
     * the driver has moved more than 100 meters or if it's their first recorded position.
     *
     * @param Order|null $order The order to consider when updating the position (default: null)
     *
     * @return Position|null The created Position object, or null if no new position was created
     */
    public function createPositionWithOrderContext(?Order $order = null): ?Position
    {
        $lastPosition = $this->getLastKnownPosition();
        $positionData = [
            'company_uuid' => session('company', $this->company_uuid),
            'subject_uuid' => $this->uuid,
            'subject_type' => get_class($this),
            'coordinates'  => $this->location,
        ];

        $this->loadMissing('driver');

        $currentOrder = $order instanceof Order ? $order : $this->driver->getCurrentOrder();
        if ($currentOrder) {
            $positionData['order_uuid'] = $currentOrder->uuid;
        }

        $destination = $currentOrder ? $currentOrder->payload->getPickupOrCurrentWaypoint() : null;
        if ($destination) {
            $positionData['destination_uuid'] = $destination->uuid;
        }

        $isFirstPosition = is_null($lastPosition);
        $isPast50Meters  = $lastPosition && Utils::vincentyGreatCircleDistance($this->location, $lastPosition->coordinates) > 50;

        // Create a position if it's the first one or the vehicle has moved significantly
        return ($isFirstPosition || $isPast50Meters) ? Position::create($positionData) : null;
    }

    public static function createFromImport(array $row, bool $saveInstance = false): Vehicle
    {
        // Filter array for null key values
        $row = array_filter($row);

        // Get vehicle columns
        $vehicleName      = Utils::or($row, ['vehicle', 'vehicle_name', 'name']);
        $make             = Utils::or($row, ['make', 'vehicle_make', 'manufacturer', 'brand']);
        $model            = Utils::or($row, ['model', 'vehicle_model', 'brand_model']);
        $year             = Utils::or($row, ['year', 'vehicle_year', 'build_year', 'release_year']);
        $trim             = Utils::or($row, ['trim', 'vehicle_trim', 'brand_trim']);
        $type             = Utils::or($row, ['type', 'vehicle_type'], 'vehicle');
        $plateNumber      = Utils::or($row, ['plate_number', 'license_plate', 'license_place_number', 'vehicle_plate', 'registration_plate', 'tag_number', 'tail_number', 'head_number']);
        $vin              = Utils::or($row, ['vin', 'vin_number', 'vin_id', 'vehicle_identification_number', 'serial_number']);
        $driverAssigned   = Utils::or($row, ['driver', 'driver_name', 'driver_assigned', 'driver_assignee']);

        // Handle when only a vehicle name is provided
        if ($vehicleName && empty($make) && empty($model)) {
            // extract make and model from vehicle name
            $parsedVehicle = VehicleData::parse($vehicleName);

            if (!empty($parsedVehicle['make'])) {
                $make = $parsedVehicle['make'];
            }

            if (!empty($parsedVehicle['model'])) {
                $model = $parsedVehicle['model'];
            }

            if (!empty($parsedVehicle['year'])) {
                $year = $parsedVehicle['year'];
            }

            // if unable to extract set name to make
            if (!$make) {
                $make = $vehicleName;
            }
        }

        // Attempt to resolve driver if driver name provided
        $driver = null;
        if ($driverAssigned) {
            $driver = Driver::findByIdentifier($driverAssigned);
        }

        // Create vehicle
        $vehicle = new static([
            'company_uuid'           => session('company'),
            'make'                   => $make,
            'model'                  => $model,
            'year'                   => $year,
            'trim'                   => $trim,
            'plate_number'           => $plateNumber,
            'vin'                    => $vin,
            'type'                   => $type,
            'status'                 => 'active',
            'online'                 => 0,
            'status'                 => 'active',
        ]);

        // If driver was resolved assign driver to vehicle
        if ($driver) {
            $vehicle->save();
            $driver->assignVehicle($vehicle);
        }

        if ($saveInstance === true) {
            $vehicle->save();
        }

        return $vehicle;
    }

    public static function findByName(?string $vehicleName = null): ?Vehicle
    {
        if (is_null($vehicleName)) {
            return null;
        }

        return static::where(function ($query) use ($vehicleName) {
            $query->where('public_id', $vehicleName)
                    ->orWhere('plate_number', $vehicleName)
                    ->orWhere('vin', $vehicleName)
                    ->orWhere('serial_number', $vehicleName)
                    ->orWhere('call_sign', $vehicleName)
                    ->orWhereRaw("CONCAT(make, ' ', model, ' ', year) LIKE ?", ["%{$vehicleName}%"])
                    ->orWhereRaw("CONCAT(year, ' ', make, ' ', model) LIKE ?", ["%{$vehicleName}%"]);
        })->first();
    }
}
