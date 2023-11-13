<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasInternalId;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Vendor extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use HasInternalId;
    use TracksApiCredential;
    use Searchable;
    use HasSlug;
    use LogsActivity;
    use Notifiable;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'vendors';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'vendor';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        '_key',
        'internal_id',
        'company_uuid',
        'logo_uuid',
        'type_uuid',
        'connect_company_uuid',
        'business_id',
        'name',
        'email',
        'website_url',
        'meta',
        'callbacks',
        'phone',
        'place_uuid',
        'country',
        'status',
        'type',
        'slug',
    ];

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'email', 'business_id', 'connectCompany.name'];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['address', 'address_street', 'logo_url'];

    /**
     * Filterable params.
     *
     * @var array
     */
    protected $filterParams = ['customer_type', 'facilitator_type', 'photo_url'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'callbacks' => Json::class,
        'meta'      => Json::class,
    ];

    /**
     * Relationships to auto load with driver.
     *
     * @var array
     */
    protected $with = ['place'];

    /**
     * Properties which activity needs to be logged.
     *
     * @var array
     */
    protected static $logAttributes = ['name', 'email', 'website_url', 'phone', 'country', 'status', 'type', 'logo_uuid', 'company_uuid'];

    /**
     * Do not log empty changed.
     *
     * @var bool
     */
    protected static $submitEmptyLogs = false;

    /**
     * We only want to log changed attributes.
     *
     * @var bool
     */
    protected static $logOnlyDirty = true;

    /**
     * The name of the subject to log.
     *
     * @var string
     */
    protected static $logName = 'vendor';

    /**
     * Get the options for generating the slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['place'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function place()
    {
        return $this->belongsTo(Place::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function connectCompany()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class);
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
    public function logo()
    {
        return $this->belongsTo(\Fleetbase\Models\File::class);
    }

    /**
     * Get the vendor logo url.
     *
     * @return string
     */
    public function getLogoUrlAttribute()
    {
        return data_get($this, 'logo.url', 'https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/no-avatar.png');
    }

    /**
     * Returns the vendors place address.
     *
     * @return string
     */
    public function getAddressAttribute()
    {
        return data_get($this, 'place.address_html');
    }

    /**
     * Returns the vendors place address.
     *
     * @return string
     */
    public function getAddressStreetAttribute()
    {
        return data_get($this, 'place.street1');
    }

    /**
     * Notify vendor using this column.
     *
     * @return mixed|string
     */
    public function routeNotificationForTwilio()
    {
        return $this->phone;
    }

    /**
     * Set the vendor type or default to `vendor`.
     *
     * @return void
     */
    public function setTypeAttribute(?string $type)
    {
        $this->attributes['type'] = $type ?? 'vendor';
    }

    /**
     * Set the vendor default status.
     *
     * @return void
     */
    public function setStatusAttribute(?string $status = 'active')
    {
        $this->attributes['status'] = $status ?? 'active';
    }
}
