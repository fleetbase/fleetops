<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\Money;
use Fleetbase\FleetOps\Casts\Point;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\FleetOps\Support\VehicleData;
use Fleetbase\LaravelMysqlSpatial\Eloquent\SpatialTrait;
use Fleetbase\LaravelMysqlSpatial\Types\Point as SpatialPoint;
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
use Illuminate\Support\Arr;
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
    protected $searchableColumns = ['name', 'description', 'make', 'model', 'trim', 'model_type', 'body_type', 'body_sub_type', 'year', 'plate_number', 'vin', 'call_sign', 'public_id'];

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
        // Odometer & measurement
        'odometer',
        'odometer_unit',
        'odometer_at_purchase',
        'measurement_system',
        'fuel_type',
        'fuel_volume_unit',
        // Body / usage
        'trim',
        'transmission',
        'body_type',
        'body_sub_type',
        'usage_type',
        'ownership_type',
        'type',
        'class',
        // Identifiers
        'plate_number',
        'call_sign',
        'serial_number',
        'vin',
        'vin_data',
        // Financing
        'financing_status',
        'loan_number_of_payments',
        'loan_first_payment',
        'loan_amount',
        // Lifecycle
        'estimated_service_life_distance_unit',
        'estimated_service_life_distance',
        'estimated_service_life_months',
        // Capacity / dimensions
        'cargo_volume',
        'passenger_volume',
        'interior_volume',
        'weight',
        'width',
        'length',
        'height',
        'towing_capacity',
        'payload_capacity',
        'seating_capacity',
        'ground_clearance',
        'bed_length',
        'fuel_capacity',
        // Regulatory / compliance
        'emission_standard',
        'dpf_equipped',
        'scr_equipped',
        'gvwr',
        'gcwr',
        // Engine specifications
        'engine_number',
        'engine_model',
        'engine_make',
        'engine_family',
        'engine_configuration',
        'engine_displacement',
        'engine_size',
        'horsepower',
        'horsepower_rpm',
        'torque',
        'torque_rpm',
        'number_of_cylinders',
        'cylinder_arrangement',
        // Financial values
        'currency',
        'insurance_value',
        'depreciation_rate',
        'current_value',
        'acquisition_cost',
        // Other fields
        'specs',
        'details',
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
        // Money values
        'current_value'    => Money::class,
        'insurance_value'  => Money::class,
        'acquisition_cost' => Money::class,
        // Spatial
        'location'         => Point::class,
        // JSON
        'meta'             => Json::class,
        'telematics'       => Json::class,
        'specs'            => Json::class,
        'details'          => Json::class,
        'vin_data'         => Json::class,
        // Dates
        'purchased_at'       => 'datetime',
        'lease_expires_at'   => 'datetime',
        'loan_first_payment' => 'date',
        // Booleans
        'online'       => 'boolean',
        'dpf_equipped' => 'boolean',
        'scr_equipped' => 'boolean',
        // Integers
        'odometer'                        => 'integer',
        'odometer_at_purchase'            => 'integer',
        'loan_number_of_payments'         => 'integer',
        'estimated_service_life_distance' => 'integer',
        'estimated_service_life_months'   => 'integer',
        'number_of_cylinders'             => 'integer',
        'horsepower_rpm'                  => 'integer',
        'torque_rpm'                      => 'integer',
        'seating_capacity'                => 'integer',
        // Decimals
        'loan_amount'         => 'decimal:2',
        'cargo_volume'        => 'decimal:2',
        'passenger_volume'    => 'decimal:2',
        'interior_volume'     => 'decimal:2',
        'weight'              => 'decimal:2',
        'width'               => 'decimal:2',
        'length'              => 'decimal:2',
        'height'              => 'decimal:2',
        'towing_capacity'     => 'decimal:2',
        'payload_capacity'    => 'decimal:2',
        'ground_clearance'    => 'decimal:2',
        'bed_length'          => 'decimal:2',
        'fuel_capacity'       => 'decimal:2',
        'engine_displacement' => 'decimal:2',
        'engine_size'         => 'decimal:2',
        'horsepower'          => 'decimal:2',
        'torque'              => 'decimal:2',
        'gvwr'                => 'decimal:2',
        'gcwr'                => 'decimal:2',
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

    /**
     * Creates a new position for the vehicle.
     */
    public function createPosition(array $attributes = [], Model|string|null $destination = null): ?Position
    {
        if (!isset($attributes['coordinates']) && isset($attributes['location'])) {
            $attributes['coordinates'] = $attributes['location'];
        }

        if (!isset($attributes['coordinates']) && isset($attributes['latitude']) && isset($attributes['longitude'])) {
            $attributes['coordinates'] = new SpatialPoint($attributes['latitude'], $attributes['longitude']);
        }

        // handle destination if set
        $destinationUuid = Str::isUuid($destination) ? $destination : data_get($destination, 'uuid');

        return Position::create([
            ...Arr::only($attributes, ['coordinates', 'heading', 'bearing', 'speed', 'altitude', 'order_uuid']),
            'subject_uuid'     => $this->uuid,
            'subject_type'     => $this->getMorphClass(),
            'company_uuid'     => $this->company_uuid,
            'destination_uuid' => $destinationUuid,
        ]);
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

    /**
     * Set or update a single key/value pair in the `details` JSON column.
     *
     * Uses Laravel's `data_set` helper to allow dot notation for nested keys.
     *
     * @param string|array $key   the key (or array path) to set within the details
     * @param mixed        $value the value to assign to the given key
     *
     * @return array the updated details array
     */
    public function setDetail(string|array $key, mixed $value): array
    {
        $details = is_array($this->details) ? $this->details : (array) $this->details;
        data_set($details, $key, $value);
        $this->details = $details;

        return $details;
    }

    /**
     * Merge multiple values into the `details` JSON column.
     *
     * By default this performs a shallow merge (overwrites duplicate keys).
     * Use `array_replace_recursive` if you need nested merges.
     *
     * @param array $newDetails key/value pairs to merge into details
     *
     * @return array the updated details array
     */
    public function setDetails(array $newDetails = []): array
    {
        $details       = is_array($this->details) ? $this->details : (array) $this->details;
        $details       = array_merge($details, $newDetails);
        $this->details = $details;

        return $details;
    }

    /**
     * Set or update a single key/value pair in the `specs` JSON column.
     *
     * Uses Laravel's `data_set` helper to allow dot notation for nested keys.
     *
     * @param string|array $key   the key (or array path) to set within the specs
     * @param mixed        $value the value to assign to the given key
     *
     * @return array the updated specs array
     */
    public function setSpec(string|array $key, mixed $value): array
    {
        $specs = is_array($this->specs) ? $this->specs : (array) $this->specs;
        data_set($specs, $key, $value);
        $this->specs = $specs;

        return $specs;
    }

    /**
     * Merge multiple values into the `specs` JSON column.
     *
     * By default this performs a shallow merge (overwrites duplicate keys).
     * Use `array_replace_recursive` if you need nested merges.
     *
     * @param array $newSpecs key/value pairs to merge into specs
     *
     * @return array the updated specs array
     */
    public function setSpecs(array $newSpecs = []): array
    {
        $specs       = is_array($this->specs) ? $this->specs : (array) $this->specs;
        $specs       = array_merge($specs, $newSpecs);
        $this->specs = $specs;

        return $specs;
    }

    /**
     * Set or update a single key/value pair in the `vin_data` JSON column.
     *
     * Uses Laravel's `data_set` helper to allow dot notation for nested keys.
     *
     * @param string|array $key   the key (or array path) to set within the VIN data
     * @param mixed        $value the value to assign to the given key
     *
     * @return array the updated vin_data array
     */
    public function setVinData(string|array $key, mixed $value): array
    {
        $vinData = is_array($this->vin_data) ? $this->vin_data : (array) $this->vin_data;
        data_set($vinData, $key, $value);
        $this->vin_data = $vinData;

        return $vinData;
    }

    /**
     * Merge multiple values into the `vin_data` JSON column.
     *
     * By default this performs a shallow merge (overwrites duplicate keys).
     * Use `array_replace_recursive` if you need nested merges.
     *
     * @param array $newVinData key/value pairs to merge into vin_data
     *
     * @return array the updated vin_data array
     */
    public function setVinDatas(array $newVinData = []): array
    {
        $vinData        = is_array($this->vin_data) ? $this->vin_data : (array) $this->vin_data;
        $vinData        = array_merge($vinData, $newVinData);
        $this->vin_data = $vinData;

        return $vinData;
    }
}
