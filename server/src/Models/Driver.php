<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Casts\Point;
use Fleetbase\FleetOps\Scopes\DriverScope;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\FleetOps\Support\Utils as FleetOpsUtils;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasInternalId;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\SendsWebhooks;
use Fleetbase\Traits\TracksApiCredential;
use Grimzy\LaravelMysqlSpatial\Eloquent\SpatialTrait;
use Illuminate\Broadcasting\Channel;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Traits\CausesActivity;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Driver extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasInternalId;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use Notifiable;
    use SendsWebhooks;
    use SpatialTrait;
    use HasSlug;
    use LogsActivity;
    use CausesActivity;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'drivers';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'driver';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['drivers_license_number', 'user.name', 'user.email', 'user.phone'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        '_key',
        'public_id',
        'internal_id',
        'user_uuid',
        'company_uuid',
        'vehicle_uuid',
        'vendor_uuid',
        'current_job_uuid',
        'auth_token',
        'signup_token_used',
        'drivers_license_number',
        'location',
        'heading',
        'bearing',
        'altitude',
        'speed',
        'country',
        'currency',
        'city',
        'online',
        'slug',
        'status',
    ];

    /**
     * The attributes that are guarded and not mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

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
        'location' => Point::class,
        'online'   => 'boolean',
    ];

    /**
     * Relationships to auto load with driver.
     *
     * @var array
     */
    protected $with = [];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [
        'current_job_id',
        'vehicle_id',
        'vendor_id',
        'photo_url',
        'name',
        'phone',
        'email',
        'rotation',
        'vehicle_name',
        'vehicle_avatar',
        'vendor_name',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['currentJob', 'vendor', 'vehicle', 'user', 'latitude', 'longitude', 'auth_token'];

    /**
     * Attributes that is filterable on this model.
     *
     * @var array
     */
    protected $filterParams = ['vendor', 'facilitator', 'customer', 'fleet', 'photo_uuid', 'avatar_uuid'];

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
    protected static $logName = 'driver';

    /**
     * Get the options for generating the slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('drivers_license_number')
            ->saveSlugsTo('slug');
    }

    /**
     * The "booting" method of the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope(new DriverScope());
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(\Fleetbase\Models\User::class)->select(['uuid', 'avatar_uuid', 'name', 'phone', 'email'])->without(['driver'])->withTrashed();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class)->select([
            'uuid',
            'public_id',
            'year',
            'make',
            'model',
            'model_data',
            'vin_data',
            'telematics',
            'meta',
            'trim',
            'plate_number',
            DB::raw("CONCAT(vehicles.year, ' ', vehicles.make, ' ', vehicles.model, ' ', vehicles.trim, ' ', vehicles.plate_number) AS display_name"),
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class)->select(['id', 'uuid', 'public_id', 'name']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function currentJob()
    {
        return $this->belongsTo(Order::class)->select(['id', 'uuid', 'public_id', 'payload_uuid', 'driver_assigned_uuid'])->without(['driver']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function currentOrder()
    {
        return $this->belongsTo(Order::class, 'current_job_uuid')->select(['id', 'uuid', 'public_id', 'payload_uuid', 'driver_assigned_uuid'])->without(['driver']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function jobs()
    {
        return $this->hasMany(Order::class, 'driver_assigned_uuid')->without(['driver']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function orders()
    {
        return $this->hasMany(Order::class, 'driver_assigned_uuid')->without(['driver']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function pings()
    {
        return $this->hasMany(Ping::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function positions()
    {
        return $this->hasMany(Position::class, 'subject_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function fleets()
    {
        return $this->hasManyThrough(Fleet::class, FleetDriver::class, 'driver_uuid', 'uuid', 'uuid', 'fleet_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function devices()
    {
        return $this->hasMany(\Fleetbase\Models\UserDevice::class, 'user_uuid', 'user_uuid');
    }

    /**
     * Specifies the user's FCM tokens.
     *
     * @return array
     */
    public function routeNotificationForFcm()
    {
        return $this->devices
            ->where('platform', 'android')->map(
                function ($userDevice) {
                    return $userDevice->token;
                }
            )
            ->toArray();
    }

    /**
     * Specifies the user's APNS tokens.
     *
     * @return array
     */
    public function routeNotificationForApn()
    {
        return $this->devices
            ->where('platform', 'ios')->map(
                function ($userDevice) {
                    return $userDevice->token;
                }
            )->toArray();
    }

    /**
     * The channels the driver receives notification broadcasts on.
     *
     * @param \Illuminate\Notifications\Notification $notification
     *
     * @return array
     */
    public function receivesBroadcastNotificationsOn($notification)
    {
        return new Channel('driver.' . $this->public_id);
    }

    /**
     * Get the drivers rotation.
     */
    public function getRotationAttribute()
    {
        return round($this->heading / 360 + 180);
    }

    /**
     * Get assigned vehicle assigned name.
     */
    public function getCurrentJobIdAttribute()
    {
        return data_get($this, 'currentJob.public_id');
    }

    /**
     * Get assigned vehicle assigned name.
     */
    public function getVehicleNameAttribute()
    {
        return data_get($this, 'vehicle.display_name');
    }

    /**
     * Get assigned vehicles public ID.
     */
    public function getVehicleIdAttribute()
    {
        return data_get($this, 'vehicle.public_id');
    }

    /**
     * Get assigned vehicles public ID.
     */
    public function getVehicleAvatarAttribute()
    {
        if ($this->isVehicleNotAssigned()) {
            return Vehicle::getAvatar();
        }

        return data_get($this, 'vehicle.avatar_url');
    }

    /**
     * Get drivers vendor ID.
     */
    public function getVendorIdAttribute()
    {
        return data_get($this, 'vendor.public_id');
    }

    /**
     * Get drivers vendor name.
     */
    public function getVendorNameAttribute()
    {
        return data_get($this, 'vendor.name');
    }

    /**
     * Get drivers photo URL attribute.
     */
    public function getPhotoAttribute()
    {
        return data_get($this, 'user.avatar');
    }

    /**
     * Get drivers photo URL attribute.
     */
    public function getPhotoUrlAttribute()
    {
        return data_get($this, 'user.avatarUrl');
    }

    /**
     * Get drivers name.
     */
    public function getNameAttribute()
    {
        return data_get($this, 'user.name');
    }

    /**
     * Get drivers phone number.
     */
    public function getPhoneAttribute()
    {
        return data_get($this, 'user.phone');
    }

    /**
     * Get drivers email.
     */
    public function getEmailAttribute()
    {
        return data_get($this, 'user.email');
    }

    /**
     * Unassigns the current order from the driver if a driver is assigned.
     *
     * @return bool True if the driver was unassigned and the changes were saved, false otherwise
     */
    public function unassignCurrentOrder()
    {
        if (!empty($this->driver_assigned_uuid)) {
            $this->driver_assigned_uuid = null;

            return $this->save();
        }

        return false;
    }

    /**
     * Assign a vehicle to driver.
     *
     * @return void
     */
    public function assignVehicle(Vehicle $vehicle)
    {
        // auto: unassign vehicle from other drivers
        static::where('vehicle_uuid', $vehicle->uuid)->update(['vehicle_uuid' => null]);

        // set this vehicle
        $this->vehicle_uuid = $vehicle->uuid;
        $this->setRelation('vehicle', $vehicle);
        $this->save();

        return $this;
    }

    /**
     * Checks if the vehicle is assigned to the driver.
     *
     * @return bool True if the vehicle is assigned, false otherwise
     */
    public function isVehicleAssigned()
    {
        return $this->isVehicleNotAssigned() === false;
    }

    /**
     * Checks if the vehicle is not assigned to the driver.
     *
     * @return bool True if the vehicle is not assigned, false otherwise
     */
    public function isVehicleNotAssigned()
    {
        return !$this->vehicle_uuid;
    }

    /**
     * Updates the position of the driver, creating a new Position record if
     * the driver has moved more than 100 meters or if it's their first recorded position.
     *
     * @param Order|null $order The order to consider when updating the position (default: null)
     *
     * @return \Fleetbase\FleetOps\Models\Position|null The created Position object, or null if no new position was created
     */
    public function updatePosition(Order $order = null): ?Position
    {
        $position     = null;
        $lastPosition = $this->positions()->whereCompanyUuid(session('company'))->latest()->first();

        // get the drivers current order
        $currentOrder = $order ?? $this->currentOrder()->with(['payload'])->first();
        $destination  = $currentOrder ? $currentOrder->payload->getPickupOrCurrentWaypoint() : null;

        $positionData = [
            'company_uuid' => session('company', $this->company_uuid),
            'subject_uuid' => $this->uuid,
            'subject_type' => Utils::getMutationType($this),
            'coordinates'  => $this->location,
            'altitude'     => $this->altitude,
            'heading'      => $this->heading,
            'speed'        => $this->speed,
        ];

        if ($currentOrder) {
            $positionData['order_uuid'] = $currentOrder->uuid;
        }

        if ($destination) {
            $positionData['destination_uuid'] = $destination->uuid;
        }

        $isFirstPosition = !$lastPosition;
        $isPast50Meters  = $lastPosition && FleetOpsUtils::vincentyGreatCircleDistance($this->location, $lastPosition->coordinates) > 50;
        $position        = null;

        // create the first position
        if ($isFirstPosition || $isPast50Meters) {
            $position = Position::create($positionData);
        }

        return $position;
    }

    /**
     * Get the user relationship from the driver.
     */
    public function getUser(): ?\Fleetbase\Models\User
    {
        // get user
        $user = $this->load(['user'])->user;

        if (empty($user) && Str::isUuid($this->user_uuid)) {
            $user = \Fleetbase\Models\User::where('uuid', $this->user_uuid)->first();
        }

        return $user;
    }
}
